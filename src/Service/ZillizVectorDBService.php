<?php

namespace App\Service;

use App\Entity\Product;
use HelgeSverre\Milvus\Milvus as MilvusClient;

/**
 * Service for interacting with Zilliz/Milvus vector database
 * 
 * This service provides methods for storing and retrieving product data
 * in a vector database for similarity search. It handles collection initialization,
 * product insertion, and similarity search operations.
 */
class ZillizVectorDBService
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
     * Initializes the ZillizVectorDBService with the specified Milvus client, collection name, and embedding dimension.
     *
     * @param string $collectionName Name of the collection to use in the vector database. Defaults to 'default'.
     * @param int $dimension Dimension of the vector embeddings. Defaults to 1536.
     */
    public function __construct(
        MilvusClient $milvus,
        string $collectionName = 'default',
        int $dimension = 1536
    ) {
        $this->milvus = $milvus;
        $this->collectionName = $collectionName;
        $this->dimension = $dimension;
    }

    /**
     * Checks if the specified vector database collection exists.
     *
     * Returns true if the collection exists; otherwise, returns false.
     *
     * @return bool True if the collection exists, false otherwise.
     */
    public function initializeCollection(): bool
    {
        try {
            $collections = $this->milvus->collections()->list()->json()['data'];
            if (in_array($this->collectionName, $collections)) {
                return true;
            }

            // $this->milvus->createCollection([
            //     'collection_name' => $this->collectionName,
            //     'fields' => [
            //         [
            //             'name' => 'id',
            //             'description' => 'ID',
            //             'data_type' => 'INT64',
            //             'is_primary_key' => true,
            //             'auto_id' => false,
            //         ],
            //         [
            //             'name' => 'product_name',
            //             'description' => 'Product Name',
            //             'data_type' => 'VARCHAR',
            //             'max_length' => 255,
            //         ],
            //         [
            //             'name' => 'embedding',
            //             'description' => 'Embedding',
            //             'data_type' => 'FLOAT_VECTOR',
            //             'type_params' => [
            //                 'dim' => $this->dimension,
            //             ],
            //         ],
            //     ],
            // ]);
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Inserts multiple products with embeddings into the vector database.
     *
     * Each product in the array is inserted as a vector record if it has valid embeddings.
     *
     * @param array<int, Product> $products List of Product objects to insert.
     * @return bool True if all valid products are inserted successfully; false on any failure.
     */
    public function insertProducts(array $products): bool
    {
        try {
            foreach ($products as $product) {
                if (!$product instanceof Product || empty($product->getEmbeddings())) {
                    continue;
                }

                $this->milvus->vector()->insert(
                    collectionName: $this->collectionName,
                    data: [
                        'title' => $product->getName(),
                        'vector' => $product->getEmbeddings(),
                        'type' => 'product',
                    ],
                    dbName: $this->collectionName,
                );
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Inserts the features of a product and their embeddings into the vector database.
     *
     * For each feature of the given product that has a corresponding embedding, creates a vector record in the collection with relevant product information.
     *
     * @param Product $product The product whose features are being inserted.
     * @param array<string, array<int, float>> $featureEmbeddings Embeddings keyed by feature name.
     * @return bool True if all insertions succeed or if there are no features/embeddings; false if an error occurs.
     */
    public function insertProductFeatures(Product $product, array $featureEmbeddings): bool
    {
        try {
            $features = $product->getFeatures();
            if (empty($features) || empty($featureEmbeddings)) {
                return true;
            }

            foreach ($features as $index => $feature) {
                if (!isset($featureEmbeddings[$feature])) {
                    continue;
                }

                $this->milvus->vector()->insert(
                    collectionName: $this->collectionName,
                    data: [
                        'title' => $feature,
                        'vector' => $featureEmbeddings[$feature],
                        'product_id' => $product->getId(),
                        'product_name' => $product->getName(),
                        'type' => 'feature',
                    ]
                );
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Inserts a product's specifications and their embeddings into the vector database.
     *
     * For each specification of the given product, stores its text and corresponding embedding as a vector record in the collection. Skips specifications without embeddings. Returns true if all insertions succeed or if there are no specifications or embeddings; returns false on error.
     *
     * @param Product $product The product whose specifications are to be inserted.
     * @param array<string, array<int, float>> $specificationEmbeddings Embeddings keyed by specification text.
     * @return bool True if all insertions succeed or if there is nothing to insert; false on failure.
     */
    public function insertProductSpecifications(Product $product, array $specificationEmbeddings): bool
    {
        try {
            $specifications = $product->getSpecifications();
            if (empty($specifications) || empty($specificationEmbeddings)) {
                return true;
            }

            $index = 0;
            foreach ($specifications as $key => $value) {
                $specText = $key . ': ' . $value;
                if (!isset($specificationEmbeddings[$specText])) {
                    continue;
                }

                $this->milvus->vector()->insert(
                    collectionName: $this->collectionName,
                    data: [
                        'title' => $specText,
                        'vector' => $specificationEmbeddings[$specText],
                        'product_id' => $product->getId(),
                        'product_name' => $product->getName(),
                        'type' => 'specification',
                    ]
                );
                $index++;
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Searches for products in the vector database that are most similar to the given embedding.
     *
     * Performs a vector similarity search using the provided embedding and returns up to the specified number of matching products.
     *
     * @param array<int, float> $queryEmbedding Embedding vector to use as the search query.
     * @param int $limit Maximum number of similar products to return.
     * @return array<int, mixed> List of matching products, each containing product information such as id, title, and link. Returns an empty array if no matches are found or on error.
     */
    public function searchSimilarProducts(array $queryEmbedding, int $limit = 5): array
    {
        try {
            $result = $this->milvus->vector()->search(    
                collectionName: $this->collectionName,
                vector: $queryEmbedding,
                limit: $limit,
                outputFields: ["id", "title", "link"],
            );

            return $result->json()['data'] ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
