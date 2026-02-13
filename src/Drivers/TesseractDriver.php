<?php

namespace Mayaram\LaravelOcr\Drivers;

use Mayaram\LaravelOcr\Contracts\OCRDriver;
use thiagoalessio\TesseractOCR\TesseractOCR;
use Intervention\Image\ImageManagerStatic as Image;
use Mayaram\LaravelOcr\Exceptions\OCRException;

class TesseractDriver implements OCRDriver
{
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function extract($document, array $options = []): array
    {
        try {
            $imagePath = $this->prepareDocument($document);
            
            $ocr = new TesseractOCR($imagePath);
            
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
            
            $text = $ocr->run();
            
            $bounds = $this->extractBounds($ocr);
            
            return [
                'text' => $text,
                'confidence' => $this->calculateConfidence($ocr),
                'bounds' => $bounds,
                'metadata' => [
                    'engine' => 'tesseract',
                    'language' => $options['language'] ?? $this->config['language'] ?? 'eng',
                    'processing_time' => microtime(true) - LARAVEL_START
                ]
            ];
        } catch (\Exception $e) {
            throw new OCRException("Tesseract extraction failed: " . $e->getMessage());
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
            'metadata' => $extraction['metadata']
        ];
    }

    public function extractBarcode($document, array $options = []): array
    {
        throw new OCRException("Barcode extraction not supported by Tesseract driver. Use a specialized barcode library.");
    }

    public function extractQRCode($document, array $options = []): array
    {
        throw new OCRException("QR code extraction not supported by Tesseract driver. Use a specialized QR code library.");
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
            $tempPath = sys_get_temp_dir() . '/' . uniqid('ocr_') . '.jpg';
            copy($document, $tempPath);
            return $tempPath;
        }
        
        $extension = strtolower(pathinfo($document, PATHINFO_EXTENSION));
        
        if ($extension === 'pdf') {
            return $this->convertPdfToImage($document);
        }
        
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'tiff', 'bmp'])) {
            return $document;
        }
        
        throw new OCRException("Unsupported file format: {$extension}");
    }

    protected function convertPdfToImage($pdfPath): string
    {
        $imagePath = sys_get_temp_dir() . '/' . uniqid('ocr_') . '.jpg';
        
        $imagick = new \Imagick();
        $imagick->setResolution(300, 300);
        $imagick->readImage($pdfPath . '[0]');
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