<?php

namespace Mayaram\LaravelOcr\Tests\Unit;

use Mayaram\LaravelOcr\Tests\TestCase;
use Mayaram\LaravelOcr\Services\AICleanupService;
use Illuminate\Support\Facades\Http;

class AICleanupServiceTest extends TestCase
{
    protected $aiCleanup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->aiCleanup = app('laravel-ocr.ai-cleanup');
    }

    public function test_it_corrects_common_typos()
    {
        $text = "This invOice contains an arnount and nurnber for the custorner";
        $corrected = $this->aiCleanup->correctTypos($text);

        $this->assertStringContainsString('invoice', $corrected);
        $this->assertStringContainsString('amount', $corrected);
        $this->assertStringContainsString('number', $corrected);
        $this->assertStringContainsString('customer', $corrected);
    }

    public function test_it_cleans_field_values_by_type()
    {
        // Test numeric cleaning
        $numeric = $this->invokeMethod($this->aiCleanup, 'cleanFieldValue', ['$1,234.56', 'number']);
        $this->assertEquals('1234.56', $numeric);

        // Test currency cleaning
        $currency = $this->invokeMethod($this->aiCleanup, 'cleanFieldValue', ['$1,234.567', 'currency']);
        $this->assertEquals('1234.57', $currency);

        // Test email cleaning
        $email = $this->invokeMethod($this->aiCleanup, 'cleanFieldValue', ['  TEST@EXAMPLE.COM  ', 'email']);
        $this->assertEquals('test@example.com', $email);

        // Test phone cleaning
        $phone = $this->invokeMethod($this->aiCleanup, 'cleanFieldValue', ['(555) 123-4567', 'phone']);
        $this->assertEquals('5551234567', $phone);
    }

    public function test_it_normalizes_dates()
    {
        $date1 = $this->invokeMethod($this->aiCleanup, 'normalizeDate', ['01/15/2024']);
        $this->assertEquals('2024-01-15', $date1);

        $date2 = $this->invokeMethod($this->aiCleanup, 'normalizeDate', ['Jan 15, 2024']);
        $this->assertEquals('2024-01-15', $date2);

        $date3 = $this->invokeMethod($this->aiCleanup, 'normalizeDate', ['2024-01-15']);
        $this->assertEquals('2024-01-15', $date3);
    }

    public function test_it_performs_fuzzy_field_extraction()
    {
        $data = [
            'fields' => [
                'invoice_number' => ['value' => 'INV-001'],
                'customer_name' => 'John Doe',
            ],
            'text' => 'Invoice No: INV-002\nAmount: $500.00'
        ];

        // Test direct field access
        $value1 = $this->invokeMethod($this->aiCleanup, 'fuzzyExtract', [$data, 'invoice_number']);
        $this->assertEquals('INV-001', $value1);

        // Test fuzzy matching
        $value2 = $this->invokeMethod($this->aiCleanup, 'fuzzyExtract', [$data, 'Invoice Number']);
        $this->assertEquals('INV-001', $value2);

        // Test pattern extraction from text
        $value3 = $this->invokeMethod($this->aiCleanup, 'fuzzyExtract', [$data, 'amount']);
        $this->assertEquals('$500.00', $value3);
    }

    public function test_it_maps_fields_with_alternatives()
    {
        $data = [
            'inv_no' => 'INV-123',
            'total_amount' => '1000.00',
            'cust_email' => 'test@example.com'
        ];

        $mapping = [
            'invoice_id' => [
                'alternatives' => ['invoice_number', 'inv_no', 'bill_number'],
                'transform' => 'uppercase'
            ],
            'amount' => [
                'field' => 'total_amount',
                'default' => '0.00'
            ],
            'email' => 'cust_email'
        ];

        $result = $this->aiCleanup->mapFields($data, $mapping);

        $this->assertEquals('INV-123', $result['invoice_id']);
        $this->assertEquals('1000.00', $result['amount']);
        $this->assertEquals('test@example.com', $result['email']);
    }

    public function test_it_structures_data_by_document_type()
    {
        $extractedData = [
            'text' => $this->getDefaultOCRText(),
            'fields' => []
        ];

        $structured = $this->aiCleanup->structureData($extractedData, 'invoice');

        $this->assertArrayHasKey('header', $structured);
        $this->assertArrayHasKey('vendor', $structured);
        $this->assertArrayHasKey('customer', $structured);
        $this->assertArrayHasKey('totals', $structured);
    }

    public function test_it_applies_transformations()
    {
        $value = 'test value';

        $uppercase = $this->invokeMethod($this->aiCleanup, 'applyTransformation', [$value, 'uppercase']);
        $this->assertEquals('TEST VALUE', $uppercase);

        $lowercase = $this->invokeMethod($this->aiCleanup, 'applyTransformation', [$value, 'lowercase']);
        $this->assertEquals('test value', $lowercase);

        $capitalize = $this->invokeMethod($this->aiCleanup, 'applyTransformation', ['john doe', 'capitalize']);
        $this->assertEquals('John Doe', $capitalize);
    }

    public function test_it_generates_key_variations()
    {
        $variations = $this->invokeMethod($this->aiCleanup, 'getKeyVariations', ['invoice_number']);

        $this->assertContains('invoice_number', $variations);
        $this->assertContains('invoice number', $variations);
        $this->assertContains('Invoice Number', $variations);
        $this->assertContains('invoice_no', $variations);
    }

    public function test_it_cleans_with_basic_rules()
    {
        $data = [
            'text' => 'invOice arnount: $1,000.00',
            'fields' => [
                'amount' => ['value' => '$1,234.56', 'type' => 'currency'],
                'email' => ['value' => '  TEST@EXAMPLE.COM  ', 'type' => 'email']
            ]
        ];

        $cleaned = $this->aiCleanup->clean($data, ['provider' => 'basic']);

        $this->assertStringContainsString('invoice', $cleaned['text']);
        $this->assertStringContainsString('amount', $cleaned['text']);
        $this->assertEquals('1234.56', $cleaned['fields']['amount']['value']);
        $this->assertEquals('test@example.com', $cleaned['fields']['email']['value']);
    }

    public function test_it_calculates_confidence_scores()
    {
        $field = [
            'type' => 'email'
        ];

        $confidence1 = $this->invokeMethod($this->aiCleanup, 'calculateConfidence', ['test@example.com', $field]);
        $this->assertGreaterThanOrEqual(0.8, $confidence1);

        $confidence2 = $this->invokeMethod($this->aiCleanup, 'calculateConfidence', ['invalid-email', $field]);
        $this->assertLessThan(0.8, $confidence2);

        $confidence3 = $this->invokeMethod($this->aiCleanup, 'calculateConfidence', ['', $field]);
        $this->assertEquals(0.0, $confidence3);
    }

    public function test_openai_cleanup_with_mocked_response()
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'fields' => [
                                    'invoice_number' => 'INV-001',
                                    'total' => 1000.00
                                ]
                            ])
                        ]
                    ]
                ]
            ], 200)
        ]);

        config(['laravel-ocr.ai_cleanup.providers.openai.api_key' => 'test-key']);

        $data = ['text' => 'Invoice #: INV-001\nTotal: $1,000.00'];
        
        $result = $this->aiCleanup->clean($data, ['provider' => 'openai']);

        $this->assertArrayHasKey('fields', $result);
        $this->assertEquals('INV-001', $result['fields']['invoice_number']);
    }

    protected function invokeMethod($object, $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }
}