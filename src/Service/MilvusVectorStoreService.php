<?php

namespace App\Service;

use App\Entity\Product;
use HelgeSverre\Milvus\Milvus as MilvusClient;
use Psr\Log\LoggerInterface;

/**
 * Service for interacting with Milvus vector database
 * 
 * This service provides methods for storing and retrieving product data
 * in a vector database for similarity search. It handles collection initialization,
 * product insertion, and similarity search operations.
 */
class MilvusVectorStoreService implements VectorStoreInterface
{
    /**
     * Milvus client instance for interacting with the vector database
     */
    private MilvusClient $milvus;

    /**
     * Name of the collection in the vector database
     */
    private string $collectionName;

    /**
     * Dimension of the vector embeddings
     */
    private int $dimension;

    /**
     * Logger for recording operations and errors
     */
    private LoggerInterface $logger;

    /**
     * Constructor
     * 
     * @param MilvusClient $milvus The Milvus client instance
     * @param LoggerInterface $logger The logger service
     * @param string $collectionName The name of the collection to use (default: 'default')
     * @param int $dimension The dimension of the vector embeddings (default: 1536)
     */
    public function __construct(
        MilvusClient $milvus,
        LoggerInterface $logger,
        string $collectionName = 'default',
        int $dimension = 1536
    ) {
        $this->milvus = $milvus;
        $this->logger = $logger;
        $this->collectionName = $collectionName;
        $this->dimension = $dimension;
    }

    /**
     * Initialize the vector database collection
     * 
     * Checks if the collection exists and returns true if it does.
     * If the collection doesn't exist, it calls createCollection to create it.
     * 
     * @return bool True if the collection exists or was created successfully, false otherwise
     */
    public function initializeCollection(): bool
    {
        $this->logger->info('Initializing Milvus collection', [
            'collection_name' => $this->collectionName,
            'dimension' => $this->dimension
        ]);

        try {
            $collections = $this->milvus->collections()->list()->json()['data'];
            if (in_array($this->collectionName, $collections)) {
                $this->logger->info('Collection already exists', [
                    'collection_name' => $this->collectionName
                ]);
                return true;
            }

            $this->logger->info('Collection does not exist, creating new collection', [
                'collection_name' => $this->collectionName
            ]);
            return $this->createCollection($this->dimension);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to initialize collection', [
                'collection_name' => $this->collectionName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Create a new collection in the vector database
     * 
     * Creates a collection with an id field (primary key), auto_id parameter,
     * and a Float_vector field with the specified dimension.
     * 
     * @param int $dimension The dimension of the vector embeddings
     * @return bool True if the collection was created successfully, false otherwise
     */
    public function createCollection(int $dimension): bool
    {
        $this->logger->info('Creating new Milvus collection', [
            'collection_name' => $this->collectionName,
            'dimension' => $dimension,
            'metric_type' => 'L2'
        ]);

        try {
            $this->milvus->collections()->create(
                collectionName: $this->collectionName,
                dimension: $dimension,
                metricType: "L2",
                primaryField: "id",
                vectorField: "vector"
            );

            $this->logger->info('Successfully created Milvus collection', [
                'collection_name' => $this->collectionName
            ]);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create Milvus collection', [
                'collection_name' => $this->collectionName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Insert multiple products into the vector database
     * 
     * Iterates through the provided products array and inserts each valid product
     * with embeddings into the vector database.
     * 
     * @param array<int, Product> $products Array of Product objects to insert
     * @return bool True if all insertions were successful, false if any failed
     */
    public function insertProducts(array $products): bool
    {
        $this->logger->info('Inserting products into Milvus collection', [
            'collection_name' => $this->collectionName,
            'product_count' => count($products)
        ]);

        $insertedCount = 0;
        $skippedCount = 0;

        try {
            foreach ($products as $product) {
                if (empty($product->getEmbeddings())) {
                    $this->logger->warning('Skipping product due to missing embeddings', [
                        'product_id' => $product->getId() ?: 'unknown',
                        'product_name' => $product->getName() ?: 'unknown'
                    ]);
                    $skippedCount++;
                    continue;
                }

                $this->logger->debug('Inserting product into Milvus', [
                    'product_id' => $product->getId(),
                    'product_name' => $product->getName(),
                    'embedding_size' => count($product->getEmbeddings())
                ]);

                $this->milvus->vector()->insert(
                    collectionName: $this->collectionName,
                    data: [
                        'title' => $product->getName(),
                        'vector' => $product->getEmbeddings(),
                        'type' => 'product',
                    ],
                    dbName: $this->collectionName,
                );

                $insertedCount++;
            }

            $this->logger->info('Successfully inserted products into Milvus collection', [
                'collection_name' => $this->collectionName,
                'inserted_count' => $insertedCount,
                'skipped_count' => $skippedCount
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to insert products into Milvus collection', [
                'collection_name' => $this->collectionName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'inserted_before_error' => $insertedCount
            ]);
            return false;
        }
    }

    /**
     * Search for products similar to the provided query embedding
     * 
     * Performs a vector similarity search in the database using the provided
     * query embedding vector.
     * 
     * @param array<int, float> $queryEmbedding The embedding vector to search with
     * @param int $limit Maximum number of results to return (default: 5)
     * @return array<int, mixed> Array of search results, each containing product information
     */
    public function searchSimilarProducts(array $queryEmbedding, int $limit = 5): array
    {
        $this->logger->info('Searching for similar products in Milvus collection', [
            'collection_name' => $this->collectionName,
            'embedding_size' => count($queryEmbedding),
            'limit' => $limit
        ]);

        try {
            $result = $this->milvus->vector()->search(    
                collectionName: $this->collectionName,
                vector: $queryEmbedding,
                limit: $limit,
                outputFields: ["id", "title"],
                dbName: $this->collectionName,
            );

            $data = $result->json()['data'] ?? [];
            $resultCount = count($data);

            $this->logger->info('Successfully retrieved similar products from Milvus', [
                'collection_name' => $this->collectionName,
                'result_count' => $resultCount
            ]);

            if ($resultCount === 0) {
                $this->logger->warning('No similar products found in Milvus collection', [
                    'collection_name' => $this->collectionName
                ]);
            }

            return $data;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to search for similar products in Milvus collection', [
                'collection_name' => $this->collectionName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }
}
