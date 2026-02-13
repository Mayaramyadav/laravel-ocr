<?php

namespace Mayaram\LaravelOcr\Tests\Integration;

use Mayaram\LaravelOcr\Tests\TestCase;
use Mayaram\LaravelOcr\Services\DocumentParser;
use Mayaram\LaravelOcr\Services\TemplateManager;
use Mayaram\LaravelOcr\Services\AICleanupService;
use Mayaram\LaravelOcr\Models\ProcessedDocument;
use Mayaram\LaravelOcr\Facades\LaravelOcr;
use Mockery;

class FullWorkflowTest extends TestCase
{
    public function test_complete_invoice_processing_workflow()
    {
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
                [
                    'key' => 'date',
                    'label' => 'Invoice Date',
                    'type' => 'date',
                    'pattern' => '/Date\s*:\s*([A-Za-z0-9\s,]+)/i',
                ],
            ]
        ]);

        // 2. Mock OCR extraction
        $this->mockOCRService();

        // 3. Process document with full pipeline
        $parser = app('laravel-ocr.parser');
        $result = $parser->parse($this->getSampleDocument('invoice'), [
            'template_id' => $template->id,
            'use_ai_cleanup' => true,
            'save_to_database' => true,
            'user_id' => 1
        ]);

        // 4. Verify processing results
        $this->assertTrue($result['success']);
        $this->assertEquals($template->name, $result['metadata']['template_used']);
        $this->assertTrue($result['metadata']['ai_cleanup_used']);

        // 5. Verify extracted fields
        $this->assertArrayHasKey('invoice_number', $result['data']['fields']);
        $this->assertArrayHasKey('total', $result['data']['fields']);
        $this->assertEquals('INV-2024-001', $result['data']['fields']['invoice_number']['value']);
        
        // 6. Verify database storage
        $document = ProcessedDocument::latest()->first();
        $this->assertNotNull($document);
        $this->assertEquals('invoice', $document->document_type);
        $this->assertEquals($template->id, $document->template_id);
        $this->assertEquals(1, $document->user_id);
    }

    public function test_poor_quality_document_with_ai_cleanup()
    {
        $this->mockOCRService('poor-quality');
        
        $aiCleanup = app('laravel-ocr.ai-cleanup');
        $parser = app('laravel-ocr.parser');

        $result = $parser->parse($this->getSampleDocument('poor-quality'), [
            'use_ai_cleanup' => true,
            'document_type' => 'invoice'
        ]);

        $this->assertTrue($result['success']);
        
        // AI cleanup should have corrected some OCR errors
        $cleanedText = $result['data']['raw_text'] ?? $result['data']['text'];
        $this->assertStringContainsString('ACME Corporation', $cleanedText);
        $this->assertStringContainsString('INVOICE', $cleanedText);
    }

    public function test_batch_processing_with_different_templates()
    {
        $this->mockOCRService();
        
        // Create templates
        $invoiceTemplate = $this->createSampleTemplate('invoice');
        $receiptTemplate = $this->createSampleTemplate('receipt');

        $parser = app('laravel-ocr.parser');
        
        $documents = [
            $this->getSampleDocument('invoice'),
            $this->getSampleDocument('receipt'),
            $this->getSampleDocument('invoice')
        ];

        $results = $parser->parseBatch($documents, [
            'auto_detect_template' => true,
            'use_ai_cleanup' => true,
            'save_to_database' => true
        ]);

        $this->assertCount(3, $results);
        foreach ($results as $result) {
            $this->assertTrue($result['success']);
            $this->assertNotNull($result['metadata']['template_used']);
        }

        // Verify database records
        $this->assertEquals(3, ProcessedDocument::count());
    }

    public function test_template_auto_detection_accuracy()
    {
        $this->mockOCRService();
        
        $invoiceTemplate = $this->createSampleTemplate('invoice');
        $receiptTemplate = $this->createSampleTemplate('receipt');
        $contractTemplate = $this->createSampleTemplate('contract');

        $templateManager = app('laravel-ocr.templates');

        // Test invoice detection
        $invoiceText = file_get_contents($this->getSampleDocument('invoice'));
        $detectedTemplate = $templateManager->findTemplateByContent($invoiceText);
        $this->assertEquals($invoiceTemplate->id, $detectedTemplate->id);

        // Test receipt detection
        $receiptText = file_get_contents($this->getSampleDocument('receipt'));
        $detectedTemplate = $templateManager->findTemplateByContent($receiptText);
        $this->assertEquals($receiptTemplate->id, $detectedTemplate->id);
    }

    public function test_field_extraction_confidence_scoring()
    {
        $this->mockOCRService();
        
        $template = $this->createSampleTemplate('invoice');
        $parser = app('laravel-ocr.parser');

        $result = $parser->parse($this->getSampleDocument('invoice'), [
            'template_id' => $template->id
        ]);

        $this->assertTrue($result['success']);
        
        foreach ($result['data']['fields'] as $field) {
            if (isset($field['confidence'])) {
                $this->assertGreaterThanOrEqual(0, $field['confidence']);
                $this->assertLessThanOrEqual(1, $field['confidence']);
            }
        }
    }

    public function test_error_handling_and_recovery()
    {
        // Test with non-existent file
        $parser = app('laravel-ocr.parser');
        $result = $parser->parse('/non/existent/file.pdf');
        
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);

        // Test with corrupted template
        $result = $parser->parse($this->getSampleDocument('invoice'), [
            'template_id' => 99999 // Non-existent template
        ]);
        
        $this->assertFalse($result['success']);
    }

    public function test_multi_language_support()
    {
        $this->mockOCRService();

        $result = LaravelOcr::extract($this->getSampleDocument('invoice'), [
            'language' => 'spa' // Spanish
        ]);

        $this->assertArrayHasKey('text', $result);
        $this->assertEquals('spa', $result['metadata']['language']);
    }

    public function test_data_validation_and_quality_checks()
    {
        $this->mockOCRService();
        
        $template = $this->createSampleTemplate('invoice');
        $parser = app('laravel-ocr.parser');

        $result = $parser->parse($this->getSampleDocument('invoice'), [
            'template_id' => $template->id
        ]);

        $this->assertTrue($result['success']);
        
        // Check validation results
        foreach ($result['data']['fields'] as $field) {
            if (isset($field['validation'])) {
                $this->assertArrayHasKey('valid', $field['validation']);
                $this->assertArrayHasKey('errors', $field['validation']);
            }
        }
    }

    public function test_performance_metrics()
    {
        $this->mockOCRService();
        
        $parser = app('laravel-ocr.parser');
        $startTime = microtime(true);

        $result = $parser->parse($this->getSampleDocument('invoice'));

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('processing_time', $result['metadata']);
        $this->assertGreaterThan(0, $result['metadata']['processing_time']);
        
        $totalTime = microtime(true) - $startTime;
        $this->assertLessThan(10, $totalTime); // Should complete within 10 seconds
    }

    protected function mockOCRService($type = 'normal')
    {
        $mock = Mockery::mock('Mayaram\LaravelOcr\Services\OCRManager');
        
        switch ($type) {
            case 'poor-quality':
                $mockText = file_get_contents($this->getSampleDocument('poor-quality'));
                break;
            default:
                $mockText = file_get_contents($this->getSampleDocument('invoice'));
        }

        $mock->shouldReceive('extract')
            ->andReturn([
                'text' => $mockText,
                'confidence' => 0.95,
                'bounds' => [],
                'metadata' => [
                    'engine' => 'tesseract',
                    'language' => 'eng',
                    'processing_time' => 0.5
                ]
            ]);

        $this->app->instance('laravel-ocr', $mock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}