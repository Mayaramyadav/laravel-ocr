<?php

use Mayaram\LaravelOcr\Services\AICleanupService;
use Laravel\Ai\Ai;

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



test('cleanup with ai sdk using mocked response', function () {
    Ai::fakeAgent(\Mayaram\LaravelOcr\Agents\CleanupAgent::class, [
        '{"fields": {"invoice_number": "INV-001", "total": 1000.00}}'
    ]);
    
    // We don't need config for API key anymore if using fake, but keeping provider config is good practice
    config(['laravel-ocr.ai_cleanup.default_provider' => 'openai']);
    // Actually config is passed in constructor, but tests use binded config.
    // The service uses $this->config->get().
    // We bind config mock usually, but this test uses app() which uses real config? 
    // The beforeEach binds 'laravel-ocr.ai-cleanup' which uses app(AICleanupService::class).
    
    $data = ['text' => 'Invoice #: INV-001\nTotal: $1,000.00'];
    
    $result = $this->aiCleanup->clean($data, ['provider' => 'openai']);

    expect($result)->toHaveKey('fields');
    expect($result['fields']['invoice_number'])->toBe('INV-001');
});