<?php

namespace App\Tests\Service;

use App\Entity\Product;
use App\Service\ZillizVectorDBService;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Query\Expr;
use HelgeSverre\Milvus\Milvus as MilvusClient;
use PHPUnit\Framework\TestCase;

class ZillizVectorDBServiceTest extends TestCase
{
    private $milvusClientMock;
    private $entityManagerMock;
    private $queryBuilderMock;
    private $queryMock;
    private ZillizVectorDBService $service;

    protected function setUp(): void
    {
        $this->milvusClientMock = $this->createMock(MilvusClient::class);
        $this->entityManagerMock = $this->createMock(EntityManagerInterface::class);
        $this->queryBuilderMock = $this->createMock(QueryBuilder::class);
        $this->queryMock = $this->createMock(AbstractQuery::class);

        $this->entityManagerMock->method('createQueryBuilder')
            ->willReturn($this->queryBuilderMock);

        $this->queryBuilderMock->method('select')->willReturnSelf();
        $this->queryBuilderMock->method('from')->willReturnSelf();
        $this->queryBuilderMock->method('where')->willReturnSelf();
        $this->queryBuilderMock->method('setParameter')->willReturnSelf();
        $this->queryBuilderMock->method('setMaxResults')->willReturnSelf();
        $this->queryBuilderMock->method('getQuery')->willReturn($this->queryMock);

        // General setup for expr() and orX() for tests that don't override this
        // Specific tests can use ->expects(...) to override if needed
        $exprInstanceMock = $this->createMock(Expr::class);
        $this->queryBuilderMock->method('expr')->willReturn($exprInstanceMock);
        $orxInstanceMock = $this->createMock(Expr\Orx::class);
        $exprInstanceMock->method('orX')->willReturn($orxInstanceMock);


        $this->service = new ZillizVectorDBService(
            $this->milvusClientMock,
            $this->entityManagerMock
        );
    }

    public function testKeywordSearchWithOneKeyword()
    {
        $keyword = 'test';
        $limit = 5;
        $expectedProduct = $this->createMock(Product::class);

        // Local mocks for specific expression expectations in this test
        $exprMock = $this->createMock(Expr::class);
        $orxMock = $this->createMock(Expr\Orx::class);

        $this->queryBuilderMock->expects($this->once())
            ->method('select')
            ->with('p')
            ->willReturnSelf();
        $this->queryBuilderMock->expects($this->once())
            ->method('from')
            ->with(Product::class, 'p')
            ->willReturnSelf();

        $this->queryBuilderMock->expects($this->once())->method('expr')->willReturn($exprMock);
        $exprMock->expects($this->once())->method('orX')->willReturn($orxMock);

        $exprMock->expects($this->exactly(2))
            ->method('like')
            ->withConsecutive(
                ['p.name', ':keyword0'],
                ['p.description', ':keyword0']
            )
            ->willReturnOnConsecutiveCalls(
                $this->createMock(Expr\Comparison::class),
                $this->createMock(Expr\Comparison::class)
            );

        $orxMock->expects($this->exactly(2))->method('add');

        $this->queryBuilderMock->expects($this->once())
            ->method('where')
            ->with($orxMock)
            ->willReturnSelf();

        $this->queryBuilderMock->expects($this->once())
            ->method('setParameter')
            ->with(':keyword0', '%' . $keyword . '%')
            ->willReturnSelf();

        $this->queryBuilderMock->expects($this->once())
            ->method('setMaxResults')
            ->with($limit)
            ->willReturnSelf();

        $this->queryMock->expects($this->once())
            ->method('getResult')
            ->willReturn([$expectedProduct]);

        $results = $this->service->keywordSearch($keyword, $limit);

        $this->assertCount(1, $results);
        $this->assertSame($expectedProduct, $results[0]);
    }

    public function testKeywordSearchWithMultipleKeywords()
    {
        $query = 'keyword1 keyword2';
        $keywords = ['keyword1', 'keyword2'];
        $limit = 3;
        $expectedProduct1 = $this->createMock(Product::class);
        $expectedProduct2 = $this->createMock(Product::class);

        // Local mocks for specific expression expectations in this test
        $exprMock = $this->createMock(Expr::class);
        $orxMock = $this->createMock(Expr\Orx::class);

        $this->queryBuilderMock->expects($this->once())->method('expr')->willReturn($exprMock);
        $exprMock->expects($this->once())->method('orX')->willReturn($orxMock);

        $exprMock->expects($this->exactly(4))
            ->method('like')
            ->withConsecutive(
                ['p.name', ':keyword0'],
                ['p.description', ':keyword0'],
                ['p.name', ':keyword1'],
                ['p.description', ':keyword1']
            )
            ->willReturn(new Expr\Comparison('a', Expr\Comparison::LIKE, 'b'));

        $orxMock->expects($this->exactly(4))->method('add');

        $this->queryBuilderMock->expects($this->once())
            ->method('where')
            ->with($orxMock)
            ->willReturnSelf();

        $this->queryBuilderMock->expects($this->exactly(2))
            ->method('setParameter')
            ->withConsecutive(
                [':keyword0', '%' . $keywords[0] . '%'],
                [':keyword1', '%' . $keywords[1] . '%']
            )
            ->willReturnSelf();

        $this->queryBuilderMock->expects($this->once())
            ->method('setMaxResults')
            ->with($limit);

        $this->queryMock->expects($this->once())
            ->method('getResult')
            ->willReturn([$expectedProduct1, $expectedProduct2]);

        $results = $this->service->keywordSearch($query, $limit);

        $this->assertCount(2, $results);
    }

    public function testKeywordSearchReturnsNoResults()
    {
        $query = 'nonexistent';
        // Ensure expr() and orX() are called as part of query building
        $exprMock = $this->createMock(Expr::class);
        $orxMock = $this->createMock(Expr\Orx::class);
        $this->queryBuilderMock->method('expr')->willReturn($exprMock); // Use method() if it might be called but not strictly once
        $exprMock->method('orX')->willReturn($orxMock);


        $this->queryMock->expects($this->once())
            ->method('getResult')
            ->willReturn([]);

        $results = $this->service->keywordSearch($query);
        $this->assertEmpty($results);
    }

    public function testKeywordSearchAppliesLimitCorrectly()
    {
        $query = 'search limit test';
        $customLimit = 7;

        // Ensure expr() and orX() are called as part of query building
        $exprMock = $this->createMock(Expr::class);
        $orxMock = $this->createMock(Expr\Orx::class);
        $this->queryBuilderMock->method('expr')->willReturn($exprMock);
        $exprMock->method('orX')->willReturn($orxMock);


        $this->queryBuilderMock->expects($this->once())
            ->method('setMaxResults')
            ->with($customLimit)
            ->willReturnSelf();

        $this->queryMock->method('getResult')->willReturn([]);

        $this->service->keywordSearch($query, $customLimit);
    }

    public function testKeywordSearchWithEmptyQueryStringReturnsEmptyArray()
    {
        $this->entityManagerMock->expects($this->never())->method('createQueryBuilder');

        $results = $this->service->keywordSearch('');
        $this->assertEmpty($results);

        $results = $this->service->keywordSearch('   ');
        $this->assertEmpty($results);
    }
}
