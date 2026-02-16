# Laravel OCR Package - AI Development Guidelines

## Architecture Overview

This is a **Laravel package** for OCR and intelligent document extraction with a multi-driver architecture, template-based parsing, and AI cleanup capabilities. The package uses Orchestra Testbench for testing.

### Core Components

**Data Flow**: User Input → DocumentParser → OCRManager → Driver Selection → Template Matching → AI Cleanup → Database Storage

- **[OCRManager](../src/Services/OCRManager.php)**: Manager pattern (extends `Illuminate\Support\Manager`) that abstracts driver selection (Tesseract, Google Vision, AWS Textract, Azure). Routes calls to `extract()`, `extractTable()`, `extractBarcode()`, `extractQRCode()`.

- **[DocumentParser](../src/Services/DocumentParser.php)**: Orchestrator service handling the full pipeline: document preparation → OCR extraction → template application → AI cleanup → database persistence. Includes batch processing and error handling with metadata tracking.

- **[TemplateManager](../src/Services/TemplateManager.php)**: Manages document templates for structured extraction using regex patterns. Templates can auto-detect document type and apply field mappings.

- **[AICleanupService](../src/Services/AICleanupService.php)**: Pluggable cleanup with providers (OpenAI, Anthropic, local models). Includes field mapping with fuzzy matching and transformations (e.g., `uppercase`, `currency`).

- **[OCRDriver Interface](../src/Contracts/OCRDriver.php)**: Contract all drivers must implement (`extract`, `extractTable`, `extractBarcode`, `extractQRCode`, `getSupportedLanguages`, `getSupportedFormats`).

### Models & Database

- **[DocumentTemplate](../src/Models/DocumentTemplate.php)**: Stores templates (name, type, layout as JSON, version). Has `fields()` relationship with regex patterns for extraction.
- **[ProcessedDocument](../src/Models/ProcessedDocument.php)**: Persists extracted data with metadata.
- **[TemplateField](../src/Models/TemplateField.php)**: Individual fields within templates with type, pattern, and validation rules.

### Service Registration

All services registered as singletons in [LaravelOcrServiceProvider.php](../src/LaravelOcrServiceProvider.php):

```php
app('laravel-ocr')                  // OCRManager
app('laravel-ocr.parser')           // DocumentParser
app('laravel-ocr.templates')        // TemplateManager
app('laravel-ocr.ai-cleanup')       // AICleanupService
```

## Key Patterns & Conventions

### Template-Based Extraction

Templates use regex patterns with fallback to fuzzy field matching. Example:

```php
'fields' => [
    [
        'key' => 'invoice_number',
        'pattern' => '/Invoice\s*#?\s*:\s*([A-Z0-9\-]+)/i',  // Primary extraction
        'type' => 'string',
    ]
]
```

### Multi-Provider AI Cleanup

The AICleanupService supports pluggable providers (`openai`, `anthropic`, `local`) controlled via config or runtime options:

```php
$aiCleanup->clean($data, ['provider' => 'openai']);
```

### Pipeline Options Pattern

DocumentParser accepts a flexible options array controlling behavior:

- `template_id`: Use specific template
- `auto_detect_template`: Auto-find matching template (default: true)
- `use_ai_cleanup`: Apply AI cleaning (default: false)
- `save_to_database`: Persist result (default: false)
- `document_type`: For AI cleanup context

### Error Handling

DocumentParser catches exceptions and returns structured responses:

```php
['success' => false, 'error' => $message, 'metadata' => [...]]
```

Always check the `success` key before using `data`.

## Testing Strategy

- **[Unit Tests](../tests/Unit/)**: Test individual services (OCRManager, TemplateManager, AICleanupService) in isolation with mocked drivers.
- **[Feature Tests](../tests/Feature/)**: Test DocumentParser integration.
- **[Integration Tests](../tests/Integration/)**: Full workflows with real fixtures in `tests/fixtures/`.

Test setup uses [TestCase.php](../tests/TestCase.php) with RefreshDatabase, in-memory SQLite, and Orchestra Testbench (9.0+ for Laravel 12). **Fixtures** are stored as `.txt` files mimicking OCR output (see `tests/fixtures/invoice-sample.txt`).

### Running Tests

```bash
vendor/bin/phpunit                    # All tests
vendor/bin/phpunit --filter Unit      # Unit suite only
```

Configure test driver in `phpunit.xml` under `LARAVEL_OCR_DRIVER=tesseract`. Laravel 12+ requires PHP 8.2+ and enables strict mode by default (`failOnWarning`, `beStrictAboutTestsThatDoNotTestAnything`).

## Developer Workflows

### Adding a New OCR Driver

1. Create `src/Drivers/YourDriver.php` implementing `OCRDriver` interface
2. Add method `createYourDriverDriver()` in OCRManager following naming pattern
3. Register config in `config/laravel-ocr.php` under `drivers.your_driver`
4. Add integration test to verify compatibility

### Adding Template Field Types

Extend TemplateField model and AICleanupService's transformation system (`applyTransformation()` method). Document new types in config.

### Batch Processing Workflows

Use `DocumentParser::parseBatch($documents, $options)` for queued/bulk extraction. Includes error aggregation and progress tracking.

## Configuration & Environment

Primary config: [config/laravel-ocr.php](../config/laravel-ocr.php)

Key env variables (Tesseract defaults):

```
LARAVEL_OCR_DRIVER=tesseract
TESSERACT_LANGUAGE=eng
LARAVEL_OCR_AI_CLEANUP=true
LARAVEL_OCR_AI_PROVIDER=openai
```

## Critical Concepts for AI Agents

1. **Driver Abstraction**: All OCR providers funnel through OCRManager. Always implement the OCRDriver interface.
2. **Template Versioning**: Templates have `version` field for tracking schema evolution across documents.
3. **Metadata Tracking**: DocumentParser returns extensive metadata (processing_time, template_used, document_type, ai_cleanup_used).
4. **Offline-First Design**: Tesseract (default) enables privacy without cloud dependencies.
5. **Lazy Service Loading**: Services use app container; avoid direct instantiation to ensure proper config binding.
