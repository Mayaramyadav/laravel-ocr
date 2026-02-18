<?php

use Mayaram\LaravelOcr\Services\DocumentParser;
use Mayaram\LaravelOcr\DTOs\OcrResult;
use Mayaram\LaravelOcr\Models\ProcessedDocument;
use Mayaram\LaravelOcr\Exceptions\DocumentParserException;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;

beforeEach(function () {
    // Reset mocks
    Mockery::close();
    
    // Set dummy API key for testing
    config(['laravel-ocr.ai_cleanup.providers.openai.api_key' => 'test-key']);
    
    // Mock OpenAI API
    // Mock AI Agent
    Ai::fakeAgent(\Mayaram\LaravelOcr\Agents\CleanupAgent::class, [
        json_encode([
            'text' => "Cleaned Invoice\nACME Corporation\nINVOICE #: INV-2024-001",
            'fields' => [
                'invoice_number' => 'INV-2024-001',
                'total' => 1000.00
            ]
        ])
    ]);
});

function mockOCRServiceForParser($test, $type = 'normal') {
    $mock = Mockery::mock('Mayaram\LaravelOcr\Services\OCRManager');
    
    $mockText = "Sample Invoice Text";
    // We need to implement getSampleDocument or simulate it since we are in a closure
    // But $test context has it if we bind it.
    // However, simplest is to just return hardcoded strings for now or use TestCase methods via $test
    
    // Actually, let's look at how we can reuse TestCase helper.
    // In Pest, $this inside test refers to TestCase.
    
    // Let's rely on simple strings for content unless file path is strictly required by DocumentParser.
    // DocumentParser checks file existence.
    // So we need real files or mocked prepareDocument.
    // But prepareDocument is protected.
    
    // We will use a real temporary file.
    $tempFile = tempnam(sys_get_temp_dir(), 'ocr_test_');
    file_put_contents($tempFile, $type === 'poor-quality' ? "Poor Quality Scan\nACME Corporation\nINVOICE" : "Standard Invoice\nInvoice #: INV-2024-001\nTotal: $1,000.00\nDate: Jan 15, 2024");
    
    // We can't easily mock prepareDocument without partial mock of DocumentParser.
    // But we are testing DocumentParser, so we should let it run.
    
    $mock->shouldReceive('extract')
        ->andReturn([
            'text' => file_get_contents($tempFile),
            'confidence' => 0.95,
            'bounds' => [],
            'metadata' => [
                'engine' => 'tesseract',
                'language' => 'eng',
                'processing_time' => 0.5
            ]
        ]);

    app()->instance('laravel-ocr', $mock);
    
    return $tempFile;
}

test('complete invoice processing workflow', function () {
    // 1. Create a template for invoices
    $templateManager = app('laravel-ocr.templates');
    $template = $templateManager->create([
        'name' => 'Standard Invoice Template',
        'type' => 'invoice',
        'fields' => [
            [
                'key' => 'invoice_number',
                'label' => 'Invoice Number',
                'type' => 'string',
                'pattern' => '/Invoice\s*#?\s*:\s*([A-Z0-9\-]+)/i',
                'validators' => ['required' => true]
            ],
            [
                'key' => 'total',
                'label' => 'Total Amount',
                'type' => 'currency',
                'pattern' => '/Total\s*:\s*\$?\s*([0-9,.]+)/i',
                'validators' => ['required' => true, 'type' => 'numeric']
            ],
        ]
    ]);

    // 2. Mock OCR extraction
    $docPath = mockOCRServiceForParser($this);

    // 3. Process document with full pipeline
    $parser = app('laravel-ocr.parser');
    $result = $parser->parse($docPath, [
        'template_id' => $template->id,
        'use_ai_cleanup' => true,
        'save_to_database' => true,
        'user_id' => 1
    ]);

    // 4. Verify processing results
    expect($result)->toBeInstanceOf(OcrResult::class);
    expect($result->metadata['template_used'])->toBe($template->name);
    expect($result->metadata['ai_cleanup_used'])->toBeTrue();

    // 5. Verify extracted fields
    expect($result->metadata['fields'])->toHaveKey('invoice_number');
    expect($result->metadata['fields'])->toHaveKey('total');
    // When using AI cleanup with our mock, fields are direct values
    expect($result->metadata['fields']['invoice_number'])->toBe('INV-2024-001');

    // 6. Verify database storage
    $document = ProcessedDocument::latest()->first();
    expect($document)->not->toBeNull();
    expect($document->document_type)->toBe('invoice');
    expect($document->template_id)->toBe($template->id);
    expect($document->user_id)->toBe(1);
    
    unlink($docPath);
});

test('poor quality document with ai cleanup', function () {
    $docPath = mockOCRServiceForParser($this, 'poor-quality');
    
    $parser = app('laravel-ocr.parser');

    $result = $parser->parse($docPath, [
        'use_ai_cleanup' => true,
        'document_type' => 'invoice'
    ]);

    expect($result)->toBeInstanceOf(OcrResult::class);
    
    // AI cleanup should have corrected some OCR errors (simulated by our mock returning text)
    expect($result->text)->toContain('ACME Corporation');
    expect($result->text)->toContain('INVOICE');
    
    unlink($docPath);
});

test('batch processing with different templates', function () {
    $docPath = mockOCRServiceForParser($this);
    
    // Create templates (simplified)
    $templateManager = app('laravel-ocr.templates');
    $invoiceTemplate = $templateManager->create(['name' => 'Invoice', 'type' => 'invoice']);
    
    $parser = app('laravel-ocr.parser');
    
    $documents = [$docPath, $docPath, $docPath];

    $results = $parser->parseBatch($documents, [
        'auto_detect_template' => true,
        'use_ai_cleanup' => true,
        'save_to_database' => true
    ]);

    expect($results)->toHaveCount(3);
    foreach ($results as $result) {
        expect($result)->toBeInstanceOf(OcrResult::class);
    }

    // Verify database records
    expect(ProcessedDocument::count())->toBe(3);
    
    unlink($docPath);
});

test('error handling and recovery', function () {
    $parser = app('laravel-ocr.parser');
    
    // Test with non-existent file
    // DocumentParser::parse throws exception now
    expect(fn() => $parser->parse('/non/existent/file.pdf'))
        ->toThrow(DocumentParserException::class);
});

test('performance metrics', function () {
    $docPath = mockOCRServiceForParser($this);
    
    $parser = app('laravel-ocr.parser');
    $startTime = microtime(true);

    $result = $parser->parse($docPath);

    expect($result)->toBeInstanceOf(OcrResult::class);
    expect($result->metadata)->toHaveKey('processing_time');
    expect($result->metadata['processing_time'])->toBeGreaterThan(0);
    
    unlink($docPath);
});
