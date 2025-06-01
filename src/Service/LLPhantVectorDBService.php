<?php

namespace App\Service;

use App\Entity\Product;
use App\Service\OpenAIEmbeddingGenerator; // Using our existing one
use LLPhant\Embeddings\VectorStores\Memory\MemoryVectorStore;   // Corrected FQCN based on error hint
use LLPhant\Embeddings\Document;                              // Corrected FQCN based on error hint and README link
// Assuming LLPhant might have its own Exception type
use LLPhant\Exception\LLPhantException;                       // Or use \Exception

class LLPhantVectorDBService
{
    private MemoryVectorStore $vectorStore; // Corrected class name
    private OpenAIEmbeddingGenerator $embeddingGenerator; // Or a LLPhant interface if it exists

    public function __construct(OpenAIEmbeddingGenerator $embeddingGenerator)
    {
        $this->embeddingGenerator = $embeddingGenerator;
        // Assuming MemoryVectorStore constructor takes an embedding generator
        $this->vectorStore = new MemoryVectorStore($this->embeddingGenerator); // Corrected class name
    }

    /**
     * Initializes the collection. For InMemoryVectorStore, this might be a no-op.
     *
     * @return bool True if initialization is successful or not needed.
     */
    public function initializeCollection(): bool
    {
        // For InMemoryVectorStore, typically no explicit initialization is needed after construction.
        // If it were a persistent store, this might involve creating tables/indexes.
        return true;
    }

    /**
     * Converts a Product entity to an LLPhant Document.
     * The document's content is a concatenation of key product information.
     * Metadata includes the product_id.
     */
    private function productToDocument(Product $product): Document
    {
        $content = $product->getName() . " " .
                   $product->getDescription() . " " .
                   $product->getBrand() . " " .
                   $product->getCategory();

        // Assuming Document constructor: content, metadata array
        $document = new Document($content);
        $document->metadata['product_id'] = $product->getId();
        // Add other relevant metadata if needed by the application
        $document->metadata['name'] = $product->getName();

        return $document;
    }

    /**
     * Inserts a single product into the vector database.
     *
     * @param Product $product The product to insert.
     * @return bool True on success, false on failure.
     */
    public function insertProduct(Product $product): bool
    {
        try {
            $document = $this->productToDocument($product);
            $this->vectorStore->addDocument($document); // Assumes this embeds and stores
            return true;
        } catch (LLPhantException $e) {
            // Log error: "Failed to insert product {$product->getId()}: {$e->getMessage()}"
            return false;
        } catch (\Exception $e) {
            // Log error
            return false;
        }
    }

    /**
     * Inserts multiple products into the vector database.
     *
     * @param Product[] $products An array of products to insert.
     * @return bool True if all insertions were successful, false otherwise.
     */
    public function insertProducts(array $products): bool
    {
        $allSucceeded = true;
        foreach ($products as $product) {
            if (!$this->insertProduct($product)) {
                $allSucceeded = false;
                // Optionally, collect errors or stop on first failure
            }
        }
        return $allSucceeded;
    }

    /**
     * Inserts product features into the vector database.
     * Each feature is treated as a separate document for embedding.
     *
     * @param Product $product The product whose features to insert.
     * @param array $featureEmbeddings Not used if vector store handles embedding. Kept for signature consistency for now.
     * @return bool True on success, false on failure.
     */
    public function insertProductFeatures(Product $product, array $featureEmbeddings): bool
    {
        // This method's signature included $featureEmbeddings, but with LLPhant
        // handling embeddings via the generator in its store, we'll use feature text.
        // If pre-computed embeddings were to be used, LLPhant API would need to support that.
        try {
            $baseInfo = $product->getName() . " " . $product->getBrand() . " " . $product->getCategory();
            foreach ($product->getFeatures() as $featureText) {
                $content = $baseInfo . " Feature: " . $featureText;
                $document = new Document($content);
                $document->metadata['product_id'] = $product->getId();
                $document->metadata['type'] = 'feature';
                $document->metadata['text'] = $featureText; // Store original feature text
                $this->vectorStore->addDocument($document);
            }
            return true;
        } catch (LLPhantException $e) {
            // Log error
            return false;
        } catch (\Exception $e) {
            // Log error
            return false;
        }
    }

    /**
     * Inserts product specifications into the vector database.
     * Each specification is treated as a separate document for embedding.
     *
     * @param Product $product The product whose specifications to insert.
     * @param array $specificationEmbeddings Not used if vector store handles embedding.
     * @return bool True on success, false on failure.
     */
    public function insertProductSpecifications(Product $product, array $specificationEmbeddings): bool
    {
        try {
            $baseInfo = $product->getName() . " " . $product->getBrand() . " " . $product->getCategory();
            foreach ($product->getSpecifications() as $key => $value) {
                $specText = $key . ": " . $value;
                $content = $baseInfo . " Specification: " . $specText;
                $document = new Document($content);
                $document->metadata['product_id'] = $product->getId();
                $document->metadata['type'] = 'specification';
                $document->metadata['text'] = $specText; // Store original spec text
                $this->vectorStore->addDocument($document);
            }
            return true;
        } catch (LLPhantException $e) {
            // Log error
            return false;
        } catch (\Exception $e) {
            // Log error
            return false;
        }
    }

    /**
     * Searches for products similar to a given query embedding.
     *
     * @param array $queryEmbedding The embedding of the search query.
     * @param int $limit The maximum number of similar products to return.
     * @return array An array of product data (e.g., IDs, names) that are similar.
     */
    public function searchSimilarProducts(array $queryEmbedding, int $limit = 5): array
    {
        try {
            // Assuming similaritySearch returns an array of Document objects
            $results = $this->vectorStore->similaritySearch($queryEmbedding, $limit);

            $similarProducts = [];
            foreach ($results as $document) {
                if ($document instanceof Document && isset($document->metadata['product_id'])) {
                    // We might want to return more than just ID, e.g., name, or reconstruct product
                    // For now, just collecting IDs and names as an example.
                    // This part depends heavily on what `similaritySearch` returns and what the app expects.
                    $similarProducts[] = [
                        'id' => $document->metadata['product_id'],
                        'name' => $document->metadata['name'] ?? null, // Assuming name was stored in metadata
                        // 'score' => $document->score ?? null, // If LLPhant documents have a score
                    ];
                }
            }
            return $similarProducts;
        } catch (LLPhantException $e) {
            // Log error
            return [];
        } catch (\Exception $e) {
            // Log error
            return [];
        }
    }
}
