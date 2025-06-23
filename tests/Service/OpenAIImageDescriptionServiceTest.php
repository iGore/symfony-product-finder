<?php

namespace App\Tests\Service;

use App\Service\OpenAIImageDescriptionService;
use OpenAI\Client;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class OpenAIImageDescriptionServiceTest extends KernelTestCase
{
    private Client $mockOpenAIClient;
    private LoggerInterface $mockLogger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockLogger = $this->createMock(LoggerInterface::class);
    }

    private function createService(array $fakeResponses = []): OpenAIImageDescriptionService
    {
        $this->mockOpenAIClient = new ClientFake($fakeResponses);
        return new OpenAIImageDescriptionService($this->mockOpenAIClient, $this->mockLogger, 'gpt-4-vision-preview');
    }

    private function createUploadedFileMock(string $filename = 'test.jpg', string $mimeType = 'image/jpeg', bool $isValid = true): UploadedFile
    {
        $mockFile = $this->createMock(UploadedFile::class);
        $mockFile->method('getClientOriginalName')->willReturn($filename);
        $mockFile->method('getPathname')->willReturn(tempnam(sys_get_temp_dir(), 'upl')); // Create a real temp file
        file_put_contents($mockFile->getPathname(), 'fake image data'); // Add some content
        $mockFile->method('getMimeType')->willReturn($mimeType);
        $mockFile->method('isValid')->willReturn($isValid);
        return $mockFile;
    }

    public function testGenerateDescriptionForImageSuccess(): void
    {
        $expectedDescription = 'A beautiful landscape with mountains and a lake.';
        $fakeResponse = CreateResponse::fake([
            'choices' => [
                [
                    'message' => ['role' => 'assistant', 'content' => $expectedDescription],
                ],
            ],
        ]);
        $service = $this->createService([$fakeResponse]);
        $mockFile = $this->createUploadedFileMock();

        $this->mockLogger->expects($this->exactly(2))->method('info'); // Once for generating, once for success

        $description = $service->generateDescriptionForImage($mockFile, 'Describe this scenic view.');

        $this->assertEquals($expectedDescription, $description);
        // ClientFake asserts that the request was made.
        // We can add more specific assertions about the request payload if ClientFake supports it easily,
        // or by using a more detailed mock for the Client if needed.
    }

    public function testGenerateDescriptionForImageApiError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to generate image description: OpenAI API error');

        $service = $this->createService([new \OpenAI\Exceptions\ErrorException(['message' => 'OpenAI API error', 'type' => 'api_error'])]);
        $mockFile = $this->createUploadedFileMock();

        $this->mockLogger->expects($this->once())->method('info'); // For generating
        $this->mockLogger->expects($this->once())->method('error'); // For the error

        $service->generateDescriptionForImage($mockFile, 'Describe this.');
    }

    public function testGenerateDescriptionForImageInvalidResponseFormat(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to generate image description: Invalid response format from OpenAI client');

        // Simulate a response that doesn't match the expected structure
        $fakeResponse = CreateResponse::fake([
            'choices' => [ // Missing message or content
                [
                    'delta' => ['content' => null] // Not what Chat::create returns for non-stream
                ]
            ],
        ]);
        $service = $this->createService([$fakeResponse]);
        $mockFile = $this->createUploadedFileMock();

        $this->mockLogger->expects($this->once())->method('info');
        $this->mockLogger->expects($this->once())->method('error');

        $service->generateDescriptionForImage($mockFile);
    }

    protected function tearDown(): void
    {
        // Clean up any temp files created by UploadedFile mocks
        // This is a bit simplistic; a more robust way would track them.
        $files = glob(sys_get_temp_dir() . '/upl*');
        foreach ($files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }
}
