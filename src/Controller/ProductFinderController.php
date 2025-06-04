<?php

namespace App\Controller;

use App\Service\EmbeddingGeneratorInterface;
use App\Service\ZillizVectorDBService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class ProductFinderController extends AbstractController
{
    private EmbeddingGeneratorInterface $embeddingGenerator;
    private ZillizVectorDBService $vectorDBService;

    public function __construct(
        EmbeddingGeneratorInterface $embeddingGenerator,
        ZillizVectorDBService $vectorDBService
    ) {
        $this->embeddingGenerator = $embeddingGenerator;
        $this->vectorDBService = $vectorDBService;
    }

    #[Route('/api/products/search', name: 'api_products_search', methods: ['POST'])]
    public function searchProducts(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $query = $data['query'] ?? '';
        $type = $data['type'] ?? 'hybrid'; // Default to 'hybrid'

        if (empty($query)) {
            return $this->json([
                'success' => false,
                'message' => 'Query parameter is required',
                // Consistent error response structure based on type
                ($type === 'vector' ? 'vector_results' : ($type === 'keyword' ? 'keyword_results' : 'products')) => [],
            ], 400);
        }

        $vectorResults = [];
        $keywordResults = [];

        if ($type === 'vector' || $type === 'hybrid') {
            $queryEmbedding = $this->embeddingGenerator->generateQueryEmbedding($query);
            $vectorResults = $this->vectorDBService->searchSimilarProducts($queryEmbedding);
        }

        if ($type === 'keyword' || $type === 'hybrid') {
            $keywordResults = $this->vectorDBService->keywordSearch($query);
        }
        
        if ($type === 'hybrid') {
            $combinedProducts = $this->combineHybridResults($vectorResults, $keywordResults);
            return $this->json([
                'success' => true,
                'query' => $query,
                'type' => $type,
                'products' => $combinedProducts
            ]);
        } elseif ($type === 'vector') {
            return $this->json([
                'success' => true,
                'query' => $query,
                'type' => $type,
                'vector_results' => $vectorResults
            ]);
        } else { // type === 'keyword'
            return $this->json([
                'success' => true,
                'query' => $query,
                'type' => $type,
                'keyword_results' => $keywordResults
            ]);
        }
    }

    private function combineHybridResults(array $vectorResults, array $keywordResults): array
    {
        $combined = [];
        $vectorWeight = 0.7;
        $keywordWeight = 0.3;

        // Process vector results
        foreach ($vectorResults as $result) {
            $productId = $result['primary_key'] ?? null;
            if ($productId === null) continue; // Skip if no ID

            $score = $result['score'] ?? 0; // Assume similarity score (0-1)
                                            // If distance, it would need inversion e.g. 1 / (1 + distance)
                                            // As per subtask: "assume it's already a similarity score between 0 and 1"

            $combined[$productId] = [
                'id' => $productId,
                'name' => $result['title'] ?? 'N/A', // From vector search result
                // 'description' => $result['description'] ?? '', // If available
                'score' => $score * $vectorWeight,
                'search_type' => 'vector',
                'original_data' => $result // Keep original vector hit for reference if needed
            ];
        }

        // Process keyword results (App\Entity\Product objects)
        foreach ($keywordResults as $product) {
            $productId = $product->getId();
            $keywordBaseScore = 1.0; // Default score for keyword matches

            if (isset($combined[$productId])) {
                $combined[$productId]['score'] += $keywordBaseScore * $keywordWeight;
                $combined[$productId]['search_type'] = 'hybrid';
                // Update name from Product entity if it's more complete/accurate
                $combined[$productId]['name'] = $product->getName();
                // Potentially add/update other fields like description
                // $combined[$productId]['description'] = $product->getDescription();
            } else {
                $combined[$productId] = [
                    'id' => $productId,
                    'name' => $product->getName(),
                    // 'description' => $product->getDescription(), // If needed
                    'score' => $keywordBaseScore * $keywordWeight,
                    'search_type' => 'keyword',
                    'original_data' => [ // Construct similar structure as vector for consistency
                        'primary_key' => $productId,
                        'title' => $product->getName(),
                        // 'description' => $product->getDescription(),
                        // No 'score' from keyword entity itself, so not included here
                    ]
                ];
            }
        }

        // Convert associative array to indexed array for sorting
        $sortableCombined = array_values($combined);

        // Sort by score descending
        usort($sortableCombined, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return $sortableCombined;
    }

    #[Route('/api/products/chat', name: 'api_products_chat', methods: ['POST'])]
    public function chatSearch(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $message = $data['message'] ?? '';
        $history = $data['history'] ?? []; // <-- History auslesen

        if (empty($message)) {
            return $this->json([
                'success' => false,
                'message' => 'Message parameter is required',
                'response' => null,
                'products' => []
            ], 400);
        }

        // History an Intent-Extraktion übergeben
        $searchQuery = $this->extractSearchIntent($message, $history);

        $queryEmbedding = $this->embeddingGenerator->generateQueryEmbedding($searchQuery);
        $results = $this->vectorDBService->searchSimilarProducts($queryEmbedding);
        $responseMessage = $this->generateChatResponse($message, $searchQuery, $results, $history);

        return $this->json([
            'success' => true,
            'query' => $searchQuery,
            'response' => $responseMessage,
            'products' => $results
        ]);
    }
    
    /**
     * Extract search intent from a chat message
     */
    private function extractSearchIntent(string $message, array $history = []): string
    {
        // Beispiel: Nutze die letzte Nutzer-Nachricht als Such-Query
        // Hier könntest du auch komplexere Logik/NLP einsetzen
        return $message;
    }
    
    /**
     * Generate a chat response based on the search results
     */
    private function generateChatResponse(string $originalMessage, string $searchQuery, array $results, array $history = []): string
    {
        // Du kannst die History hier für Kontext verwenden, z.B. für Follow-Ups
        if (empty($results)) {
            return "Es tut mir leid, ich konnte keine passenden Produkte zu Ihrer Anfrage finden. Können Sie Ihre Anforderungen genauer beschreiben?";
        }
        
        $count = count($results);
        $productNames = array_map(function($result) {
            return $result['title'] ?? 'Unbekanntes Produkt';
        }, array_slice($results, 0, 3));
        
        $productList = implode(', ', $productNames);
        if ($count > 3) {
            $productList .= ' und ' . ($count - 3) . ' weitere';
        }
        
        return "Basierend auf Ihrer Anfrage \"$originalMessage\" habe ich folgende Produkte gefunden: $productList. Möchten Sie mehr Details zu einem bestimmten Produkt?";
    }
}
