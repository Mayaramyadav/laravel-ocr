# Laravel OCR & Document Intelligence

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mayaram/laravel-ocr.svg?style=flat-square)](https://packagist.org/packages/mayaram/laravel-ocr)
[![Total Downloads](https://img.shields.io/packagist/dt/mayaram/laravel-ocr.svg?style=flat-square)](https://packagist.org/packages/mayaram/laravel-ocr)
[![License](https://img.shields.io/packagist/l/mayaram/laravel-ocr.svg?style=flat-square)](https://packagist.org/packages/mayaram/laravel-ocr)

**Turn any image or PDF into structured, actionable data.**

A powerful, developer-friendly Laravel package that reads text from images and PDFs, understands the content, fixes scanning errors with AI, and delivers clean, structured data directly to your application.

> **Why this package?** Most OCR tools just give you a dump of raw text. This package gives you **objects**, **arrays**, and **confidence scores**. It knows the difference between an Invoice Number and a Phone Number.

---

## ✨ Features

- **🧠 Laravel OCR Engine**: Seamlessly switch between **Tesseract** (offline/privacy-first), **Google Vision**, **AWS Textract**, or **Azure AI** drivers.
- **🤖 AI-Powered Cleanup**: Uses OpenAI or Anthropic to fix OCR typos (e.g., `1NV01CE` -> `INVOICE`) and normalize data formats.
- **📦 Structured Data Objects**: Returns typed `OcrResult` DTOs, not just extraction arrays.
- **📑 Advanced Table Extraction**: specialized algorithms to extract line items, quantities, and prices from complex invoice layouts.
- **🔍 Auto-Classification**: Automatically detects document types (Invoice, Receipt, Contract, Purchase Order, etc.).
- **⚡ Workflows**: Define custom processing pipelines in your config (e.g., "If Invoice -> Extract Tables -> Verify Totals").
- **🎨 Blade Components**: Built-in `x-laravel-ocr::document-preview` component to visualize results with bounding boxes.
- **🔒 Enterprise Security**: Encrypted storage options, malware scanning, and full offline support for sensitive data.

---

## 🚀 Installation

Requires PHP 8.2+ and Laravel 10.0+ (compatible with Laravel 11 & 12).

### 1. Install via Composer

```bash
composer require mayaram/laravel-ocr
```

### 2. Publish Configuration & Assets

```bash
php artisan vendor:publish --tag=laravel-ocr-config
php artisan migrate
```

---

## ⚙️ Configuration

Set your preferred driver and credentials in your `.env` file.

### Offline / Privacy-First (Default)

Calculations are done on your server. No data leaves your infrastructure.

```env
LARAVEL_OCR_DRIVER=tesseract
TESSERACT_BINARY=/usr/bin/tesseract
```

### Cloud Drivers (Higher Accuracy)

```env
# Google Cloud Vision
LARAVEL_OCR_DRIVER=google_vision
GOOGLE_VISION_KEY_FILE=/path/to/service-account.json

# AWS Textract
LARAVEL_OCR_DRIVER=aws_textract
AWS_ACCESS_KEY_ID=your-key
AWS_SECRET_ACCESS_KEY=your-secret
AWS_DEFAULT_REGION=us-east-1
```

### AI Cleanup (Optional but Recommended)

Enable AI to fix scanning errors and structure messy data.

```env
LARAVEL_OCR_AI_CLEANUP=true
LARAVEL_OCR_AI_PROVIDER=openai
OPENAI_API_KEY=sk-...
```

---

## 📖 Usage

### 1. Simple Text Extraction

The `LaravelOcr` facade provides a simple entry point for basic extraction.

```php
use Mayaram\LaravelOcr\Facades\LaravelOcr;

// Extract from a local file, URL, or UploadedFile
$result = LaravelOcr::extract(request()->file('document'));

echo $result['text'];
// "INVOICE #1001..."
```

### 2. Smart Parsing (Structured Data)

For powerful data extraction, use the `DocumentParser`. This returns a rich `OcrResult` DTO.

```php
use Mayaram\LaravelOcr\Enums\DocumentType;

$parser = app('laravel-ocr.parser');

$result = $parser->parse('storage/invoices/inv-2024.pdf', [
    'document_type' => DocumentType::INVOICE,
    'use_ai_cleanup' => true
]);

// 1. Access Clean Data
$invoiceNumber = $result->fields['invoice_number']['value'];
$totalAmount = $result->fields['totals']['total']['amount'];

// 2. Access Metadata
echo $result->confidence; // 0.98
echo $result->metadata['processing_time']; // 1.2s
```

### 3. Working with Line Items & Tables

The package includes an **Advanced Invoice Extractor** capable of parsing complex invoice tables into structured arrays.

```php
$result = $parser->parse($invoicePath, ['extract_advanced_line_items' => true]);

foreach ($result->fields['line_items'] as $item) {
    echo "{$item['description']}: {$item['quantity']} x \${$item['unit_price']} = \${$item['total']}\n";
}
// Output:
// Web Hosting: 12 x $10.00 = $120.00
// Domain Name: 1 x $15.00 = $15.00
```

### 4. Templates

Define reusable templates to target specific fields using Regex patterns. Clean and maintainable.

```php
use Mayaram\LaravelOcr\Facades\LaravelOcr;

// 1. Create a Template
$template = app('laravel-ocr.templates')->create([
    'name' => 'TechCorp Invoice',
    'type' => 'invoice',
    'fields' => [
        [
            'key' => 'order_id',
            'pattern' => '/Order\s*ID:\s*([A-F0-9]+)/i',
            'type' => 'string'
        ]
    ]
]);

// 2. Apply it
$result = LaravelOcr::extractWithTemplate($file, $template->id);
```

### 5. Workflows

Configure processing pipelines in `config/laravel-ocr.php` to standardize how different document types are handled.

```php
// config/laravel-ocr.php
'workflows' => [
    'receipt' => [
        'options' => ['use_ai_cleanup' => true, 'extract_line_items' => true],
        'validators' => [
             ['type' => 'required_fields', 'fields' => ['total', 'date']]
        ]
    ]
],

// Usage
$result = $parser->parseWithWorkflow($file, 'receipt');
```

---

## 🎨 Blade Component

Preview the extracted document and data directly in your UI.

```blade
<x-laravel-ocr::document-preview
    :document="$processedDocument"
    :show-overlay="true"
/>
```

---

## 🧪 Testing

The package relies on **Pest** for testing.

```bash
composer test
```

## 📄 License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
