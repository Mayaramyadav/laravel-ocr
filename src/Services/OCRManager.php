<?php

namespace Mayaram\LaravelOcr\Services;

use Illuminate\Support\Manager;
use Mayaram\LaravelOcr\Drivers\TesseractDriver;
use Mayaram\LaravelOcr\Drivers\GoogleVisionDriver;
use Mayaram\LaravelOcr\Drivers\AWSTextractDriver;
use Mayaram\LaravelOcr\Drivers\AzureOCRDriver;
use Mayaram\LaravelOcr\Contracts\OCRDriver;

class OCRManager extends Manager
{
    protected function createTesseractDriver(): OCRDriver
    {
        return new TesseractDriver($this->config->get('laravel-ocr.drivers.tesseract', []));
    }

    protected function createGoogleVisionDriver(): OCRDriver
    {
        return new GoogleVisionDriver($this->config->get('laravel-ocr.drivers.google_vision', []));
    }

    protected function createAWSTextractDriver(): OCRDriver
    {
        return new AWSTextractDriver($this->config->get('laravel-ocr.drivers.aws_textract', []));
    }

    protected function createAzureDriver(): OCRDriver
    {
        return new AzureOCRDriver($this->config->get('laravel-ocr.drivers.azure', []));
    }


    public function getDefaultDriver()
    {
        return $this->config->get('laravel-ocr.default', 'tesseract');
    }

    public function extract($document, array $options = [])
    {
        return $this->driver()->extract($document, $options);
    }

    public function extractWithTemplate($document, $templateId, array $options = [])
    {
        $rawText = $this->driver()->extract($document, $options);
        $templateManager = app('laravel-ocr.templates');
        
        return $templateManager->applyTemplate($rawText, $templateId);
    }

    public function extractTable($document, array $options = [])
    {
        return $this->driver()->extractTable($document, $options);
    }

    public function extractBarcode($document, array $options = [])
    {
        return $this->driver()->extractBarcode($document, $options);
    }

    public function extractQRCode($document, array $options = [])
    {
        return $this->driver()->extractQRCode($document, $options);
    }
}