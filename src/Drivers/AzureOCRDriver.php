<?php

namespace Mayaram\LaravelOcr\Drivers;

use Mayaram\LaravelOcr\Contracts\OCRDriver;
use Mayaram\LaravelOcr\Exceptions\OCRException;
use GuzzleHttp\Client;

class AzureOCRDriver implements OCRDriver
{
    protected array $config;
    protected Client $client;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function extract($document, array $options = []): array
    {
        $this->ensureClientInitialized();

        try {
            $endpoint = rtrim($this->config['endpoint'] ?? '', '/') . '/vision/v3.2/ocr';
            $key = $this->config['key'] ?? '';

            if (empty($endpoint) || empty($key)) {
                throw new OCRException("Azure OCR endpoint or key is missing in configuration.");
            }

            $response = $this->client->post($endpoint, [
                'headers' => [
                    'Ocp-Apim-Subscription-Key' => $key,
                    'Content-Type' => 'application/octet-stream',
                ],
                'body' => file_get_contents($document),
                'query' => [
                    'language' => $options['language'] ?? 'unk', // 'unk' is auto-detect in Azure
                    'detectOrientation' => 'true',
                ]
            ]);

            $result = json_decode($response->getBody(), true);
            $fullText = '';

            if (isset($result['regions'])) {
                foreach ($result['regions'] as $region) {
                    foreach ($region['lines'] as $line) {
                        foreach ($line['words'] as $word) {
                            $fullText .= $word['text'] . ' ';
                        }
                        $fullText .= "\n";
                    }
                }
            }

            return [
                'text' => trim($fullText),
                'confidence' => 0.0, // Detailed confidence structure omitted
                'bounds' => [], // Detailed bounds structure omitted
                'metadata' => [
                    'engine' => 'azure',
                    'orientation' => $result['orientation'] ?? 'Up',
                    'language' => $result['language'] ?? 'unk',
                    'processing_time' => microtime(true) - LARAVEL_START
                ]
            ];

        } catch (\Exception $e) {
            \Log::error('Azure OCR extraction failed: '.$e->getMessage(), ['exception' => $e]);
            throw new OCRException("Azure OCR extraction failed: " . $e->getMessage());
        }
    }

    public function extractTable($document, array $options = []): array
    {
        // Azure Form Recognizer is better for tables, but this is basic OCR driver.
        // Fallback to text line splitting.
        
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
        throw new OCRException("Barcode extraction not supported by Azure OCR driver directly. Use a specialized library.");
    }

    public function extractQRCode($document, array $options = []): array
    {
        throw new OCRException("QR code extraction not supported by Azure OCR driver directly. Use a specialized library.");
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
            'zh-Hans' => 'Chinese Simplified',
            'ja' => 'Japanese',
            'ko' => 'Korean',
            'ru' => 'Russian',
        ];
    }

    public function getSupportedFormats(): array
    {
        return ['jpg', 'jpeg', 'png', 'bmp', 'pdf', 'tiff'];
    }

    protected function ensureClientInitialized(): void
    {
        if (!isset($this->client)) {
            $this->client = new Client();
        }
    }
}
