<?php

namespace App\Controller;

use App\DTO\Request\ChatRequestDto;
use App\DTO\Response\ChatResponseDto;
use App\DTO\Response\ProductResponseDto;
use App\Service\EmbeddingGeneratorInterface;
use App\Service\ImageDescriptionServiceInterface;
use App\Service\PromptServiceInterface;
use App\Service\SearchServiceInterface;
use App\Service\VectorStoreInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Annotation\Route;

class ProductFinderController extends AbstractController
{
    private EmbeddingGeneratorInterface $embeddingGenerator;
    private VectorStoreInterface $vectorStoreService;
    private PromptServiceInterface $promptService;
    private SearchServiceInterface $searchService;
    private ImageDescriptionServiceInterface $imageDescriptionService;
    private string $projectDir;

    public function __construct(
        EmbeddingGeneratorInterface $embeddingGenerator,
        VectorStoreInterface $vectorStoreService,
        SearchServiceInterface $searchService,
        PromptServiceInterface $promptService,
        ImageDescriptionServiceInterface $imageDescriptionService,
        string $projectDir
    ) {
        $this->embeddingGenerator = $embeddingGenerator;
        $this->vectorStoreService = $vectorStoreService;
        $this->searchService = $searchService;
        $this->promptService = $promptService;
        $this->imageDescriptionService = $imageDescriptionService;
        $this->projectDir = $projectDir;
    }


    /**
     * Create a response for when no results are found
     */
    private function createNoResultsResponse(string $searchQuery): JsonResponse
    {
        $noResultsMessage = $this->promptService->getPrompt('product_finder', 'no_results_message');
        $response = new ChatResponseDto(
            true,
            $searchQuery,
            null,
            $noResultsMessage,
            []
        );
        return $this->json($response);
    }

