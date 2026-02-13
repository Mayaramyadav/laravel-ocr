<?php

use Mayaram\LaravelOcr\Services\AICleanupService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->aiCleanup = app('laravel-ocr.ai-cleanup');
});

function invokeMethod($object, $methodName, array $parameters = [])
{
    $reflection = new \ReflectionClass(get_class($object));
    $method = $reflection->getMethod($methodName);
    $method->setAccessible(true);

    return $method->invokeArgs($object, $parameters);
}

test('it corrects common typos', function () {
    $text = "This invOice contains an arnount and nurnber for the custorner";
    $corrected = $this->aiCleanup->correctTypos($text);

    expect($corrected)
        ->toContain('invoice')
        ->toContain('amount')
        ->toContain('number')
        ->toContain('customer');
});

test('it cleans field values by type', function () {
    // Test numeric cleaning
    $numeric = invokeMethod($this->aiCleanup, 'cleanFieldValue', ['$1,234.56', 'number']);
    expect($numeric)->toBe('1234.56');

    // Test currency cleaning
    $currency = invokeMethod($this->aiCleanup, 'cleanFieldValue', ['$1,234.567', 'currency']);
    expect($currency)->toBe('1234.57');

    // Test email cleaning
    $email = invokeMethod($this->aiCleanup, 'cleanFieldValue', ['  TEST@EXAMPLE.COM  ', 'email']);
    expect($email)->toBe('test@example.com');

    // Test phone cleaning
    $phone = invokeMethod($this->aiCleanup, 'cleanFieldValue', ['(555) 123-4567', 'phone']);
    expect($phone)->toBe('5551234567');
});

test('it normalizes dates', function () {
    $date1 = invokeMethod($this->aiCleanup, 'normalizeDate', ['01/15/2024']);
    expect($date1)->toBe('2024-01-15');

    $date2 = invokeMethod($this->aiCleanup, 'normalizeDate', ['Jan 15, 2024']);
    expect($date2)->toBe('2024-01-15');

    $date3 = invokeMethod($this->aiCleanup, 'normalizeDate', ['2024-01-15']);
    expect($date3)->toBe('2024-01-15');
});

test('it performs fuzzy field extraction', function () {
    $data = [
        'fields' => [
            'invoice_number' => ['value' => 'INV-001'],
            'customer_name' => 'John Doe',
        ],
        'text' => 'Invoice No: INV-002\nAmount: $500.00'
    ];

    // Test direct field access
    $value1 = invokeMethod($this->aiCleanup, 'fuzzyExtract', [$data, 'invoice_number']);
    expect($value1)->toBe('INV-001');

    // Test fuzzy matching
    $value2 = invokeMethod($this->aiCleanup, 'fuzzyExtract', [$data, 'Invoice Number']);
    expect($value2)->toBe('INV-001');

    // Test pattern extraction from text
    $value3 = invokeMethod($this->aiCleanup, 'fuzzyExtract', [$data, 'amount']);
    expect($value3)->toBe('$500.00');
});

test('it maps fields with alternatives', function () {
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

    expect($result['invoice_id'])->toBe('INV-123');
    expect($result['amount'])->toBe('1000.00');
    expect($result['email'])->toBe('test@example.com');
});

test('it structures data by document type', function () {
    // We need a way to see getDefaultOCRText which was in TestCase.
    // We can just define it here or in Pest.php
    $text = "ACME Corporation\nINVOICE\nInvoice #: INV-2024-001";
    
    $extractedData = [
        'text' => $text,
        'fields' => []
    ];

    $structured = $this->aiCleanup->structureData($extractedData, 'invoice');

    expect($structured)->toHaveKey('header');
    expect($structured)->toHaveKey('vendor');
    expect($structured)->toHaveKey('customer');
    expect($structured)->toHaveKey('totals');
});

test('it applies transformations', function () {
    $value = 'test value';

    $uppercase = invokeMethod($this->aiCleanup, 'applyTransformation', [$value, 'uppercase']);
    expect($uppercase)->toBe('TEST VALUE');

    $lowercase = invokeMethod($this->aiCleanup, 'applyTransformation', [$value, 'lowercase']);
    expect($lowercase)->toBe('test value');

    $capitalize = invokeMethod($this->aiCleanup, 'applyTransformation', ['john doe', 'capitalize']);
    expect($capitalize)->toBe('John Doe');
});

test('it generates key variations', function () {
    $variations = invokeMethod($this->aiCleanup, 'getKeyVariations', ['invoice_number']);

    expect($variations)
        ->toContain('invoice_number')
        ->toContain('invoice number')
        ->toContain('Invoice Number')
        ->toContain('invoice_no');
});

test('it cleans with basic rules', function () {
    $data = [
        'text' => 'invOice arnount: $1,000.00',
        'fields' => [
            'amount' => ['value' => '$1,234.56', 'type' => 'currency'],
            'email' => ['value' => '  TEST@EXAMPLE.COM  ', 'type' => 'email']
        ]
    ];

    $cleaned = $this->aiCleanup->clean($data, ['provider' => 'basic']);

    expect($cleaned['text'])->toContain('invoice');
    expect($cleaned['text'])->toContain('amount');
    expect($cleaned['fields']['amount']['value'])->toBe('1234.56');
    expect($cleaned['fields']['email']['value'])->toBe('test@example.com');
});

test('it calculates confidence scores', function () {
    $field = [
        'type' => 'email'
    ];

    $confidence1 = invokeMethod($this->aiCleanup, 'calculateConfidence', ['test@example.com', $field]);
    expect($confidence1)->toBeGreaterThanOrEqual(0.8);

    $confidence2 = invokeMethod($this->aiCleanup, 'calculateConfidence', ['invalid-email', $field]);
    expect($confidence2)->toBeLessThan(0.8);

    $confidence3 = invokeMethod($this->aiCleanup, 'calculateConfidence', ['', $field]);
    expect($confidence3)->toBe(0.0);
});

test('openai cleanup with mocked response', function () {
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

    expect($result)->toHaveKey('fields');
    expect($result['fields']['invoice_number'])->toBe('INV-001');
});