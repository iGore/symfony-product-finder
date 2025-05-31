<?php

namespace App\Service;

use App\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;
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
     * EntityManager instance for database operations
     */
    private EntityManagerInterface $entityManager;

    /**
     * Constructor
     * 
     * @param MilvusClient $milvus The Milvus client instance
     * @param EntityManagerInterface $entityManager The Doctrine entity manager
     * @param string $collectionName The name of the collection to use (default: 'products')
     * @param int $dimension The dimension of the vector embeddings (default: 1536)
     */
    public function __construct(
        MilvusClient $milvus,
        EntityManagerInterface $entityManager,
        string $collectionName = 'products',
        int $dimension = 1536
    ) {
        $this->milvus = $milvus;
        $this->entityManager = $entityManager;
        $this->collectionName = $collectionName;
        $this->dimension = $dimension;
    }

    /**
     * Initialize the vector database collection
     * 
     * Checks if the collection exists and returns true if it does.
     * Currently, collection creation is commented out.
     * 
     * @return bool True if the collection exists or was created successfully, false otherwise
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
     * Insert a single product into the vector database
     * 
     * Note: This method contains debug code (dump statements) and uses hardcoded
     * vector values instead of the product's actual embeddings.
     * 
     * @param Product $product The product to insert
     * @return bool True if the insertion was successful, false otherwise
     */
    public function insertProduct(Product $product): bool
    {
        dump('Test');

        dump($product->getName());
        dump($product->getEmbeddings());
        try {
            $this->milvus->vector()->insert(
                collectionName: $this->collectionName,
                data: [
                    'vector' => [0.1, 0.2, 0.3 /* etc... */],
                    "title" => $product->getName(),
                    "link" => "https://example.com/document-name-here",
                ]
            );
            return true;
        } catch (\Throwable $e) {
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
        try {
            foreach ($products as $product) {
                if (!$product instanceof Product || empty($product->getEmbeddings())) {
                    continue;
                }

                $this->milvus->vector()->insert(
                    collectionName: $this->collectionName,
                    data: [
                        'primary_key' => $product->getId(),
                        'title' => $product->getName(),
                        'vector' => $product->getEmbeddings(),
                    ]
                );
            }

            return true;
        } catch (\Throwable $e) {
            dump($e->getMessage());
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
        try {
            $result = $this->milvus->vector()->search(    
                collectionName: $this->collectionName,
                vector: $queryEmbedding,
                outputFields: ["primary_key", "title", "link"],
            );

            return $result->json()['data'] ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Search for products by keywords in name or description.
     *
     * @param string $query The search query string (keywords separated by space).
     * @param int $limit The maximum number of products to return.
     * @return array<int, Product> An array of Product entities.
     */
    public function keywordSearch(string $query, int $limit = 5): array
    {
        $keywords = array_filter(explode(' ', $query));

        if (empty($keywords)) {
            return [];
        }

        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('p')
            ->from(Product::class, 'p');

        $orX = $qb->expr()->orX();
        foreach ($keywords as $index => $keyword) {
            $placeholder = ':keyword' . $index;
            $orX->add($qb->expr()->like('p.name', $placeholder));
            $orX->add($qb->expr()->like('p.description', $placeholder));
            $qb->setParameter($placeholder, '%' . $keyword . '%');
        }
        $qb->where($orX);

        $qb->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }
}