    /**
     * Handles chat-based product search requests and returns product recommendations.
     *
     * Accepts a chat message as a search query, generates an embedding, retrieves similar products from a vector store, filters results by similarity, and uses prompt templates to generate a chat-based recommendation. Returns a structured JSON response containing the recommendation and matching products, or an error message if no suitable products are found or an error occurs.
     *
     * @param ChatRequestDto $chatRequest The incoming chat request containing the user's message.
     * @return JsonResponse JSON response with product recommendations, no-results message, or error details.
     */
    #[Route('/api/products/chat', name: 'api_products_chat', methods: ['POST'])]
    public function chatSearch(#[MapRequestPayload] ChatRequestDto $chatRequest): JsonResponse
    {
        $message = $chatRequest->message;

        if (empty($message)) {
            $response = new ChatResponseDto(
                false,
                null,
                'Message parameter is required',
                null,
                []
            );
            return $this->json($response, 400);
        }

        // Use message directly as search query
        $searchQuery = $message;

        try {
            // Generate embedding for the query
            $queryEmbedding = $this->embeddingGenerator->generateQueryEmbedding($searchQuery);

            // Search for similar products
            $results = $this->vectorStoreService->searchSimilarProducts($queryEmbedding, 3);

            if (empty($results)) {
                return $this->createNoResultsResponse($searchQuery);
            }

            // Filter results to only include products with distance <= 0.5
            $filteredResults = array_filter($results, static function($result) {
                return isset($result['distance']) && $result['distance'] <= 0.5;
            });

            if (empty($filteredResults)) {
                return $this->createNoResultsResponse($searchQuery);
            }

            // Create system prompt that acts as a product finder
            $systemPromptContent = $this->promptService->getPrompt('product_finder', 'system_prompt');
            $systemPrompt = [
                'role' => 'system',
                'content' => $systemPromptContent
            ];

            // Create user message with query and products
            $productsList = '';
            foreach ($filteredResults as $index => $result) {
                $productsList .= ($index + 1) . ". " . ($result['title'] ?? 'Unknown product') . " (Similarity: " . (1 - ($result['distance'] ?? 0)) . ")\n";
            }

            $userMessageContent = $this->promptService->getPrompt('product_finder', 'user_message_template', [
                'query' => $searchQuery,
                'products_list' => $productsList
            ]);

            $userMessage = [
                'role' => 'user',
                'content' => $userMessageContent
            ];

            $messages = [$systemPrompt, $userMessage];
            $recommendation = $this->searchService->generateChatCompletion($messages);

            // Convert results to ProductResponseDto objects
            $productDtos = array_map(static function($result) {
                return ProductResponseDto::fromArray($result);
            }, $filteredResults);

            $response = new ChatResponseDto(
                true,
                $searchQuery,
                null,
                $recommendation,
                $productDtos
            );

            return $this->json($response);
        } catch (\Exception $e) {
            $response = new ChatResponseDto(
                false,
                $searchQuery,
                'An error occurred during search: ' . $e->getMessage(),
                null,
                []
            );
            return $this->json($response, 500);
        }
    }

    #[Route('/api/products/chat_with_image', name: 'api_products_chat_with_image', methods: ['POST'])]
    public function chatWithImage(Request $request): JsonResponse
    {
        /** @var UploadedFile|null $imageFile */
        $imageFile = $request->files->get('image');
        $userMessage = $request->request->get('message', ''); // Optional text message from user

        if (!$imageFile) {
            return $this->json(new ChatResponseDto(false, null, 'Image file is required'), 400);
        }

        if (!$imageFile->isValid()) {
            return $this->json(new ChatResponseDto(false, null, 'Invalid image file: ' . $imageFile->getError()), 400);
        }

        // Validate MIME type
        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($imageFile->getMimeType(), $allowedMimeTypes, true)) {
            return $this->json(new ChatResponseDto(false, null, 'Invalid image type. Allowed types: JPEG, PNG, GIF, WebP.'), 400);
        }

        // For simplicity, save the image to a public temporary directory to make it accessible via URL.
        // In a production app, use a proper file storage service (e.g., S3, or Symfony's public dir with unique names).
        $tempDir = $this->projectDir . '/public/uploads/temp_images/';
        if (!is_dir($tempDir) && !mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
            // @codeCoverageIgnoreStart
            // This is hard to test reliably without more complex setup
            return $this->json(new ChatResponseDto(false, null, 'Could not create temporary directory for image upload.'), 500);
            // @codeCoverageIgnoreEnd
        }
        $imageFileName = uniqid('img_', true) . '.' . $imageFile->guessExtension();
        try {
            //$imageFile->move($tempDir, $imageFileName);
        } catch (\Exception $e) {
            return $this->json(new ChatResponseDto(false, null, $e->getMessage()), 500);

        }
        $imageUrl = $request->getSchemeAndHttpHost() . '/uploads/temp_images/' . $imageFileName;

        $imageDescription = '';
        $searchQueryContext = '';

        try {
            // 1. Get image description
            $imageDescription = $this->imageDescriptionService->generateDescriptionForImage($imageFile, 'Describe this image for a product search query.');

            // The original uploaded file path might be invalid after move, so use the new path
            // However, imageDescriptionService expects UploadedFile.
            // For now, we pass the original UploadedFile object. If the service needs to re-read, this might be an issue.
            // A better approach for the service might be to accept path or content.
            // For now, we assume it reads content on first call.

            // 2. Combine with user's text message if provided
            $searchQuery = $imageDescription;
            if (!empty($userMessage)) {
                $searchQuery = $userMessage . "\n\nImage Content: " . $imageDescription;
                $searchQueryContext = $userMessage; // For display in response if needed
            } else {
                $searchQueryContext = "Image Analysis";
            }

            // 3. Generate embedding for the combined query
            $queryEmbedding = $this->embeddingGenerator->generateQueryEmbedding($searchQuery);

            // 4. Search for similar products
            $results = $this->vectorStoreService->searchSimilarProducts($queryEmbedding, 3);

            if (empty($results)) {
                return $this->json(new ChatResponseDto(
                    true,
                    $searchQueryContext,
                    null,
                    $this->promptService->getPrompt('product_finder', 'no_results_message'),
                    [],
                    $imageUrl,
                    $imageDescription
                ));
            }

            $filteredResults = array_filter($results, static fn($result) => isset($result['distance']) && $result['distance'] <= 0.5);

            if (empty($filteredResults)) {
                 return $this->json(new ChatResponseDto(
                    true,
                    $searchQueryContext,
                    null,
                    $this->promptService->getPrompt('product_finder', 'no_results_message'),
                    [],
                    $imageUrl,
                    $imageDescription
                ));
            }

            // 5. Generate chat completion
            $productsList = '';
            foreach ($filteredResults as $index => $result) {
                $productsList .= ($index + 1) . ". " . ($result['title'] ?? 'Unknown product') . " (Similarity: " . (1 - ($result['distance'] ?? 0)) . ")\n";
            }

            $systemPromptContent = $this->promptService->getPrompt('product_finder', 'system_prompt');
            $userMessagePrompt = $this->promptService->getPrompt('product_finder', 'user_message_template_with_image', [
                'user_query' => $userMessage ?: 'the uploaded image',
                'image_description' => $imageDescription,
                'products_list' => $productsList
            ]);

            // If the specific prompt for image context doesn't exist, fall back.
            if (str_contains($userMessagePrompt, "Prompt 'product_finder.user_message_template_with_image' not found")) {
                 $userMessagePrompt = $this->promptService->getPrompt('product_finder', 'user_message_template', [
                    'query' => $searchQueryContext . ($userMessage ? " (based on image and text)" : " (based on image)"),
                    'products_list' => $productsList
                ]);
            }


            $messages = [
                ['role' => 'system', 'content' => $systemPromptContent],
                ['role' => 'user', 'content' => $userMessagePrompt]
            ];
            $recommendation = $this->searchService->generateChatCompletion($messages);

            $productDtos = array_map(static fn($result) => ProductResponseDto::fromArray($result), $filteredResults);

            return $this->json(new ChatResponseDto(
                true,
                $searchQueryContext, // This is the effective query for the user
                null,
                $recommendation,
                $productDtos,
                $imageUrl,
                $imageDescription
            ));

        } catch (\Exception $e) {
            // Clean up uploaded file in case of error
//            if (file_exists($tempDir . $imageFileName)) {
//                unlink($tempDir . $imageFileName);
//            }
            return $this->json(new ChatResponseDto(
                false,
                $searchQueryContext,
                'An error occurred: ' . $e->getMessage(),
                null,
                [],
                null, // No image URL if error
                $imageDescription ?: null // Send description if it was generated before error
            ), 500);
        }
    }
}
