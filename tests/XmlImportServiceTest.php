<?php

namespace App\Tests;

use App\Entity\Product;
use App\Service\XmlImportService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class XmlImportServiceTest extends TestCase
{
    private XmlImportService $xmlImportService;
    private SerializerInterface $serializer;
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->validator = $this->createMock(ValidatorInterface::class);
        
        $this->xmlImportService = new XmlImportService(
            $this->serializer,
            $this->validator
        );
    }

    public function testImportFromString(): void
    {
        $xmlContent = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<products>
    <product>
        <id>1</id>
        <name>Test Product</name>
        <sku>TEST-123</sku>
        <description>Test description</description>
        <brand>Test Brand</brand>
        <category>Test Category</category>
        <price>99.99</price>
        <specifications>
            <specification name="color">Black</specification>
            <specification name="weight">200g</specification>
        </specifications>
        <features>
            <feature>Feature 1</feature>
            <feature>Feature 2</feature>
        </features>
    </product>
</products>
XML;

        $products = $this->xmlImportService->importFromString($xmlContent);
        
        $this->assertCount(1, $products);
        $this->assertInstanceOf(Product::class, $products[0]);
        $this->assertEquals(1, $products[0]->getId());
        $this->assertEquals('Test Product', $products[0]->getName());
        $this->assertEquals('TEST-123', $products[0]->getSku());
        $this->assertEquals('Test description', $products[0]->getDescription());
        $this->assertEquals('Test Brand', $products[0]->getBrand());
        $this->assertEquals('Test Category', $products[0]->getCategory());
        $this->assertEquals(99.99, $products[0]->getPrice());
        
        $specifications = $products[0]->getSpecifications();
        $this->assertCount(2, $specifications);
        $this->assertEquals('Black', $specifications['color']);
        $this->assertEquals('200g', $specifications['weight']);
        
        $features = $products[0]->getFeatures();
        $this->assertCount(2, $features);
        $this->assertEquals('Feature 1', $features[0]);
        $this->assertEquals('Feature 2', $features[1]);
    }
}
