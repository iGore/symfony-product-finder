<?php

namespace App\Service;

use App\Entity\Product;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service for generating vector embeddings using OpenAI's API
 * 
 * This service implements the EmbeddingGeneratorInterface and provides
 * methods for generating vector embeddings for products and search queries
 * using OpenAI's embedding models. It includes fallback to mock embeddings
 * when the API is unavailable or no API key is provided.
 */
class OpenAIEmbeddingGenerator implements EmbeddingGeneratorInterface
{
    /**
     * HTTP client for making API requests
     */
    private HttpClientInterface $httpClient;

    /**
     * OpenAI API key
     */
    private string $apiKey;

    /**
     * OpenAI embedding model to use
     */
    private string $embeddingModel;

    /**
     * Constructor
     * 
     * @param HttpClientInterface $httpClient The HTTP client for making API requests
     * @param string $apiKey OpenAI API key (default: empty string)
     * @param string $embeddingModel OpenAI embedding model to use (default: 'text-embedding-3-large')
     */
    public function __construct(
        HttpClientInterface $httpClient,
        string $apiKey = '',
        string $embeddingModel = 'text-embedding-3-large'
    ) {
        $this->httpClient = $httpClient;
        $this->apiKey = $apiKey;
        $this->embeddingModel = $embeddingModel;
    }

    /**
     * Set API key for OpenAI
     * 
     * @param string $apiKey The OpenAI API key
     * @return self For method chaining
     */
    public function setApiKey(string $apiKey): self
    {
        $this->apiKey = $apiKey;
        return $this;
    }

    /**
     * Set embedding model
     * 
     * @param string $embeddingModel The OpenAI embedding model to use
     * @return self For method chaining
     */
    public function setEmbeddingModel(string $embeddingModel): self
    {
        $this->embeddingModel = $embeddingModel;
        return $this;
    }

    /**
     * Generate embeddings for a product
     * 
     * Extracts text from the product using getTextForEmbedding() and
     * generates a vector embedding for it.
     * 
     * {@inheritdoc}
     * 
     * @param Product $product The product to generate embeddings for
     * @return array<int, float> The embedding vector
     */
    public function generateEmbedding(Product $product): array
    {
        $text = $product->getTextForEmbedding();
        return $this->generateEmbeddingForText($text);
    }

    /**
     * Generate embeddings for a search query
     * 
     * Creates a vector embedding for the provided search query text.
     * 
     * {@inheritdoc}
     * 
     * @param string $query The search query text
     * @return array<int, float> The embedding vector
     */
    public function generateQueryEmbedding(string $query): array
    {
        return $this->generateEmbeddingForText($query);
    }

    /**
     * Generate embedding for a text using OpenAI API
     * 
     * Makes an API call to OpenAI's embeddings endpoint to generate a vector
     * representation of the provided text. Falls back to mock embeddings if
     * the API key is not set or if the API call fails.
     * 
     * @param string $text The text to generate embeddings for
     * @return array<int, float> The embedding vector
     * @throws \RuntimeException If the API response format is invalid
     */
    private function generateEmbeddingForText(string $text): array
    {
        if (empty($this->apiKey)) {
            // Return mock embedding if no API key is provided
            return $this->generateMockEmbedding($text);
        }

        try {
            $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/embeddings', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'input' => $text,
                    'model' => $this->embeddingModel,
                ],
            ]);

            $data = $response->toArray();

            if (isset($data['data'][0]['embedding'])) {
                return $data['data'][0]['embedding'];
            }

            throw new \RuntimeException('Failed to generate embedding: Invalid response format');
        } catch (\Exception $e) {
            // Fallback to mock embedding on error
            return $this->generateMockEmbedding($text);
        }
    }

    /**
     * Generate a mock embedding for testing purposes
     * 
     * Creates a deterministic but unique embedding vector based on the MD5 hash
     * of the input text. This is used when no API key is provided or when the
     * API call fails. The generated vector has the same dimensions as OpenAI's
     * embeddings (1536).
     * 
     * @param string $text The text to generate a mock embedding for
     * @return array<int, float> The mock embedding vector with 1536 dimensions
     */
    private function generateMockEmbedding(string $text): array
    {
        // Create a deterministic but unique embedding based on the text
        $hash = md5($text);
        $bytes = str_split($hash, 2);

        // Convert to float values between -1 and 1
        $embedding = [];
        foreach ($bytes as $byte) {
            $value = (hexdec($byte) / 255) * 2 - 1;
            $embedding[] = $value;
        }

        // Pad to 1536 dimensions (similar to OpenAI's embeddings)
        while (count($embedding) < 1536) {
            $embedding = array_merge($embedding, $embedding);
        }

        // Trim to exactly 1536
        return array_slice($embedding, 0, 1536);
    }
}
