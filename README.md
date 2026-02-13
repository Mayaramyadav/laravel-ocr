# Laravel OCR & Document Data Extractor

A powerful Laravel package for OCR and intelligent document parsing with AI-powered data cleanup, reusable templates, and multi-language support.

**Requires PHP 8.2+ and Laravel 9.0+** (including Laravel 12)

## Features

- **Multi-Driver OCR Support**: Tesseract (offline), Google Vision, AWS Textract, Azure OCR
- **Modern Architecture**: Built with DTOs, Enums, and Strict Typing for robust development
- **Template Matching System**: Create and share reusable document templates
- **AI-Powered Cleanup**: Automatic typo correction and data structuring
- **Multi-Language Support**: Extract text in multiple languages
- **Laravel Native**: Seamless integration with Eloquent, Queues, and Blade (Laravel 12 compatible)
- **Privacy-First**: Full offline capability for sensitive documents
- **Data Extraction**: Automatically extract dates, amounts, emails, phone numbers
- **Document Preview**: Interactive Blade components for reviewing extracted data

## Installation

```bash
composer require mayaram/laravel-ocr
```

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=laravel-ocr-config
```

Run migrations:

```bash
php artisan migrate
```

## Basic Usage

### Simple OCR Extraction (Raw)

```php
use Mayaram\LaravelOcr\Facades\LaravelOcr;

// Extract text as array
$result = LaravelOcr::extract('path/to/document.jpg');
```

### Full Document Parsing (Recommended)

Use the `DocumentParser` to get a structured `OcrResult` object:

```php
use Mayaram\LaravelOcr\Enums\DocumentType;

$parser = app('laravel-ocr.parser');

$result = $parser->parse('invoice.pdf', [
    'auto_detect_template' => true,
    'document_type' => DocumentType::INVOICE
]);

// Access data typesafely
echo $result->text;
echo $result->confidence;
$fields = $result->metadata['fields'];
```

### AI Cleanup

```php
use Mayaram\LaravelOcr\Enums\DocumentType;

$parser = app('laravel-ocr.parser');

$result = $parser->parse('receipt.jpg', [
    'use_ai_cleanup' => true,
    'document_type' => DocumentType::RECEIPT
]);
```

### Creating Templates

```php
$templateManager = app('laravel-ocr.templates');

use Mayaram\LaravelOcr\Enums\DocumentType;

$template = $templateManager->create([
    'name' => 'Standard Invoice',
    'type' => DocumentType::INVOICE,
    'fields' => [
        [
            'key' => 'invoice_number',
            'label' => 'Invoice Number',
            'type' => 'string',
            'pattern' => '/Invoice\s*#?\s*:\s*([A-Z0-9\-]+)/i',
        ],
        [
            'key' => 'total_amount',
            'label' => 'Total Amount',
            'type' => 'currency',
            'pattern' => '/Total\s*:\s*\$?\s*([0-9,.]+)/i',
        ]
    ]
]);
```

### Batch Processing

```php
$documents = [
    'invoice1.pdf',
    'invoice2.jpg',
    'receipt.png'
];

$results = $parser->parseBatch($documents, [
    'use_ai_cleanup' => true,
    'save_to_database' => true
]);
```

## Blade Components

Display extracted document data with the included Blade component:

```blade
<x-laravel-ocr::document-preview
    :document="$processedDocument"
    :show-overlay="true"
    :show-actions="true"
/>
```

## Advanced Configuration

### Configure OCR Drivers

```env
# Tesseract (Default - Offline)
SMART_OCR_DRIVER=tesseract
TESSERACT_LANGUAGE=eng

# Google Vision
SMART_OCR_DRIVER=google_vision
GOOGLE_VISION_KEY_FILE=/path/to/credentials.json
GOOGLE_VISION_PROJECT_ID=your-project-id

# AWS Textract
SMART_OCR_DRIVER=aws_textract
AWS_ACCESS_KEY_ID=your-key
AWS_SECRET_ACCESS_KEY=your-secret
AWS_DEFAULT_REGION=us-east-1

# Azure OCR
SMART_OCR_DRIVER=azure
AZURE_OCR_ENDPOINT=https://your-resource.cognitiveservices.azure.com/
AZURE_OCR_KEY=your-key
```

### Enable AI Cleanup

```env
SMART_OCR_AI_CLEANUP=true
SMART_OCR_AI_PROVIDER=openai
OPENAI_API_KEY=your-openai-key
```

### Queue Processing

```env
SMART_OCR_QUEUE_ENABLED=true
SMART_OCR_QUEUE_NAME=ocr-processing
```

## Workflows

Define custom workflows for specific document types:

```php
// config/laravel-ocr.php
'workflows' => [
    'invoice' => [
        'options' => [
            'use_ai_cleanup' => true,
            'auto_detect_template' => true,
            'extract_tables' => true,
        ],
        'post_processors' => [
            ['class' => 'App\OCR\Processors\InvoiceProcessor'],
        ],
    ],
]

// Usage
$result = $parser->parseWithWorkflow('invoice.pdf', 'invoice');
```

## API Usage

```php
// Field mapping with fuzzy matching
$aiCleanup = app('laravel-ocr.ai-cleanup');
$mapped = $aiCleanup->mapFields($extractedData, [
    'invoice_id' => [
        'alternatives' => ['invoice_number', 'inv_no', 'bill_number'],
        'transform' => 'uppercase'
    ],
    'amount' => [
        'field' => 'total',
        'transform' => 'currency'
    ]
]);
```

## Security

- **Offline Mode**: Use Tesseract for complete data privacy
- **Encryption**: Enable data encryption for stored documents
- **Validation**: Built-in MIME type and file size validation
- **Sanitization**: Automatic input sanitization

## Pro Version

Upgrade to Pro for:

- Advanced AI cleanup with multiple providers
- Access to community template marketplace
- Priority support and updates
- Advanced language packs
- Custom OCR model training

## Testing

Run the test suite with Pest:

```bash
vendor/bin/pest
```
