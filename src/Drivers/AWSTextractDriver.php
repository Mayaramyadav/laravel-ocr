<?php

namespace Mayaram\LaravelOcr\Drivers;

use Mayaram\LaravelOcr\Contracts\OCRDriver;
use Mayaram\LaravelOcr\Exceptions\OCRException;
use Aws\Textract\TextractClient;

class AWSTextractDriver implements OCRDriver
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
            $client = new TextractClient([
                'version' => 'latest',
                'region' => $this->config['region'] ?? 'us-east-1',
                'credentials' => [
                    'key'    => $this->config['key'] ?? '',
                    'secret' => $this->config['secret'] ?? '',
                ],
            ]);

            $result = $client->detectDocumentText([
                'Document' => [
                    'Bytes' => file_get_contents($document),
                ],
            ]);

            $blocks = $result['Blocks'];
            $fullText = '';
            
            foreach ($blocks as $block) {
                if ($block['BlockType'] === 'LINE') {
                    $fullText .= $block['Text'] . "\n";
                }
            }

            return [
                'text' => trim($fullText),
                'confidence' => 0.0, // AWS returns confidence per block, omitting for brevity
                'bounds' => [],      // AWS returns geometry per block
                'metadata' => [
                    'engine' => 'aws_textract',
                    'processing_time' => microtime(true) - LARAVEL_START
                ]
            ];

        } catch (\Exception $e) {
            throw new OCRException("AWS Textract extraction failed: " . $e->getMessage());
        }
    }

    public function extractTable($document, array $options = []): array
    {
        $this->ensureSdkInstalled();

        try {
            // Textract has analyzeDocument for tables, but it is more expensive and different API call
            // For now, simple fallback
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

        } catch (\Exception $e) {
            throw new OCRException("AWS Textract table extraction failed: " . $e->getMessage());
        }
    }

    public function extractBarcode($document, array $options = []): array
    {
        throw new OCRException("Barcode extraction not supported by AWS Textract driver directly. Use a specialized library.");
    }

    public function extractQRCode($document, array $options = []): array
    {
        throw new OCRException("QR code extraction not supported by AWS Textract driver directly. Use a specialized library.");
    }

    public function getSupportedLanguages(): array
    {
        return [
            'en' => 'English',
            'es' => 'Spanish',
            'de' => 'German',
            'fr' => 'French',
            'it' => 'Italian',
            'pt' => 'Portuguese',
        ];
    }

    public function getSupportedFormats(): array
    {
        return ['jpg', 'jpeg', 'png', 'pdf'];
    }

    protected function ensureSdkInstalled(): void
    {
        if (!class_exists(TextractClient::class)) {
            throw new OCRException(
                "AWS SDK not installed. Please install it via composer: composer require aws/aws-sdk-php"
            );
        }
    }
}
