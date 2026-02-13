# Laravel OCR & Document Data Extractor

A powerful Laravel package that **reads text from images and PDFs automatically**, understands the data, fixes scanning errors with AI, and gives you clean, structured output.

**Requires PHP 8.2+ and Laravel 9.0+** (including Laravel 12)

---

## ✨ What Can This Package Do?

| Feature                 | Description                                                                 |
| ----------------------- | --------------------------------------------------------------------------- |
| 📄 **OCR Extraction**   | Read text from images (JPG, PNG, TIFF, BMP) and PDFs                        |
| 🤖 **AI Cleanup**       | Automatically fix scanning errors and typos using OpenAI or Anthropic       |
| 📋 **Templates**        | Create reusable templates to extract specific fields from documents         |
| 📦 **Batch Processing** | Process hundreds of documents at once                                       |
| 🌍 **Multi-Language**   | Extract text in English, Spanish, French, German, Chinese, Arabic, and more |
| 🔒 **Privacy-First**    | Works 100% offline with Tesseract — no data leaves your server              |
| ⚡ **Queue Support**    | Process documents in the background using Laravel Queues                    |
| 🧩 **Blade Components** | Built-in UI components to preview extracted data                            |

### Supported Document Types

Invoice · Receipt · Contract · Purchase Order · Shipping · General

---

## 🚀 Installation

### Step 1: Install the Package

```bash
composer require mayaram/laravel-ocr
```

### Step 2: Publish Config & Run Migrations

```bash
php artisan vendor:publish --tag=laravel-ocr-config
php artisan migrate
```

That's it! You're ready to go. 🎉

---

## 📖 Usage Guide

### 1. Simple OCR — Extract Raw Text

The quickest way to read text from any document:

```php
use Mayaram\LaravelOcr\Facades\LaravelOcr;

$result = LaravelOcr::extract('path/to/document.jpg');

echo $result['text'];       // The extracted text
echo $result['confidence'];  // Accuracy score (0.95 = 95%)
```

### 2. Smart Parsing — Get Structured Data (Recommended)

Use the `DocumentParser` to automatically detect fields like invoice numbers, amounts, dates, etc:

```php
use Mayaram\LaravelOcr\Enums\DocumentType;

$parser = app('laravel-ocr.parser');

$result = $parser->parse('invoice.pdf', [
    'auto_detect_template' => true,
    'document_type' => DocumentType::INVOICE,
]);

// Access the result as an OcrResult DTO
echo $result->text;                     // Full extracted text
echo $result->confidence;               // Confidence score
$fields = $result->metadata['fields'];  // Extracted fields (invoice_number, total, etc.)
```

### 3. AI Cleanup — Fix Scanning Errors Automatically

Scanned documents often have errors like `1NV01CE` instead of `INVOICE`. AI cleanup fixes them:

```php
$result = $parser->parse('poor-quality-scan.pdf', [
    'use_ai_cleanup' => true,
    'document_type' => DocumentType::RECEIPT,
]);

// "1NV01CE #: 1NV-2024-00l" → "INVOICE #: INV-2024-001" ✅
```

### 4. Templates — Extract Specific Fields Every Time

Create a reusable template once, then use it on all similar documents:

```php
use Mayaram\LaravelOcr\Enums\DocumentType;

$templateManager = app('laravel-ocr.templates');

// Create a template
$template = $templateManager->create([
    'name' => 'My Invoice Template',
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
        ],
    ],
]);

// Use the template on any invoice
$result = LaravelOcr::extractWithTemplate('new-invoice.pdf', $template->id);
```

### 5. Batch Processing — Handle Multiple Documents

Process many documents at once:

```php
$documents = ['invoice1.pdf', 'invoice2.jpg', 'receipt.png'];

$results = $parser->parseBatch($documents, [
    'use_ai_cleanup' => true,
    'save_to_database' => true,
]);

foreach ($results as $result) {
    echo $result['data']['fields']['invoice_number']['value'];
}
```

### 6. Multi-Language Support

Extract text from documents in different languages:

```php
$result = LaravelOcr::extract('spanish-invoice.pdf', [
    'language' => 'spa',  // Spanish
]);

// Supported: eng, spa, fra, deu, chi_sim, ara, and many more
```

### 7. Blade Component — Preview Extracted Data

Display extracted data in your views with the built-in component:

