<?php

namespace App\Service;

use OpenAI\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class OpenAIImageDescriptionService implements ImageDescriptionServiceInterface
{
    private Client $client;
    private LoggerInterface $logger;
    private string $visionModel;

    public function __construct(
        Client $client,
        LoggerInterface $logger,
        string $openAiVisionModel = 'gpt-4-vision-preview' // Default vision model
    ) {
        $this->client = $client;
        $this->logger = $logger;
        $this->visionModel = $openAiVisionModel;
    }

    /**
     * Generates a textual description for the given image file using OpenAI.
     *
     * @param UploadedFile $imageFile The uploaded image file.
     * @param string|null $prompt An optional prompt to guide the description generation.
     * @return string The generated textual description of the image.
     * @throws \RuntimeException If description generation fails or API response is invalid.
     */
    public function generateDescriptionForImage(UploadedFile $imageFile, ?string $prompt = null): string
    {
        $this->logger->info('Generating image description', [
            'model' => $this->visionModel,
            'filename' => $imageFile->getClientOriginalName(),
        ]);

        try {
            $imageData = base64_encode(file_get_contents($imageFile->getPathname()));
            $mimeType = $imageFile->getMimeType() ?: 'image/jpeg'; // Default to jpeg if mime type is not available

            $messages = [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $prompt ?? 'Describe this image in detail. What objects are present? What is happening? What product might this be related to?',
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => "data:{$mimeType};base64,{$imageData}",
                            ],
                        ],
                    ],
                ],
            ];

            $requestOptions = [
                'model' => $this->visionModel,
                'messages' => $messages,
                'max_tokens' => 300, // Adjust as needed
            ];

            $response = $this->client->chat()->create($requestOptions);

            if (isset($response->choices[0]->message->content)) {
                $content = $response->choices[0]->message->content;
                $this->logger->info('Successfully received image description from OpenAI', [
                    'content_length' => strlen($content),
                ]);
                return $content;
            } else {
                $this->logger->error('Invalid response format from OpenAI client for image description', [
                    'response' => json_encode($response),
                ]);
                throw new \RuntimeException('Failed to generate image description: Invalid response format from OpenAI client');
            }
        } catch (\Exception $e) {
            $this->logger->error('Error generating image description with OpenAI API', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \RuntimeException('Failed to generate image description: ' . $e->getMessage(), 0, $e);
        }
    }
}
