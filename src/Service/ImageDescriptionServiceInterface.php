<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Interface for services that generate descriptions for images.
 */
interface ImageDescriptionServiceInterface
{
    /**
     * Generates a textual description for the given image file.
     *
     * @param UploadedFile $imageFile The uploaded image file.
     * @param string|null $prompt An optional prompt to guide the description generation.
     * @return string The generated textual description of the image.
     * @throws \Exception If description generation fails.
     */
    public function generateDescriptionForImage(UploadedFile $imageFile, ?string $prompt = null): string;
}
