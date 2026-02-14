<?php

namespace Mayaram\LaravelOcr\Drivers;

use Mayaram\LaravelOcr\Contracts\OCRDriver;
use Mayaram\LaravelOcr\Exceptions\OCRException;
use Google\Cloud\Vision\V1\ImageAnnotatorClient;

class GoogleVisionDriver implements OCRDriver
{
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function extract($document, array $options = []): array
    {
        $this->ensureSdkInstalled();

        try {
            $imageAnnotator = new ImageAnnotatorClient($this->config);
            $imageContent = file_get_contents($document);
            $response = $imageAnnotator->textDetection($imageContent);
            $texts = $response->getTextAnnotations();

            if (empty($texts)) {
                return [
                    'text' => '',
                    'confidence' => 0.0,
                    'bounds' => [],
                    'metadata' => ['engine' => 'google_vision']
                ];
            }

            // The first annotation contains the entire text
            $fullText = $texts[0]->getDescription();
            
            // Calculate average confidence if available (Google Vision doesn't always provide simple confidence for full text)
            // We can iterate over pages/blocks to get confidence, but for now we'll simplify.
            
            $imageAnnotator->close();

            return [
                'text' => $fullText,
                'confidence' => 0.0, // Google Vision API structure is complex for single confidence score
                'bounds' => [], // Parsing bounds is complex, leaving empty for now
                'metadata' => [
                    'engine' => 'google_vision',
                    'processing_time' => microtime(true) - LARAVEL_START
                ]
            ];

        } catch (\Exception $e) {
            throw new OCRException("Google Vision extraction failed: " . $e->getMessage());
        }
    }

    public function extractTable($document, array $options = []): array
    {
        // Google Vision doesn't have a dedicated "Table" extraction in the basic TextDetection.
        // It returns blocks/paragraphs.
        // For now, we fall back to raw text extraction or throw specific exception if strict table needed.
        // Implementation similar to Tesseract's simple line splitting for now.
        
        $extraction = $this->extract($document, $options);
        $lines = explode("\n", $extraction['text']);
        $table = [];

        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $cells = preg_split('/\s{2,}|\t/', $line);
                $table[] = array_map('trim', $cells);
            }
        }

        return [
            'table' => $table,
            'raw_text' => $extraction['text'],
            'metadata' => $extraction['metadata']
        ];
    }

    public function extractBarcode($document, array $options = []): array
    {
        throw new OCRException("Barcode extraction not supported by Google Vision driver directly. Use a specialized library.");
    }

    public function extractQRCode($document, array $options = []): array
    {
        throw new OCRException("QR code extraction not supported by Google Vision driver directly. Use a specialized library.");
    }

    public function getSupportedLanguages(): array
    {
        // Google Vision supports auto-detection, but here are some common ones
        return [
            'en' => 'English',
            'es' => 'Spanish',
            'fr' => 'French',
            'de' => 'German',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'zh' => 'Chinese',
            'ja' => 'Japanese',
            'ko' => 'Korean',
            'ru' => 'Russian',
            'hi' => 'Hindi',
            'ar' => 'Arabic',
        ];
    }

    public function getSupportedFormats(): array
    {
        return ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'ico', 'pdf', 'tiff'];
    }

    protected function ensureSdkInstalled(): void
    {
        if (!class_exists(ImageAnnotatorClient::class)) {
            throw new OCRException(
                "Google Cloud Vision SDK not installed. Please install it via composer: composer require google/cloud-vision"
            );
        }
    }
}
