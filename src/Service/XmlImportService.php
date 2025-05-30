<?php

namespace App\Service;

use App\Entity\Product;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class XmlImportService
{
    private SerializerInterface $serializer;
    private ValidatorInterface $validator;

    public function __construct(
        SerializerInterface $serializer,
        ValidatorInterface $validator
    ) {
        $this->serializer = $serializer;
        $this->validator = $validator;
    }

    /**
     * Import products from XML file
     * 
     * @param string $xmlFilePath Path to XML file
     * @return array Array of Product objects
     */
    public function importFromFile(string $xmlFilePath): array
    {
        if (!file_exists($xmlFilePath)) {
            throw new \InvalidArgumentException("XML file not found: $xmlFilePath");
        }

        $xmlContent = file_get_contents($xmlFilePath);
        return $this->importFromString($xmlContent);
    }

    /**
     * Import products from XML string
     * 
     * @param string $xmlContent XML content
     * @return array Array of Product objects
     */
    public function importFromString(string $xmlContent): array
    {
        $products = [];
        
        // Parse XML
        $xml = new \SimpleXMLElement($xmlContent);
        
        // Process each product node
        foreach ($xml->product as $productNode) {
            $product = $this->createProductFromXmlNode($productNode);
            $products[] = $product;
        }
        
        return $products;
    }

    /**
     * Create a Product object from XML node
     * 
     * @param \SimpleXMLElement $productNode
     * @return Product
     */
    private function createProductFromXmlNode(\SimpleXMLElement $productNode): Product
    {
        $product = new Product();
        
        // Map basic properties
        if (isset($productNode->id)) {
            $product->setId((int)$productNode->id);
        }
        
        if (isset($productNode->name)) {
            $product->setName((string)$productNode->name);
        }
        
        if (isset($productNode->sku)) {
            $product->setSku((string)$productNode->sku);
        }
        
        if (isset($productNode->description)) {
            $product->setDescription((string)$productNode->description);
        }
        
        if (isset($productNode->brand)) {
            $product->setBrand((string)$productNode->brand);
        }
        
        if (isset($productNode->category)) {
            $product->setCategory((string)$productNode->category);
        }
        
        if (isset($productNode->price)) {
            $product->setPrice((float)$productNode->price);
        }
        
        if (isset($productNode->image_url)) {
            $product->setImageUrl((string)$productNode->image_url);
        }
        
        if (isset($productNode->rating)) {
            $product->setRating((float)$productNode->rating);
        }
        
        if (isset($productNode->stock)) {
            $product->setStock((int)$productNode->stock);
        }
        
        // Process specifications
        $specifications = [];
        if (isset($productNode->specifications) && isset($productNode->specifications->specification)) {
            foreach ($productNode->specifications->specification as $spec) {
                $name = (string)$spec->attributes()->name;
                $value = (string)$spec;
                $specifications[$name] = $value;
            }
        }
        $product->setSpecifications($specifications);
        
        // Process features
        $features = [];
        if (isset($productNode->features) && isset($productNode->features->feature)) {
            foreach ($productNode->features->feature as $feature) {
                $features[] = (string)$feature;
            }
        }
        $product->setFeatures($features);
        
        return $product;
    }
}
