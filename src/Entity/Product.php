<?php

namespace App\Entity;

class Product
{
    private ?int $id = null;
    private ?string $name = null;
    private ?string $sku = null;
    private ?string $description = null;
    private ?string $brand = null;
    private ?string $category = null;
    private ?float $price = null;
    private ?array $specifications = [];
    private ?array $features = [];
    private ?string $imageUrl = null;
    private ?float $rating = null;
    private ?int $stock = null;
    private ?array $embeddings = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getSku(): ?string
    {
        return $this->sku;
    }

    public function setSku(?string $sku): self
    {
        $this->sku = $sku;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getBrand(): ?string
    {
        return $this->brand;
    }

    public function setBrand(?string $brand): self
    {
        $this->brand = $brand;
        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): self
    {
        $this->category = $category;
        return $this;
    }

    public function getPrice(): ?float
    {
        return $this->price;
    }

    public function setPrice(?float $price): self
    {
        $this->price = $price;
        return $this;
    }

    public function getSpecifications(): ?array
    {
        return $this->specifications;
    }

    public function setSpecifications(?array $specifications): self
    {
        $this->specifications = $specifications;
        return $this;
    }

    public function getFeatures(): ?array
    {
        return $this->features;
    }

    public function setFeatures(?array $features): self
    {
        $this->features = $features;
        return $this;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(?string $imageUrl): self
    {
        $this->imageUrl = $imageUrl;
        return $this;
    }

    public function getRating(): ?float
    {
        return $this->rating;
    }

    public function setRating(?float $rating): self
    {
        $this->rating = $rating;
        return $this;
    }

    public function getStock(): ?int
    {
        return $this->stock;
    }

    public function setStock(?int $stock): self
    {
        $this->stock = $stock;
        return $this;
    }

    public function getEmbeddings(): ?array
    {
        return $this->embeddings;
    }

    public function setEmbeddings(?array $embeddings): self
    {
        $this->embeddings = $embeddings;
        return $this;
    }

    /**
     * Get all product data as a single text for embedding generation
     */
    public function getTextForEmbedding(): string
    {
        $text = $this->name . ' ' . $this->description . ' ' . $this->brand . ' ' . $this->category;
        
        // Add specifications
        if (!empty($this->specifications)) {
            foreach ($this->specifications as $key => $value) {
                $text .= ' ' . $key . ': ' . $value;
            }
        }
        
        // Add features
        if (!empty($this->features)) {
            $text .= ' Features: ' . implode(', ', $this->features);
        }
        
        return $text;
    }
}
