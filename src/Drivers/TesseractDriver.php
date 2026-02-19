<?php

namespace Mayaram\LaravelOcr\Drivers;

use Mayaram\LaravelOcr\Contracts\OCRDriver;
use Mayaram\LaravelOcr\Exceptions\OCRException;
use thiagoalessio\TesseractOCR\TesseractOCR;

class TesseractDriver implements OCRDriver
{
    protected array $config;

    protected ?string $pdfExtractedText = null;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function extract($document, array $options = []): array
    {
        try {
            $imagePath = $this->prepareDocument($document);

            // If PDF text was extracted directly via pdfparser, return it without Tesseract
            if ($this->pdfExtractedText !== null) {
                $text = $this->pdfExtractedText;
                $this->pdfExtractedText = null;

                return [
                    'text' => $text,
                    'confidence' => 0.90,
                    'bounds' => [],
                    'metadata' => [
                        'engine' => 'tesseract',
                        'method' => 'pdfparser',
                        'language' => $options['language'] ?? $this->config['language'] ?? 'eng',
                        'processing_time' => microtime(true) - LARAVEL_START,
                    ],
                ];
            }

            $ocr = new TesseractOCR($imagePath);

            // Set the tesseract binary path from config (Herd/Valet have limited $PATH)
            if (! empty($this->config['binary'])) {
                $ocr->executable($this->config['binary']);
            }

            if (isset($options['language'])) {
                $ocr->lang($options['language']);
            } elseif (isset($this->config['language'])) {
                $ocr->lang($this->config['language']);
            }

            if (isset($options['whitelist'])) {
                $ocr->whitelist($options['whitelist']);
            }

            if (isset($options['psm'])) {
                $ocr->psm($options['psm']);
            }

            try {
                $text = $ocr->run();
            } catch (\Exception $e) {
                // Tesseract ran but produced no output (e.g. image has no readable text)
                if (str_contains($e->getMessage(), 'did not produce any output')) {
                    $text = '';
                } else {
                    throw $e;
                }
            }

            $bounds = $this->extractBounds($ocr);

            return [
                'text' => $text,
                'confidence' => $this->calculateConfidence($ocr),
                'bounds' => $bounds,
                'metadata' => [
                    'engine' => 'tesseract',
                    'language' => $options['language'] ?? $this->config['language'] ?? 'eng',
                    'processing_time' => microtime(true) - LARAVEL_START,
                ],
            ];
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            throw new OCRException('Tesseract extraction failed: '.$e->getMessage());
        } finally {
            if (isset($imagePath) && file_exists($imagePath) && $imagePath !== $document) {
                unlink($imagePath);
            }
        }
    }

    public function extractTable($document, array $options = []): array
    {
        $extraction = $this->extract($document, array_merge($options, ['psm' => 6]));

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
        throw new OCRException('Barcode extraction not supported by Tesseract driver. Use a specialized barcode library.');
    }

    public function extractQRCode($document, array $options = []): array
    {
        throw new OCRException('QR code extraction not supported by Tesseract driver. Use a specialized QR code library.');
    }

    public function getSupportedLanguages(): array
    {
        return [
            'eng' => 'English',
            'spa' => 'Spanish',
            'fra' => 'French',
            'deu' => 'German',
            'ita' => 'Italian',
            'por' => 'Portuguese',
            'rus' => 'Russian',
            'jpn' => 'Japanese',
            'kor' => 'Korean',
            'chi_sim' => 'Chinese (Simplified)',
            'chi_tra' => 'Chinese (Traditional)',
            'ara' => 'Arabic',
            'hin' => 'Hindi',
        ];
    }

    public function getSupportedFormats(): array
    {
        return ['jpg', 'jpeg', 'png', 'tiff', 'bmp', 'pdf'];
    }

    protected function prepareDocument($document): string
    {
        if (filter_var($document, FILTER_VALIDATE_URL)) {
            $tempPath = sys_get_temp_dir().'/'.uniqid('ocr_').'.jpg';
            copy($document, $tempPath);

            return $tempPath;
        }

        $extension = strtolower(pathinfo($document, PATHINFO_EXTENSION));

        // If no extension (e.g. PHP temp upload like /tmp/phpXXXXX), detect from MIME type
        if (empty($extension)) {
            $mimeType = mime_content_type($document);
            $mimeToExt = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/tiff' => 'tiff',
                'image/bmp' => 'bmp',
                'image/gif' => 'gif',
                'application/pdf' => 'pdf',
            ];
            $extension = $mimeToExt[$mimeType] ?? '';
        }

        if ($extension === 'pdf') {
            // First try to extract text directly using pdfparser (no Ghostscript needed)
            $pdfText = $this->extractPdfText($document);
            if (! empty(trim($pdfText))) {
                // PDF has extractable text — return it directly via the extract method
                $this->pdfExtractedText = $pdfText;

                return $document; // Will be intercepted in extract()
            }

            // Scanned PDF — convert to image using ImageMagick
            return $this->convertPdfToImage($document);
        }

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'tiff', 'bmp'])) {
            return $document;
        }

        throw new OCRException("Unsupported file format: {$extension}");
    }

    /**
     * Extract text from PDF using smalot/pdfparser.
     */
    protected function extractPdfText(string $pdfPath): string
    {
        try {
            $parser = new \Smalot\PdfParser\Parser;
            $pdf = $parser->parseFile($pdfPath);

            return $pdf->getText();
        } catch (\Exception $e) {
            return '';
        }
    }

    protected function convertPdfToImage($pdfPath): string
    {
        $imagePath = sys_get_temp_dir().'/'.uniqid('ocr_').'.jpg';

        // Ensure Ghostscript can be found (Herd/Valet have limited $PATH)
        $currentPath = getenv('PATH') ?: '';
        if (! str_contains($currentPath, '/opt/homebrew/bin')) {
            putenv("PATH=/opt/homebrew/bin:/usr/local/bin:{$currentPath}");
        }

        $imagick = new \Imagick;
        $imagick->setResolution(300, 300);
        $imagick->readImage($pdfPath.'[0]');
        $imagick->setImageFormat('jpg');
        $imagick->writeImage($imagePath);
        $imagick->clear();
        $imagick->destroy();

        return $imagePath;
    }

    protected function extractBounds($ocr): array
    {
        return [];
    }

    protected function calculateConfidence($ocr): float
    {
        return 0.0;
    }
}
