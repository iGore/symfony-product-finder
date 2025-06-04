<?php

namespace App\Tests\Controller;

use App\Entity\Product;
use App\Service\EmbeddingGeneratorInterface;
use App\Service\ZillizVectorDBService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class ProductFinderControllerTest extends WebTestCase
{
    private $client;
    private $vectorDBServiceMock;
    private $embeddingGeneratorMock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();

        // Create mocks for the services
        $this->vectorDBServiceMock = $this->createMock(ZillizVectorDBService::class);
        $this->embeddingGeneratorMock = $this->createMock(EmbeddingGeneratorInterface::class);

        // Configure the embedding generator mock to return a dummy embedding for any query
        $this->embeddingGeneratorMock->method('generateQueryEmbedding')
            ->willReturn([0.1, 0.2, 0.3]); // Dummy embedding vector

        // Replace services in the container with our mocks
        // static::getContainer() is available in WebTestCase
        static::getContainer()->set(ZillizVectorDBService::class, $this->vectorDBServiceMock);
        static::getContainer()->set(EmbeddingGeneratorInterface::class, $this->embeddingGeneratorMock);
    }

    public function testSearchProductsVectorType()
    {
        $expectedVectorResults = [
            ['primary_key' => 1, 'title' => 'Vector Product 1', 'score' => 0.9],
            ['primary_key' => 2, 'title' => 'Vector Product 2', 'score' => 0.8],
        ];

        $this->vectorDBServiceMock->expects($this->once())
            ->method('searchSimilarProducts')
            ->willReturn($expectedVectorResults);
        $this->vectorDBServiceMock->expects($this->never())
            ->method('keywordSearch');

        $this->client->request(
            'POST',
            '/api/products/search',
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['query' => 'test vector query', 'type' => 'vector'])
        );

        $response = $this->client->getResponse();
        $this->assertResponseIsSuccessful();
        $responseData = json_decode($response->getContent(), true);

        $this->assertTrue($responseData['success']);
        $this->assertEquals('vector', $responseData['type']);
        $this->assertEquals($expectedVectorResults, $responseData['vector_results']);
        $this->assertEmpty($responseData['keyword_results']);
    }

    public function testSearchProductsKeywordType()
    {
        // For the 'keyword' type, the controller directly returns the results from
        // $this->vectorDBService->keywordSearch() under the 'keyword_results' key.
        // To simplify assertion and avoid deep entity serialization issues in the test,
        // we'll have the mock return an array of arrays, simulating serialized objects.
        $expectedKeywordData = [
            ['id' => 10, 'name' => 'Keyword Product 10', 'description' => 'Desc for 10'],
            ['id' => 20, 'name' => 'Keyword Product 20', 'description' => 'Desc for 20'],
        ];

        $this->vectorDBServiceMock->expects($this->once())
            ->method('keywordSearch')
            ->willReturn($expectedKeywordData); // Mock returns array of arrays
        $this->vectorDBServiceMock->expects($this->never())
            ->method('searchSimilarProducts');

        $this->client->request(
            'POST',
            '/api/products/search',
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['query' => 'test keyword query', 'type' => 'keyword'])
        );

        $response = $this->client->getResponse();
        $this->assertResponseIsSuccessful();
        $responseData = json_decode($response->getContent(), true);

        $this->assertTrue($responseData['success']);
        $this->assertEquals('keyword', $responseData['type']);
        $this->assertEquals($expectedKeywordData, $responseData['keyword_results']);
        $this->assertEmpty($responseData['vector_results']);
    }

    public function testSearchProductsHybridType()
    {
        $vectorResults = [
            ['primary_key' => 1, 'title' => 'Hybrid Prod 1 from Vector', 'score' => 0.8],
            ['primary_key' => 3, 'title' => 'Vector Only', 'score' => 0.9],
        ];

        // For hybrid search, combineHybridResults expects Product entities from keywordSearch
        $product1Mock = $this->createMock(Product::class);
        $product1Mock->method('getId')->willReturn(1);
        $product1Mock->method('getName')->willReturn('Hybrid Prod 1 from Keyword');

        $product2Mock = $this->createMock(Product::class);
        $product2Mock->method('getId')->willReturn(2);
        $product2Mock->method('getName')->willReturn('Keyword Only');

        $keywordProductEntities = [$product1Mock, $product2Mock];

        $this->vectorDBServiceMock->method('searchSimilarProducts')->willReturn($vectorResults);
        $this->vectorDBServiceMock->method('keywordSearch')->willReturn($keywordProductEntities);

        $this->client->request(
            'POST',
            '/api/products/search',
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['query' => 'test hybrid query', 'type' => 'hybrid'])
        );

        $response = $this->client->getResponse();
        $this->assertResponseIsSuccessful();
        $responseData = json_decode($response->getContent(), true);

        $this->assertTrue($responseData['success']);
        $this->assertEquals('hybrid', $responseData['type']);
        $this->assertArrayHasKey('products', $responseData);

        $products = $responseData['products'];
        $this->assertCount(3, $products);

        // Expected scores & order:
        // 1. Prod 1 (Hybrid): ID 1, Name 'Hybrid Prod 1 from Keyword', Score (0.8*0.7) + (1.0*0.3) = 0.86
        // 2. Prod 3 (Vector): ID 3, Name 'Vector Only', Score 0.9*0.7 = 0.63
        // 3. Prod 2 (Keyword): ID 2, Name 'Keyword Only', Score 1.0*0.3 = 0.3

        $this->assertEquals(1, $products[0]['id']);
        $this->assertEquals('Hybrid Prod 1 from Keyword', $products[0]['name']);
        $this->assertEqualsWithDelta(0.86, $products[0]['score'], 0.001);
        $this->assertEquals('hybrid', $products[0]['search_type']);

        $this->assertEquals(3, $products[1]['id']);
        $this->assertEquals('Vector Only', $products[1]['name']);
        $this->assertEqualsWithDelta(0.63, $products[1]['score'], 0.001);
        $this->assertEquals('vector', $products[1]['search_type']);

        $this->assertEquals(2, $products[2]['id']);
        $this->assertEquals('Keyword Only', $products[2]['name']);
        $this->assertEqualsWithDelta(0.3, $products[2]['score'], 0.001);
        $this->assertEquals('keyword', $products[2]['search_type']);
    }

    public function testSearchProductsEmptyQuery()
    {
        $this->client->request(
            'POST',
            '/api/products/search',
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['query' => '']) // Empty query
        );

        $response = $this->client->getResponse();
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);

        $this->assertFalse($responseData['success']);
        $this->assertEquals('Query parameter is required', $responseData['message']);
        // Defaults to 'hybrid' type for error response structure
        $this->assertArrayHasKey('products', $responseData);
        $this->assertEmpty($responseData['products']);
    }

    public function testSearchProductsDefaultsToHybridType()
    {
        // Setup similar to hybrid test
        $vectorResults = [
            ['primary_key' => 1, 'title' => 'Default Hybrid Vec', 'score' => 0.75],
        ];

        $product1Mock = $this->createMock(Product::class);
        $product1Mock->method('getId')->willReturn(1);
        $product1Mock->method('getName')->willReturn('Default Hybrid Kw');
        $keywordProductEntities = [$product1Mock];

        $this->vectorDBServiceMock->method('searchSimilarProducts')->willReturn($vectorResults);
        $this->vectorDBServiceMock->method('keywordSearch')->willReturn($keywordProductEntities);

        $this->client->request(
            'POST',
            '/api/products/search',
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['query' => 'test default hybrid']) // No 'type' parameter
        );

        $response = $this->client->getResponse();
        $this->assertResponseIsSuccessful();
        $responseData = json_decode($response->getContent(), true);

        $this->assertTrue($responseData['success']);
        $this->assertEquals('hybrid', $responseData['type']); // Check it defaulted to hybrid
        $this->assertArrayHasKey('products', $responseData);
        $this->assertCount(1, $responseData['products']);

        // Expected score: (0.75 * 0.7) + (1.0 * 0.3) = 0.525 + 0.3 = 0.825
        $this->assertEquals(1, $responseData['products'][0]['id']);
        $this->assertEquals('Default Hybrid Kw', $responseData['products'][0]['name']);
        $this->assertEqualsWithDelta(0.825, $responseData['products'][0]['score'], 0.001);
        $this->assertEquals('hybrid', $responseData['products'][0]['search_type']);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->client = null;
        $this->vectorDBServiceMock = null;
        $this->embeddingGeneratorMock = null;
        // Reset any static properties or services if necessary, though kernel reboot usually handles it.
    }
}
