<?php

namespace Mayaram\LaravelOcr\Drivers;

use Google\Cloud\Vision\V1\AnnotateImageRequest;
use Google\Cloud\Vision\V1\BatchAnnotateImagesRequest;
use Google\Cloud\Vision\V1\Client\ImageAnnotatorClient;
use Google\Cloud\Vision\V1\Feature;
use Google\Cloud\Vision\V1\Image;
use Mayaram\LaravelOcr\Contracts\OCRDriver;
use Mayaram\LaravelOcr\Exceptions\OCRException;

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
            $clientConfig = $this->config;

            // Map 'key_file' or 'json_key' to 'credentials' if present
            if (isset($this->config['key_file'])) {
                $keyFile = $this->config['key_file'];

                // Try to resolve relative path if file doesn't exist
                if (! file_exists($keyFile) && function_exists('base_path') && file_exists(base_path($keyFile))) {
                    $keyFile = base_path($keyFile);
                }

                if (file_exists($keyFile)) {
                    $clientConfig['credentials'] = $keyFile;
                }
            }

            // If key_file didn't work/wasn't provided, try json_key
            if (! isset($clientConfig['credentials']) && isset($this->config['json_key'])) {
                $clientConfig['credentials'] = is_array($this->config['json_key'])
                    ? $this->config['json_key']
                    : json_decode($this->config['json_key'], true);
            }

            $imageAnnotator = new ImageAnnotatorClient($clientConfig);
            $imageContent = file_get_contents($document);

            // Create the request using GAPIC objects
            $image = (new Image)->setContent($imageContent);
            $feature = (new Feature)->setType(Feature\Type::TEXT_DETECTION);

            $request = (new AnnotateImageRequest)
                ->setImage($image)
                ->setFeatures([$feature]);

            // Create the batch request
            $batchRequest = (new BatchAnnotateImagesRequest())
                ->setRequests([$request]);

            // Call the correct method: batchAnnotateImages
            $response = $imageAnnotator->batchAnnotateImages($batchRequest);

            // Validate response
            if ($response->getResponses()->count() === 0) {
                return $this->emptyResult();
            }

            $annotateImageResponse = $response->getResponses()[0];

            if ($annotateImageResponse->hasError()) {
                throw new \Exception($annotateImageResponse->getError()->getMessage());
            }

            $texts = $annotateImageResponse->getTextAnnotations();

            if (count($texts) === 0) {
                return $this->emptyResult();
            }

            // The first annotation contains the entire text
            $fullText = $texts[0]->getDescription();

            $imageAnnotator->close();

            return [
                'text' => $fullText,
                'confidence' => 0.0, // Google Vision API doesn't provide a single confidence score for the whole text
                'bounds' => [], // Parsing bounds omitted for brevity
                'metadata' => [
                    'engine' => 'google_vision',
                    'processing_time' => microtime(true) - LARAVEL_START,
                ],
            ];

        } catch (\Exception $e) {
            \Log::error('Google Vision extraction failed: '.$e->getMessage(), ['exception' => $e]);
            throw new OCRException('Google Vision extraction failed: '.$e->getMessage());
        }
    }

    protected function emptyResult(): array
    {
        return [
            'text' => '',
            'confidence' => 0.0,
            'bounds' => [],
            'metadata' => ['engine' => 'google_vision'],
        ];
    }

    public function extractTable($document, array $options = []): array
    {
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
            'metadata' => $extraction['metadata'],
        ];
    }

    public function extractBarcode($document, array $options = []): array
    {
        throw new OCRException('Barcode extraction not supported by Google Vision driver directly. Use a specialized library.');
    }

    public function extractQRCode($document, array $options = []): array
    {
        throw new OCRException('QR code extraction not supported by Google Vision driver directly. Use a specialized library.');
    }

    public function getSupportedLanguages(): array
    {
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
        if (! class_exists(ImageAnnotatorClient::class)) {
            throw new OCRException(
                'Google Cloud Vision SDK not installed. Please install it via composer: composer require google/cloud-vision'
            );
        }
    }
}
