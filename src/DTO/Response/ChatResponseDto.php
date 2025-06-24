<?php

namespace App\DTO\Response;

readonly class ChatResponseDto implements \JsonSerializable
{
    public bool $success;
    public ?string $query;
    public ?string $message;
    public ?string $response;
    /** @var ProductResponseDto[] */
    public array $products;
    public ?string $uploadedImageUrl; // New property for the image URL
    public ?string $imageDescription; // New property for the AI-generated image description

    /**
     * Initializes a new immutable ChatResponseDto with the provided chat response data.
     *
     * @param bool $success Indicates whether the chat response was successful.
     * @param string|null $query Optional query string associated with the chat.
     * @param string|null $message Optional message to include in the response.
     * @param string|null $response Optional response content.
     * @param ProductResponseDto[] $products Array of product response DTOs related to the chat.
     * @param string|null $uploadedImageUrl Optional URL of the uploaded image.
     * @param string|null $imageDescription Optional AI-generated description of the uploaded image.
     */
    public function __construct(
        bool $success = true,
        ?string $query = null,
        ?string $message = null,
        ?string $response = null,
        array $products = [],
        ?string $uploadedImageUrl = null,
        ?string $imageDescription = null
    ) {
        $this->success = $success;
        $this->query = $query;
        $this->message = $message;
        $this->response = $response;
        $this->products = $products;
        $this->uploadedImageUrl = $uploadedImageUrl;
        $this->imageDescription = $imageDescription;
    }

    /**
     * Returns an associative array representation of the chat response for JSON serialization.
     *
     * The resulting array always includes the keys 'success', 'query', 'response', and 'products'.
     * The 'message', 'uploadedImageUrl', and 'imageDescription' keys are included only if their respective properties are not null.
     *
     * @return array<string, mixed> Associative array suitable for JSON encoding.
     */
    public function jsonSerialize(): array
    {
        $result = [
            'success' => $this->success,
            'query' => $this->query,
            'response' => $this->response,
            'products' => $this->products,
        ];

        if ($this->message !== null) {
            $result['message'] = $this->message;
        }
        if ($this->uploadedImageUrl !== null) {
            $result['uploadedImageUrl'] = $this->uploadedImageUrl;
        }
        if ($this->imageDescription !== null) {
            $result['imageDescription'] = $this->imageDescription;
        }

        return $result;
    }
}
