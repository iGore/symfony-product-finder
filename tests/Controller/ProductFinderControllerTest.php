<?php

namespace App\Tests\Controller;

use App\DTO\Response\ProductResponseDto;
use App\Service\EmbeddingGeneratorInterface;
use App\Service\ImageDescriptionServiceInterface;
use App\Service\PromptServiceInterface;
use App\Service\SearchServiceInterface;
use App\Service\VectorStoreInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid; // For potential future use if product IDs are UUIDs

class ProductFinderControllerTest extends WebTestCase
{
    private $client;
    private $mockImageDescriptionService;
    private $mockEmbeddingGenerator;
    private $mockVectorStoreService;
    private $mockSearchService;
    private $mockPromptService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();

        $this->mockImageDescriptionService = $this->createMock(ImageDescriptionServiceInterface::class);
        $this->mockEmbeddingGenerator = $this->createMock(EmbeddingGeneratorInterface::class);
        $this->mockVectorStoreService = $this->createMock(VectorStoreInterface::class);
        $this->mockSearchService = $this->createMock(SearchServiceInterface::class);
        $this->mockPromptService = $this->createMock(PromptServiceInterface::class);

        static::getContainer()->set(ImageDescriptionServiceInterface::class, $this->mockImageDescriptionService);
        static::getContainer()->set(EmbeddingGeneratorInterface::class, $this->mockEmbeddingGenerator);
        static::getContainer()->set(VectorStoreInterface::class, $this->mockVectorStoreService);
        static::getContainer()->set(SearchServiceInterface::class, $this->mockSearchService);
        static::getContainer()->set(PromptServiceInterface::class, $this->mockPromptService);
    }

    private function createTestUploadedFile(string $filename, string $content = 'test image data', string $mimeType = 'image/png'): UploadedFile
    {
        $path = sys_get_temp_dir() . '/' . $filename;
        file_put_contents($path, $content);
        return new UploadedFile($path, $filename, $mimeType, null, true); // test mode = true
    }

    public function testChatWithImageSuccess(): void
    {
        $imageDescription = 'A shiny red apple.';
        $userQuery = 'healthy snack';
        $combinedQuery = $userQuery . "\n\nImage Content: " . $imageDescription;
        $embedding = [0.1, 0.2, 0.3];
        $vectorResults = [
            ['primary_key' => 'product1', 'title' => 'Red Apple', 'distance' => 0.1, 'payload' => ['description' => 'Fresh red apple']],
        ];
        $llmResponse = 'I recommend the Red Apple, it matches your image and query for a healthy snack.';
        $promptSystem = 'System prompt';
        $promptUser = 'User message for LLM';

        $this->mockImageDescriptionService
            ->expects($this->once())
            ->method('generateDescriptionForImage')
            ->willReturn($imageDescription);

        $this->mockEmbeddingGenerator
            ->expects($this->once())
            ->method('generateQueryEmbedding')
            ->with($combinedQuery)
            ->willReturn($embedding);

        $this->mockVectorStoreService
            ->expects($this->once())
            ->method('searchSimilarProducts')
            ->with($embedding, 3)
            ->willReturn($vectorResults);

        $this->mockPromptService
            ->expects($this->any()) // Could be called multiple times due to fallback logic or for no_results
            ->method('getPrompt')
            ->willReturnMap([
                ['product_finder', 'system_prompt', [], $promptSystem],
                ['product_finder', 'user_message_template_with_image', [
                    'user_query' => $userQuery,
                    'image_description' => $imageDescription,
                    'products_list' => "1. Red Apple (Similarity: 0.9)\n"
                ], $promptUser],
                 ['product_finder', 'no_results_message', [], 'No products found.'], // For other paths
            ]);

        $this->mockSearchService
            ->expects($this->once())
            ->method('generateChatCompletion')
            ->with([
                ['role' => 'system', 'content' => $promptSystem],
                ['role' => 'user', 'content' => $promptUser]
            ])
            ->willReturn($llmResponse);

        $uploadedFile = $this->createTestUploadedFile('test_image.png');

        $this->client->request(
            'POST',
            '/api/products/chat_with_image',
            ['message' => $userQuery], // POST parameters for text
            ['image' => $uploadedFile]    // FILES for image
        );

        $response = $this->client->getResponse();
        $this->assertResponseIsSuccessful();
        $this->assertJson($response->getContent());
        $responseData = json_decode($response->getContent(), true);

        $this->assertTrue($responseData['success']);
        $this->assertEquals($userQuery, $responseData['query']); // Query context for user
        $this->assertEquals($llmResponse, $responseData['response']);
        $this->assertStringContainsString('/uploads/temp_images/test_image', $responseData['uploadedImageUrl']);
        $this->assertEquals($imageDescription, $responseData['imageDescription']);
        $this->assertCount(1, $responseData['products']);
        $this->assertEquals('Red Apple', $responseData['products'][0]['title']);

        // Clean up the test file
        if (file_exists($uploadedFile->getPathname())) {
            unlink($uploadedFile->getPathname());
        }
         // Clean up the moved file in public/uploads/temp_images
        if (!empty($responseData['uploadedImageUrl'])) {
            $projectDir = static::getContainer()->getParameter('kernel.project_dir');
            // Construct path relative to project root
            $filePath = parse_url($responseData['uploadedImageUrl'], PHP_URL_PATH);
            $fullPath = $projectDir . '/public' . $filePath;
            if (file_exists($fullPath)) {
                unlink($fullPath);
            }
        }
    }

    public function testChatWithImageNoImageProvided(): void
    {
        $this->client->request(
            'POST',
            '/api/products/chat_with_image',
            ['message' => 'test query']
        );
        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertFalse($responseData['success']);
        $this->assertEquals('Image file is required', $responseData['message']);
    }

    public function testChatWithImageInvalidMimeType(): void
    {
        $uploadedFile = $this->createTestUploadedFile('test_document.txt', 'not an image', 'text/plain');
        $this->client->request(
            'POST',
            '/api/products/chat_with_image',
            [],
            ['image' => $uploadedFile]
        );
        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertFalse($responseData['success']);
        $this->assertEquals('Invalid image type. Allowed types: JPEG, PNG, GIF, WebP.', $responseData['message']);

        if (file_exists($uploadedFile->getPathname())) {
            unlink($uploadedFile->getPathname());
        }
    }

    public function testChatWithImageDescriptionServiceThrowsException(): void
    {
        $this->mockImageDescriptionService
            ->expects($this->once())
            ->method('generateDescriptionForImage')
            ->willThrowException(new \RuntimeException("AI VISION FAILED"));

        $uploadedFile = $this->createTestUploadedFile('test_image_fail.png');
        $this->client->request(
            'POST',
            '/api/products/chat_with_image',
            ['message' => 'test'],
            ['image' => $uploadedFile]
        );

        $response = $this->client->getResponse();
        $this->assertEquals(500, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertFalse($responseData['success']);
        $this->assertStringContainsString('An error occurred: AI VISION FAILED', $responseData['message']);
        $this->assertNull($responseData['uploadedImageUrl']); // Image URL should be null as it's cleaned up or not set fully

        // File should have been moved and then deleted by the controller's error handling
         $projectDir = static::getContainer()->getParameter('kernel.project_dir');
         // Check if the file exists in the temp upload dir (it shouldn't)
         // This requires knowing the generated unique name, which is tricky here.
         // Instead, we rely on the fact that uploadedImageUrl is null as an indicator of cleanup.
         // A more robust test would involve checking the directory content or mocking the filesystem.
        if (file_exists($uploadedFile->getPathname())) {
            unlink($uploadedFile->getPathname()); // clean original test file
        }
    }


    protected function tearDown(): void
    {
        // General cleanup for any stray temp files if UploadedFile didn't clean itself up.
        $files = glob(sys_get_temp_dir() . '/test_*');
        foreach ($files as $file) {
            if (file_exists($file)) {
                @unlink($file); // Suppress error if file is already gone
            }
        }
        // Clean up public/uploads/temp_images directory more broadly if needed, but be careful
        // For now, individual test cleanup is preferred.
        parent::tearDown();
    }
}
