<?php

namespace App\Service;

use App\Entity\Product;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenAIEmbeddingGenerator implements EmbeddingGeneratorInterface
{
    private HttpClientInterface $httpClient;
    private string $apiKey;
    private string $embeddingModel;
    
    public function __construct(
        HttpClientInterface $httpClient,
        string $apiKey = '',
        string $embeddingModel = 'text-embedding-3-small'
    ) {
        $this->httpClient = $httpClient;
        $this->apiKey = $apiKey;
        $this->embeddingModel = $embeddingModel;
    }
    
    /**
     * Set API key for OpenAI
     */
    public function setApiKey(string $apiKey): self
    {
        $this->apiKey = $apiKey;
        return $this;
    }
    
    /**
     * Set embedding model
     */
    public function setEmbeddingModel(string $embeddingModel): self
    {
        $this->embeddingModel = $embeddingModel;
        return $this;
    }
    
    /**
     * {@inheritdoc}
     */
    public function generateEmbedding(Product $product): array
    {
        $text = $product->getTextForEmbedding();
        return $this->generateEmbeddingForText($text);
    }
    
    /**
     * {@inheritdoc}
     */
    public function generateQueryEmbedding(string $query): array
    {
        return $this->generateEmbeddingForText($query);
    }
    
    /**
     * Generate embedding for a text using OpenAI API
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
     * This is used when no API key is provided or when the API call fails
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
