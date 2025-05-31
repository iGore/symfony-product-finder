<?php

namespace App\Serializer;

use App\Entity\Product;

/**
 * Serializer for converting XML product data to Product objects
 * 
 * This class handles the deserialization of XML product data into Product objects.
 * It maps XML nodes to product properties, handles type conversion, and extracts
 * nested data like specifications and features.
 */
class ProductXmlSerializer
{
    /**
     * Map of XML node names to property types
     * 
     * This constant defines how XML nodes should be mapped to Product properties
     * and what data type each property should be converted to.
     * 
     * @var array<string, array<string, string>> Map of XML node names to property types
     */
    private const PROPERTY_MAPPING = [
        'id' => ['type' => 'int'],
        'name' => ['type' => 'string'],
        'sku' => ['type' => 'string'],
        'description' => ['type' => 'string'],
        'brand' => ['type' => 'string'],
        'category' => ['type' => 'string'],
        'price' => ['type' => 'float'],
        'image_url' => ['type' => 'string'],
        'rating' => ['type' => 'float'],
        'stock' => ['type' => 'int'],
    ];

    /**
     * Create a Product object from XML node
     * 
     * Deserializes a SimpleXMLElement node into a Product object by:
     * 1. Mapping basic properties using the PROPERTY_MAPPING configuration
     * 2. Converting values to the appropriate data types
     * 3. Setting the values on the Product object using explicit setter calls
     * 4. Processing nested elements like specifications and features
     * 
     * @param \SimpleXMLElement $productNode The XML node containing product data
     * @return Product A fully populated Product object
     */
    public function deserialize(\SimpleXMLElement $productNode): Product
    {
        $product = new Product();

        // Map basic properties using the property mapping
        foreach (self::PROPERTY_MAPPING as $xmlNode => $mapping) {
            if (isset($productNode->$xmlNode)) {
                $value = $productNode->$xmlNode;
                $type = $mapping['type'];

                // Cast to the appropriate type
                switch ($type) {
                    case 'int':
                        $value = (int)$value;
                        break;
                    case 'float':
                        $value = (float)$value;
                        break;
                    case 'string':
                    default:
                        $value = (string)$value;
                        break;
                }

                // Use explicit setter calls instead of dynamic method calls
                switch ($xmlNode) {
                    case 'id':
                        $product->setId($value);
                        break;
                    case 'name':
                        $product->setName($value);
                        break;
                    case 'sku':
                        $product->setSku($value);
                        break;
                    case 'description':
                        $product->setDescription($value);
                        break;
                    case 'brand':
                        $product->setBrand($value);
                        break;
                    case 'category':
                        $product->setCategory($value);
                        break;
                    case 'price':
                        $product->setPrice($value);
                        break;
                    case 'image_url':
                        $product->setImageUrl($value);
                        break;
                    case 'rating':
                        $product->setRating($value);
                        break;
                    case 'stock':
                        $product->setStock($value);
                        break;
                }
            }
        }

        // Process specifications
        $product->setSpecifications($this->extractSpecifications($productNode));

        // Process features
        $product->setFeatures($this->extractFeatures($productNode));

        return $product;
    }

    /**
     * Extract specifications from product node
     * 
     * Parses the specifications section of the XML product node and converts it
     * into an associative array of specification name-value pairs.
     * 
     * @param \SimpleXMLElement $productNode The XML node containing product data
     * @return array<string, string> Associative array of specifications (name => value)
     */
    private function extractSpecifications(\SimpleXMLElement $productNode): array
    {
        $specifications = [];
        if (isset($productNode->specifications) && isset($productNode->specifications->specification)) {
            foreach ($productNode->specifications->specification as $spec) {
                $name = (string)$spec->attributes()->name;
                $value = (string)$spec;
                $specifications[$name] = $value;
            }
        }
        return $specifications;
    }

    /**
     * Extract features from product node
     * 
     * Parses the features section of the XML product node and converts it
     * into an indexed array of feature strings.
     * 
     * @param \SimpleXMLElement $productNode The XML node containing product data
     * @return array<int, string> Indexed array of feature strings
     */
    private function extractFeatures(\SimpleXMLElement $productNode): array
    {
        $features = [];
        if (isset($productNode->features) && isset($productNode->features->feature)) {
            foreach ($productNode->features->feature as $feature) {
                $features[] = (string)$feature;
            }
        }
        return $features;
    }
}
