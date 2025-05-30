<?php

namespace App\Service;

use App\Entity\Product;
use HelgeSverre\Milvus\Milvus as MilvusClient;

class ZillizVectorDBService
{
    private MilvusClient $milvus;
    private string $collectionName;
    private int $dimension;

    public function __construct(
        MilvusClient $milvus,
        string $collectionName = 'products',
        int $dimension = 1536
    ) {
        $this->milvus = $milvus;
        $this->collectionName = $collectionName;
        $this->dimension = $dimension;
    }

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
}
