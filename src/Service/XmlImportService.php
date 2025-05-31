<?php

namespace App\Service;

use App\Entity\Product;
use App\Serializer\ProductXmlSerializer;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;

class XmlImportService
{
    private ValidatorInterface $validator;
    private ProductXmlSerializer $productXmlSerializer;

    public function __construct(
        ValidatorInterface $validator,
        ProductXmlSerializer $productXmlSerializer
    ) {
        $this->validator = $validator;
        $this->productXmlSerializer = $productXmlSerializer;
    }

    /**
     * Import products from XML file
     * 
     * @param string $xmlFilePath Path to XML file
     * @return array<Product> Array of validated Product objects
     * @throws \InvalidArgumentException If the file doesn't exist
     * @throws \RuntimeException If the XML is invalid or products fail validation
     */
    public function importFromFile(string $xmlFilePath): array
    {
        if (!file_exists($xmlFilePath)) {
            throw new \InvalidArgumentException("XML file not found: $xmlFilePath");
        }

        $xmlContent = file_get_contents($xmlFilePath);
        if ($xmlContent === false) {
            throw new \RuntimeException("Failed to read XML file: $xmlFilePath");
        }

        return $this->importFromString($xmlContent);
    }

    /**
     * Import products from XML string
     * 
     * @param string $xmlContent XML content
     * @return array<Product> Array of validated Product objects
     * @throws \RuntimeException If the XML is invalid or products fail validation
     */
    public function importFromString(string $xmlContent): array
    {
        try {
            $products = [];

            // Enable user error handling for libxml
            libxml_use_internal_errors(true);

            // Attempt to parse XML
            $xml = simplexml_load_string($xmlContent, \SimpleXMLElement::class, LIBXML_NOERROR);

            // Check for XML parsing errors
            $errors = libxml_get_errors();
            libxml_clear_errors();

            if ($xml === false || !empty($errors)) {
                throw new \RuntimeException('Invalid XML format');
            }

            // Process each product node
            foreach ($xml->product as $productNode) {
                $product = $this->productXmlSerializer->deserialize($productNode);
                $this->validateProduct($product);
                $products[] = $product;
            }

            return $products;
        } catch (\Exception $e) {
            throw new \RuntimeException("Error processing XML: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate a product using the validator
     * 
     * @param Product $product
     * @throws \RuntimeException If validation fails
     */
    private function validateProduct(Product $product): void
    {
        $violations = $this->validator->validate($product);

        if (count($violations) > 0) {
            throw new \RuntimeException($this->formatViolations($violations));
        }
    }

    /**
     * Format validation violations into a readable string
     * 
     * @param ConstraintViolationListInterface $violations
     * @return string
     */
    private function formatViolations(ConstraintViolationListInterface $violations): string
    {
        $errors = [];
        foreach ($violations as $violation) {
            $errors[] = sprintf(
                '%s: %s',
                $violation->getPropertyPath(),
                $violation->getMessage()
            );
        }

        return "Product validation failed: " . implode(', ', $errors);
    }
}
