<?php

namespace Mayaram\LaravelOcr\Tests\Unit;

use Mayaram\LaravelOcr\Tests\TestCase;
use Mayaram\LaravelOcr\Models\DocumentTemplate;
use Mayaram\LaravelOcr\Models\TemplateField;
use Mayaram\LaravelOcr\Models\ProcessedDocument;

class ModelsTest extends TestCase
{
    public function test_document_template_creation()
    {
        $template = DocumentTemplate::create([
            'name' => 'Test Template',
            'type' => 'invoice',
            'description' => 'A test template',
            'layout' => ['header' => 'top', 'footer' => 'bottom'],
            'is_active' => true
        ]);

        $this->assertInstanceOf(DocumentTemplate::class, $template);
        $this->assertEquals('Test Template', $template->name);
        $this->assertEquals('invoice', $template->type);
        $this->assertTrue($template->is_active);
        $this->assertIsArray($template->layout);
    }

    public function test_template_field_creation()
    {
        $template = DocumentTemplate::create([
            'name' => 'Test Template',
            'type' => 'invoice',
        ]);

        $field = TemplateField::create([
            'template_id' => $template->id,
            'key' => 'invoice_number',
            'label' => 'Invoice Number',
            'type' => 'string',
            'pattern' => '/Invoice #: (.+)/',
            'validators' => ['required' => true],
            'order' => 1
        ]);

        $this->assertInstanceOf(TemplateField::class, $field);
        $this->assertEquals('invoice_number', $field->key);
        $this->assertEquals('Invoice Number', $field->label);
        $this->assertTrue($field->validators['required']);
    }

    public function test_template_field_relationships()
    {
        $template = DocumentTemplate::create([
            'name' => 'Test Template',
            'type' => 'invoice',
        ]);

        $field = $template->fields()->create([
            'key' => 'total',
            'label' => 'Total Amount',
            'type' => 'currency',
            'order' => 1
        ]);

        $this->assertEquals($template->id, $field->template_id);
        $this->assertCount(1, $template->fields);
        $this->assertEquals($template->id, $field->template->id);
    }

    public function test_template_field_by_key()
    {
        $template = DocumentTemplate::create([
            'name' => 'Test Template',
            'type' => 'invoice',
        ]);

        $template->fields()->create([
            'key' => 'invoice_number',
            'label' => 'Invoice Number',
            'type' => 'string'
        ]);

        $template->fields()->create([
            'key' => 'total',
            'label' => 'Total',
            'type' => 'currency'
        ]);

        $field = $template->getFieldByKey('invoice_number');
        $this->assertNotNull($field);
        $this->assertEquals('Invoice Number', $field->label);

        $nonExistent = $template->getFieldByKey('non_existent');
        $this->assertNull($nonExistent);
    }

    public function test_template_duplication()
    {
        $template = DocumentTemplate::create([
            'name' => 'Original Template',
            'type' => 'invoice',
        ]);

        $template->fields()->create([
            'key' => 'invoice_number',
            'label' => 'Invoice Number',
            'type' => 'string'
        ]);

        $duplicate = $template->duplicate('Duplicated Template');

        $this->assertNotEquals($template->id, $duplicate->id);
        $this->assertEquals('Duplicated Template', $duplicate->name);
        $this->assertEquals($template->type, $duplicate->type);
        $this->assertEquals($template->fields->count(), $duplicate->fields->count());
        
        $originalField = $template->fields->first();
        $duplicateField = $duplicate->fields->first();
        $this->assertEquals($originalField->key, $duplicateField->key);
        $this->assertNotEquals($originalField->id, $duplicateField->id);
    }

    public function test_processed_document_creation()
    {
        $template = DocumentTemplate::create([
            'name' => 'Test Template',
            'type' => 'invoice',
        ]);

        $document = ProcessedDocument::create([
            'original_filename' => 'test-invoice.pdf',
            'document_type' => 'invoice',
            'extracted_data' => [
                'fields' => [
                    'invoice_number' => ['value' => 'INV-001'],
                    'total' => ['value' => '1000.00']
                ]
            ],
            'template_id' => $template->id,
            'confidence_score' => 0.95,
            'processing_time' => 2.5,
            'user_id' => 1
        ]);

        $this->assertInstanceOf(ProcessedDocument::class, $document);
        $this->assertEquals('test-invoice.pdf', $document->original_filename);
        $this->assertEquals('invoice', $document->document_type);
        $this->assertEquals(0.95, $document->confidence_score);
        $this->assertIsArray($document->extracted_data);
    }

    public function test_processed_document_field_value_helper()
    {
        $document = ProcessedDocument::create([
            'original_filename' => 'test.pdf',
            'document_type' => 'invoice',
            'extracted_data' => [
                'fields' => [
                    'invoice_number' => ['value' => 'INV-001'],
                    'total' => ['value' => '1000.00'],
                    'simple_field' => 'simple_value'
                ]
            ],
            'confidence_score' => 0.9
        ]);

        $this->assertEquals('INV-001', $document->getFieldValue('invoice_number'));
        $this->assertEquals('1000.00', $document->getFieldValue('total'));
        $this->assertEquals('simple_value', $document->getFieldValue('simple_field'));
        $this->assertNull($document->getFieldValue('non_existent'));
    }

    public function test_processed_document_get_all_field_values()
    {
        $document = ProcessedDocument::create([
            'original_filename' => 'test.pdf',
            'document_type' => 'invoice',
            'extracted_data' => [
                'fields' => [
                    'invoice_number' => ['value' => 'INV-001'],
                    'total' => ['value' => '1000.00'],
                    'simple_field' => 'simple_value'
                ]
            ],
            'confidence_score' => 0.9
        ]);

        $values = $document->getAllFieldValues();
        
        $this->assertArrayHasKey('invoice_number', $values);
        $this->assertArrayHasKey('total', $values);
        $this->assertArrayHasKey('simple_field', $values);
        $this->assertEquals('INV-001', $values['invoice_number']);
        $this->assertEquals('1000.00', $values['total']);
        $this->assertEquals('simple_value', $values['simple_field']);
    }

    public function test_processed_document_is_valid()
    {
        $validDocument = ProcessedDocument::create([
            'original_filename' => 'test.pdf',
            'document_type' => 'invoice',
            'extracted_data' => [],
            'status' => 'completed',
            'confidence_score' => 0.8
        ]);

        $invalidDocument = ProcessedDocument::create([
            'original_filename' => 'test2.pdf',
            'document_type' => 'invoice',
            'extracted_data' => [],
            'status' => 'failed',
            'confidence_score' => 0.5
        ]);

        $this->assertTrue($validDocument->isValid());
        $this->assertFalse($invalidDocument->isValid());
    }

    public function test_template_field_validation_rules()
    {
        $field = TemplateField::create([
            'template_id' => 1,
            'key' => 'email',
            'label' => 'Email',
            'type' => 'email',
            'validators' => [
                'required' => true,
                'type' => 'email',
                'regex' => '/^[^\s@]+@[^\s@]+\.[^\s@]+$/'
            ]
        ]);

        $rules = $field->getValidationRules();
        
        $this->assertContains('required', $rules);
        $this->assertContains('email', $rules);
        $this->assertContains('regex:/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $rules);
    }
}