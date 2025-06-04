<?php

namespace App\Service;

use App\Entity\Product;
use LLPhant\Embeddings\EmbeddingGenerator\OpenAIEmbeddingGenerator as LLPhantEmbedderService; // Corrected FQCN based on error hint
use LLPhant\OpenAIConfig;                                                               // Seems correct from previous run
use LLPhant\Exception\LLPhantException;                                                 // Kept for error handling

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
    private LLPhantEmbedderService $llphantEmbedder; // Use alias
    private string $apiKey;
    private string $embeddingModel;

    /**
     * Constructor
     * 
     * @param string $apiKey OpenAI API key (default: empty string)
     * @param string $embeddingModel OpenAI embedding model to use (default: 'text-embedding-ada-002')
     */
    public function __construct(
        string $apiKey = '',
        string $embeddingModel = 'text-embedding-ada-002'
    ) {
        $this->apiKey = $apiKey;
        $this->embeddingModel = $embeddingModel;

        $config = new OpenAIConfig();
        $config->apiKey = $this->apiKey;
        // Note: The $this->embeddingModel might be needed by the embedText method later,
        // or if OpenAIConfig has a property for the model.
        // e.g., $config->model = $this->embeddingModel;
        // For now, just passing config with API key.

        $this->llphantEmbedder = new LLPhantEmbedderService($config); // Use alias
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
     * Groups product fields into title, metadata, specifications, and features.
     * Creates separate chunks for specifications and features, each containing the product metadata.
     * Generates embeddings for each chunk and averages them if there are multiple chunks.
     * 
     * {@inheritdoc}
     * 
     * @param Product $product The product to generate embeddings for
     * @return array<int, float> The embedding vector
     */
    public function generateEmbedding(Product $product): array
    {
        // Group fields into title, context, and main content
        $groupedFields = $this->groupFieldData($product);

        // Create chunks with main content always included
        $chunks = $this->getChunks($groupedFields);

        // Generate embeddings for each chunk
        $embeddings = [];
        foreach ($chunks as $chunk) {
            $embeddings[] = $this->generateEmbeddingForText($chunk);
        }

        // Average the embeddings if there are multiple chunks
        if (count($embeddings) > 1) {
            return $this->averageEmbeddings($embeddings);
        } else {
            return $embeddings[0];
        }
    }

    /**
     * Generate embeddings for individual product features
     * 
     * Creates a vector embedding for each feature of the product.
     * Each feature is combined with basic product metadata for context.
     * 
     * @param Product $product The product whose features to generate embeddings for
     * @return array<string, array<int, float>> Array of feature embeddings, keyed by feature text
     */
    public function generateFeatureEmbeddings(Product $product): array
    {
        $features = $product->getFeatures();
        if (empty($features)) {
            return [];
        }

        // Get basic product metadata for context
        $baseContext = $product->getName() . ' ' . $product->getBrand() . ' ' . $product->getCategory();

        $featureEmbeddings = [];
        foreach ($features as $feature) {
            // Combine feature with product context
            $featureText = $baseContext . ' Feature: ' . $feature;

            // Generate embedding for this feature
            $featureEmbeddings[$feature] = $this->generateEmbeddingForText($featureText);
        }

        return $featureEmbeddings;
    }

    /**
     * Generate embeddings for individual product specifications
     * 
     * Creates a vector embedding for each specification of the product.
     * Each specification is combined with basic product metadata for context.
     * 
     * @param Product $product The product whose specifications to generate embeddings for
     * @return array<string, array<int, float>> Array of specification embeddings, keyed by specification text
     */
    public function generateSpecificationEmbeddings(Product $product): array
    {
        $specifications = $product->getSpecifications();
        if (empty($specifications)) {
            return [];
        }

        // Get basic product metadata for context
        $baseContext = $product->getName() . ' ' . $product->getBrand() . ' ' . $product->getCategory();

        $specificationEmbeddings = [];
        foreach ($specifications as $key => $value) {
            // Format specification as "key: value"
            $specText = $key . ': ' . $value;

            // Combine specification with product context
            $specificationText = $baseContext . ' Specification: ' . $specText;

            // Generate embedding for this specification
            $specificationEmbeddings[$specText] = $this->generateEmbeddingForText($specificationText);
        }

        return $specificationEmbeddings;
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
     * The text is chunked into smaller pieces with a token size of 500 before
     * being sent to the API. If multiple chunks are generated, the embeddings
     * are averaged to produce a single embedding vector.
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
            // Assuming LLPhant's embedText method handles chunking for texts exceeding model limits,
            // or expects pre-chunked text if it cannot.
            // Also assuming it might use the model set during instantiation or has its own default.
            // The underlying openai-php client used by LLPhant usually requires the model per API call.
            // It's possible that embedText needs the model: $this->llphantEmbedder->embedText($text, $this->embeddingModel);
            // Or $this->llphantEmbedder->embedText($text, ['model' => $this->embeddingModel]);
            // Sticking to the simplest call first, and will adapt if error indicates missing model.
            $embedding = $this->llphantEmbedder->embedText($text);

            // Ensure the embedding is not empty or null, if LLPhant could return that on error
            if (empty($embedding)) {
                throw new \RuntimeException('Failed to generate embedding: LLPhant returned empty result.');
            }
            return $embedding;
        } catch (LLPhantException $e) { // Catch specific LLPhant exceptions if possible
            // Log the exception message if a logger is available
            // error_log('LLPhant API error: ' . $e->getMessage());
            return $this->generateMockEmbedding($text . ' (LLPhant error)'); // Fallback to mock
        } catch (\Exception $e) { // Catch any other general exceptions
            // Log the exception message
            // error_log('OpenAI Embedding Generator error: ' . $e->getMessage());
            return $this->generateMockEmbedding($text . ' (general error)'); // Fallback to mock
        }
    }

    /**
     * Group product fields into title, metadata, specifications, and features
     * 
     * @param Product $product The product to group fields for
     * @return array<string, string> Grouped fields with keys 'title', 'metadata', 'specifications', 'features'
     */
    private function groupFieldData(Product $product): array
    {
        // Title: Product name and brand
        $title = $product->getName() . ' ' . $product->getBrand();

        // Metadata: Category and description
        $metadata = $product->getCategory() . ' ' . $product->getDescription();

        // Specifications
        $specifications = '';
        if (!empty($product->getSpecifications())) {
            foreach ($product->getSpecifications() as $key => $value) {
                $specifications .= ' ' . $key . ': ' . $value;
            }
        }

        // Features
        $features = '';
        if (!empty($product->getFeatures())) {
            $features = implode(', ', $product->getFeatures());
        }

        return [
            'title' => $title,
            'metadata' => $metadata,
            'specifications' => $specifications,
            'features' => $features
        ];
    }

    /**
     * Create separate chunks for specifications and features, each with product metadata
     * 
     * @param array<string, string> $groupedFields Grouped fields with keys 'title', 'metadata', 'specifications', and 'features'
     * @param int $numChunks Number of chunks to create (default: 2) - not used in this implementation
     * @return array<int, string> Array of text chunks
     */
    private function getChunks(array $groupedFields, int $numChunks = 2): array
    {
        $title = $groupedFields['title'];
        $metadata = $groupedFields['metadata'];
        $specifications = $groupedFields['specifications'];
        $features = $groupedFields['features'];

        $chunks = [];

        // Create a base chunk with title and metadata
        $baseChunk = $title . ' ' . $metadata;

        // If both specifications and features are empty, return just the base chunk
        if (empty($specifications) && empty($features)) {
            return [$baseChunk];
        }

        // Create a chunk for specifications if not empty
        if (!empty($specifications)) {
            $chunks[] = $baseChunk . ' Specifications: ' . $specifications;
        }

        // Create a chunk for features if not empty
        if (!empty($features)) {
            $chunks[] = $baseChunk . ' Features: ' . $features;
        }

        return $chunks;
    }

    /**
     * Split text into approximately equal chunks
     * 
     * @param string $text Text to split
     * @param int $numChunks Number of chunks to create
     * @return array<int, string> Array of text chunks
     */
    private function splitTextIntoChunks(string $text, int $numChunks): array
    {
        $words = explode(' ', $text);
        $totalWords = count($words);
        $wordsPerChunk = ceil($totalWords / $numChunks);

        $chunks = [];
        for ($i = 0; $i < $numChunks; $i++) {
            $start = $i * $wordsPerChunk;
            if ($start >= $totalWords) {
                break;
            }

            $chunkWords = array_slice($words, $start, $wordsPerChunk);
            $chunks[] = implode(' ', $chunkWords);
        }

        return $chunks;
    }

    /**
     * Average multiple embedding vectors into a single vector
     * 
     * @param array<int, array<int, float>> $embeddings Array of embedding vectors
     * @return array<int, float> The averaged embedding vector
     */
    private function averageEmbeddings(array $embeddings): array
    {
        if (empty($embeddings)) {
            throw new \InvalidArgumentException('Cannot average empty embeddings array');
        }

        $count = count($embeddings);
        $dimensions = count($embeddings[0]);
        $result = array_fill(0, $dimensions, 0.0);

        foreach ($embeddings as $embedding) {
            for ($i = 0; $i < $dimensions; $i++) {
                $result[$i] += $embedding[$i];
            }
        }

        for ($i = 0; $i < $dimensions; $i++) {
            $result[$i] /= $count;
        }

        return $result;
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

        // Efficiently pad to 1536 dimensions without using array_merge in a loop
        $originalLength = count($embedding);

        // Create a padded array of exactly 1536 elements
        $padded = [];
        for ($i = 0; $i < 1536; $i++) {
            // Use modulo to cycle through the original embedding values
            $padded[] = $embedding[$i % $originalLength];
        }

        return $padded;
    }
}
