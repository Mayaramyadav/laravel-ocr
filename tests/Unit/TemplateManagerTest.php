<?php

namespace Mayaram\LaravelOcr\Tests\Unit;

use Mayaram\LaravelOcr\Tests\TestCase;
use Mayaram\LaravelOcr\Services\TemplateManager;
use Mayaram\LaravelOcr\Models\DocumentTemplate;
use Mayaram\LaravelOcr\Models\TemplateField;

class TemplateManagerTest extends TestCase
{
    protected $templateManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->templateManager = app('laravel-ocr.templates');
    }

    public function test_it_can_create_template()
    {
        $templateData = [
            'name' => 'Test Invoice Template',
            'type' => 'invoice',
            'description' => 'A test template',
            'fields' => [
                [
                    'key' => 'invoice_number',
                    'label' => 'Invoice Number',
                    'type' => 'string',
                    'pattern' => '/Invoice\s*#?\s*:\s*([A-Z0-9\-]+)/i',
                ]
            ]
        ];

        $template = $this->templateManager->create($templateData);

        $this->assertInstanceOf(DocumentTemplate::class, $template);
        $this->assertEquals('Test Invoice Template', $template->name);
        $this->assertEquals('invoice', $template->type);
        $this->assertCount(1, $template->fields);
    }

    public function test_it_can_apply_template_to_extracted_text()
    {
        $template = $this->createSampleTemplate();
        $extractedData = $this->mockOCRResponse();

        $result = $this->templateManager->applyTemplate($extractedData, $template->id);

        $this->assertArrayHasKey('template_id', $result);
        $this->assertArrayHasKey('fields', $result);
        $this->assertEquals($template->id, $result['template_id']);
        
        // Check if fields were extracted
        $this->assertArrayHasKey('invoice_number', $result['fields']);
        $this->assertEquals('INV-2024-001', $result['fields']['invoice_number']['value']);
    }

    public function test_it_can_find_template_by_content()
    {
        $invoiceTemplate = $this->createSampleTemplate('invoice');
        $receiptTemplate = $this->createSampleTemplate('receipt');

        $invoiceText = $this->getDefaultOCRText();
        
        $foundTemplate = $this->templateManager->findTemplateByContent($invoiceText);

        $this->assertNotNull($foundTemplate);
        $this->assertEquals($invoiceTemplate->id, $foundTemplate->id);
    }

    public function test_it_extracts_field_values_with_patterns()
    {
        $template = $this->createSampleTemplate();
        $field = $template->fields->first();
        
        $text = "Invoice #: INV-2024-001\nDate: 01/15/2024";
        
        $value = $this->invokeMethod($this->templateManager, 'extractFieldValue', [$text, $field]);
        
        $this->assertEquals('INV-2024-001', $value);
    }

    public function test_it_generates_label_variations()
    {
        $variations = $this->invokeMethod($this->templateManager, 'generateLabelVariations', ['Invoice Number']);
        
        $this->assertContains('Invoice Number', $variations);
        $this->assertContains('Invoice_Number', $variations);
        $this->assertContains('Invoice-Number', $variations);
        $this->assertContains('invoice number', $variations);
        $this->assertContains('INVOICE NUMBER', $variations);
        $this->assertContains('Invoice No', $variations);
    }

    public function test_it_validates_field_values()
    {
        $field = new TemplateField([
            'key' => 'email',
            'type' => 'email',
            'validators' => [
                'required' => true,
                'type' => 'email'
            ]
        ]);

        $validation1 = $this->invokeMethod($this->templateManager, 'validateField', ['test@example.com', $field]);
        $this->assertTrue($validation1['valid']);
        $this->assertEmpty($validation1['errors']);

        $validation2 = $this->invokeMethod($this->templateManager, 'validateField', ['invalid-email', $field]);
        $this->assertFalse($validation2['valid']);
        $this->assertNotEmpty($validation2['errors']);
    }

    public function test_it_calculates_field_confidence()
    {
        $field = new TemplateField([
            'key' => 'test',
            'validators' => [
                'regex' => '/^[A-Z0-9]+$/',
                'length' => 10
            ]
        ]);

        $confidence1 = $this->invokeMethod($this->templateManager, 'calculateFieldConfidence', ['ABC123DEF0', $field]);
        $this->assertEquals(0.8, $confidence1);

        $confidence2 = $this->invokeMethod($this->templateManager, 'calculateFieldConfidence', ['abc123', $field]);
        $this->assertLessThan(0.8, $confidence2);
    }

    public function test_it_can_export_template()
    {
        $template = $this->createSampleTemplate();
        
        $json = $this->templateManager->exportTemplate($template->id);
        $exported = json_decode($json, true);

        $this->assertEquals($template->name, $exported['name']);
        $this->assertEquals($template->type, $exported['type']);
        $this->assertCount(count($template->fields), $exported['fields']);
    }

    public function test_it_can_import_template()
    {
        $templateData = [
            'name' => 'Imported Template',
            'type' => 'receipt',
            'fields' => [
                ['key' => 'store_name', 'label' => 'Store Name', 'type' => 'string']
            ]
        ];

        $tempFile = sys_get_temp_dir() . '/test-template.json';
        file_put_contents($tempFile, json_encode($templateData));

        $template = $this->templateManager->importTemplate($tempFile);

        $this->assertEquals('Imported Template', $template->name);
        $this->assertEquals('receipt', $template->type);
        
        unlink($tempFile);
    }

    public function test_template_duplicate_functionality()
    {
        $original = $this->createSampleTemplate();
        
        $duplicate = $original->duplicate('Duplicated Template');

        $this->assertEquals('Duplicated Template', $duplicate->name);
        $this->assertEquals($original->type, $duplicate->type);
        $this->assertEquals($original->fields->count(), $duplicate->fields->count());
        $this->assertNotEquals($original->id, $duplicate->id);
    }

    protected function invokeMethod($object, $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }
}