```blade
<x-laravel-ocr::document-preview
    :document="$processedDocument"
    :show-overlay="true"
    :show-actions="true"
/>
```

### 8. API Field Mapping — Fuzzy Matching

Map extracted field names to your own with automatic fuzzy matching:

```php
$aiCleanup = app('laravel-ocr.ai-cleanup');

$mapped = $aiCleanup->mapFields($extractedData, [
    'invoice_id' => [
        'alternatives' => ['invoice_number', 'inv_no', 'bill_number'],
        'transform' => 'uppercase',
    ],
    'amount' => [
        'field' => 'total',
        'transform' => 'currency',
    ],
]);
```

---

## 🎛️ Artisan Commands

Process documents and create templates from the command line:

```bash
# Process a document
php artisan laravel-ocr:process document.pdf --ai-cleanup --save --output=json

# Process with a specific template
php artisan laravel-ocr:process invoice.pdf --template=1 --type=invoice

# Create a template interactively
php artisan laravel-ocr:create-template 'Invoice Template' invoice --interactive
```

---

## ⚙️ Configuration

### OCR Drivers

This package supports 4 OCR engines. Set your preferred driver in `.env`:

```env
# Tesseract (Default — runs offline, no API needed)
SMART_OCR_DRIVER=tesseract
TESSERACT_LANGUAGE=eng

# Google Vision (cloud — high accuracy)
SMART_OCR_DRIVER=google_vision
GOOGLE_VISION_KEY_FILE=/path/to/credentials.json
GOOGLE_VISION_PROJECT_ID=your-project-id

# AWS Textract (cloud)
SMART_OCR_DRIVER=aws_textract
AWS_ACCESS_KEY_ID=your-key
AWS_SECRET_ACCESS_KEY=your-secret
AWS_DEFAULT_REGION=us-east-1

# Azure OCR (cloud)
SMART_OCR_DRIVER=azure
AZURE_OCR_ENDPOINT=https://your-resource.cognitiveservices.azure.com/
AZURE_OCR_KEY=your-key
```

### AI Cleanup

Enable AI-powered error correction:

```env
SMART_OCR_AI_CLEANUP=true
SMART_OCR_AI_PROVIDER=openai        # or 'anthropic'
OPENAI_API_KEY=your-openai-key       # if using OpenAI
ANTHROPIC_API_KEY=your-anthropic-key # if using Anthropic
```

### Queue (Background Processing)

Process documents asynchronously:

```env
SMART_OCR_QUEUE_ENABLED=true
SMART_OCR_QUEUE_NAME=ocr-processing
```

---

## 🔄 Workflows

Define reusable processing pipelines for different document types in `config/laravel-ocr.php`:

```php
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
        'validators' => [
            ['type' => 'required_fields', 'fields' => ['invoice_number', 'total']],
        ],
    ],
],

// Use a workflow
$result = $parser->parseWithWorkflow('invoice.pdf', 'invoice');
```

---

## 🔒 Security

| Feature                | Description                                                          |
| ---------------------- | -------------------------------------------------------------------- |
| **Offline Mode**       | Use Tesseract for complete data privacy — nothing leaves your server |
| **Encryption**         | Enable `SMART_OCR_ENCRYPT_DATA=true` to encrypt stored documents     |
| **File Validation**    | Built-in MIME type and file size checks (default: 10MB max)          |
| **Input Sanitization** | Automatic sanitization of all inputs                                 |
| **Malware Scanning**   | Optional — enable with `SMART_OCR_SCAN_MALWARE=true`                 |

Supported file formats: **JPG, JPEG, PNG, PDF, TIFF, BMP**

---

## 🧪 Testing

Run the test suite with Pest:

```bash
vendor/bin/pest
```

---

## 📂 Package Architecture

```
src/
├── Console/Commands/        # Artisan commands (process, create-template)
├── Contracts/               # Interfaces for extensibility
├── DTOs/                    # OcrResult data transfer object
├── Drivers/                 # OCR engine drivers
├── Enums/                   # DocumentType, OcrDriver enums
├── Exceptions/              # Custom exceptions
├── Facades/                 # LaravelOcr facade
├── Models/                  # DocumentTemplate, ProcessedDocument, TemplateField
└── Services/                # Core services (DocumentParser, OCRManager,
                             #   TemplateManager, AICleanupService)
```

---

## 📄 License

MIT
