<?php

namespace Mayaram\LaravelOcr\Contracts;

interface OCRDriver
{
    public function extract($document, array $options = []): array;
    
    public function extractTable($document, array $options = []): array;
    
    public function extractBarcode($document, array $options = []): array;
    
    public function extractQRCode($document, array $options = []): array;
    
    public function getSupportedLanguages(): array;
    
    public function getSupportedFormats(): array;
}