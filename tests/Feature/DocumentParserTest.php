<?php

namespace Mayaram\LaravelOcr\Tests\Feature;

use Mayaram\LaravelOcr\Tests\TestCase;
use Mayaram\LaravelOcr\Services\DocumentParser;
use Mayaram\LaravelOcr\Models\ProcessedDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;

class DocumentParserTest extends TestCase
{
    protected $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = app('laravel-ocr.parser');
        Storage::fake('testing');
    }

    public function test_it_can_parse_document_with_default_options()
    {
        $this->mockOCRManager();
        
        $result = $this->parser->parse($this->getSampleDocument());

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('metadata', $result);
        $this->assertArrayHasKey('processing_time', $result['metadata']);
    }

    public function test_it_can_parse_with_template()
    {
        $this->mockOCRManager();
        $template = $this->createSampleTemplate();

        $result = $this->parser->parse($this->getSampleDocument(), [
            'template_id' => $template->id
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals($template->name, $result['metadata']['template_used']);
        $this->assertArrayHasKey('fields', $result['data']);
    }

    public function test_it_auto_detects_template()
    {
        $this->mockOCRManager();
        $template = $this->createSampleTemplate();

        $result = $this->parser->parse($this->getSampleDocument(), [
            'auto_detect_template' => true
        ]);

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['metadata']['template_used']);
    }

    public function test_it_applies_ai_cleanup()
    {
        $this->mockOCRManager();

        $result = $this->parser->parse($this->getSampleDocument(), [
            'use_ai_cleanup' => true
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['metadata']['ai_cleanup_used']);
    }

    public function test_it_saves_to_database()
    {
        $this->mockOCRManager();

        $result = $this->parser->parse($this->getSampleDocument(), [
            'save_to_database' => true,
            'user_id' => 1
        ]);

        $this->assertTrue($result['success']);
        
        $document = ProcessedDocument::latest()->first();
        $this->assertNotNull($document);
        $this->assertEquals(1, $document->user_id);
    }

    public function test_it_handles_uploaded_files()
    {
        $this->mockOCRManager();
        
        $file = UploadedFile::fake()->create('document.pdf', 100);
        
        $result = $this->parser->parse($file);

        $this->assertTrue($result['success']);
    }

    public function test_it_extracts_metadata()
    {
        $tempFile = sys_get_temp_dir() . '/test-doc.txt';
        file_put_contents($tempFile, 'Test content');

        $metadata = $this->parser->extractMetadata($tempFile);

        $this->assertArrayHasKey('file_name', $metadata);
        $this->assertArrayHasKey('file_size', $metadata);
        $this->assertArrayHasKey('mime_type', $metadata);
        $this->assertEquals('test-doc.txt', $metadata['file_name']);

        unlink($tempFile);
    }

    public function test_it_processes_batch_documents()
    {
        $this->mockOCRManager();

        $documents = [
            $this->getSampleDocument(),
            $this->getSampleDocument(),
            $this->getSampleDocument()
        ];

        $results = $this->parser->parseBatch($documents);

        $this->assertCount(3, $results);
        foreach ($results as $result) {
            $this->assertTrue($result['success']);
        }
    }

    public function test_it_detects_document_type()
    {
        $invoiceText = "INVOICE\nInvoice #: INV-001\nBill To: John Doe\nDue Date: 2024-02-15";
        $receiptText = "RECEIPT\nTransaction #: 12345\nCashier: Jane\nThank you for your purchase";

        $invoiceType = $this->invokeMethod($this->parser, 'detectDocumentType', [['text' => $invoiceText]]);
        $receiptType = $this->invokeMethod($this->parser, 'detectDocumentType', [['text' => $receiptText]]);

        $this->assertEquals('invoice', $invoiceType);
        $this->assertEquals('receipt', $receiptType);
    }

    public function test_it_extracts_common_fields()
    {
        $text = "Invoice #: INV-2024-001\n" .
                "Date: 01/15/2024\n" .
                "Email: contact@example.com\n" .
                "Phone: (555) 123-4567\n" .
                "Total: $1,234.56\n" .
                "Website: https://example.com";

        $fields = $this->invokeMethod($this->parser, 'extractCommonFields', [$text, 'invoice']);

        $this->assertArrayHasKey('invoice_number', $fields);
        $this->assertEquals('INV-2024-001', $fields['invoice_number']['value']);
        
        $this->assertArrayHasKey('emails', $fields);
        $this->assertContains('contact@example.com', $fields['emails']);
        
        $this->assertArrayHasKey('phones', $fields);
        $this->assertContains('5551234567', $fields['phones']);
        
        $this->assertArrayHasKey('amounts', $fields);
        $this->assertEquals(1234.56, $fields['amounts'][0]['value']);
    }

    public function test_it_handles_parsing_errors()
    {
        $mock = Mockery::mock('overload:' . OCRManager::class);
        $mock->shouldReceive('extract')
            ->andThrow(new \Exception('OCR failed'));

        $result = $this->parser->parse('non-existent-file.pdf');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_workflow_processing()
    {
        $this->mockOCRManager();
        
        config(['laravel-ocr.workflows.test' => [
            'options' => [
                'use_ai_cleanup' => true,
                'extract_tables' => true
            ],
            'validators' => [
                ['type' => 'required_fields', 'fields' => ['invoice_number']]
            ]
        ]]);

        $result = $this->parser->parseWithWorkflow($this->getSampleDocument(), 'test');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('validation', $result);
    }

    protected function mockOCRManager()
    {
        $mock = Mockery::mock('overload:' . \Mayaram\LaravelOcr\Services\OCRManager::class);
        $mock->shouldReceive('extract')
            ->andReturn($this->mockOCRResponse());
        
        $this->app->instance('laravel-ocr', $mock);
    }

    protected function invokeMethod($object, $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}