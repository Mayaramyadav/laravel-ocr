<?php

namespace Mayaram\LaravelOcr\Services;

use Illuminate\Support\Facades\Http;
use Mayaram\LaravelOcr\Exceptions\AICleanupException;

class AICleanupService
{
    protected $app;
    protected array $config;

    public function __construct($app)
    {
        $this->app = $app;
        $this->config = $app['config']->get('laravel-ocr.ai_cleanup', []);
    }

    public function clean(array $extractedData, array $options = []): array
    {
        $provider = $options['provider'] ?? $this->config['default_provider'] ?? 'openai';
        
        switch ($provider) {
            case 'openai':
                return $this->cleanWithOpenAI($extractedData, $options);
            case 'anthropic':
                return $this->cleanWithAnthropic($extractedData, $options);
            case 'local':
                return $this->cleanWithLocalModel($extractedData, $options);
            default:
                return $this->cleanWithBasicRules($extractedData, $options);
        }
    }

    public function mapFields(array $data, array $mapping): array
    {
        $result = [];
        
        foreach ($mapping as $targetField => $sourceOptions) {
            if (is_string($sourceOptions)) {
                $result[$targetField] = $this->fuzzyExtract($data, $sourceOptions);
            } elseif (is_array($sourceOptions)) {
                $value = null;
                
                foreach ($sourceOptions['alternatives'] ?? [$sourceOptions['field'] ?? ''] as $alternative) {
                    $value = $this->fuzzyExtract($data, $alternative);
                    if ($value !== null) {
                        break;
                    }
                }
                
                if ($value !== null && isset($sourceOptions['transform'])) {
                    $value = $this->applyTransformation($value, $sourceOptions['transform']);
                }
                
                $result[$targetField] = $value ?? ($sourceOptions['default'] ?? null);
            }
        }
        
        return $result;
    }

    public function correctTypos($text): string
    {
        $corrections = $this->getCommonCorrections();
        
        foreach ($corrections as $typo => $correction) {
            $text = preg_replace('/\b' . preg_quote($typo, '/') . '\b/i', $correction, $text);
        }
        
        $text = $this->fixOCRPatterns($text);
        
        return $text;
    }

    public function structureData(array $extractedData, string $documentType = null): array
    {
        $structure = $this->getDocumentStructure($documentType);
        
        if (!$structure) {
            return $this->autoStructure($extractedData);
        }
        
        $result = [];
        
        foreach ($structure as $section => $fields) {
            $result[$section] = [];
            
            foreach ($fields as $field) {
                $value = $this->fuzzyExtract($extractedData, $field['key']);
                
                if ($value !== null) {
                    $result[$section][$field['key']] = [
                        'value' => $value,
                        'type' => $field['type'] ?? 'string',
                        'confidence' => $this->calculateConfidence($value, $field)
                    ];
                }
            }
        }
        
        return $result;
    }

    protected function cleanWithOpenAI(array $data, array $options): array
    {
        if (!isset($this->config['providers']['openai']['api_key'])) {
            throw new AICleanupException('OpenAI API key not configured');
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->config['providers']['openai']['api_key'],
            'Content-Type' => 'application/json',
        ])->post('https://api.openai.com/v1/chat/completions', [
            'model' => $options['model'] ?? 'gpt-3.5-turbo',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->getCleanupPrompt($options)
                ],
                [
                    'role' => 'user',
                    'content' => json_encode($data)
                ]
            ],
            'temperature' => 0.3,
            'response_format' => ['type' => 'json_object']
        ]);

        if (!$response->successful()) {
            throw new AICleanupException('OpenAI API request failed: ' . $response->body());
        }

        $result = $response->json();
        
        return json_decode($result['choices'][0]['message']['content'], true) ?? $data;
    }

    protected function cleanWithBasicRules(array $data, array $options): array
    {
        $cleaned = $data;
        
        if (isset($cleaned['text'])) {
            $cleaned['text'] = $this->correctTypos($cleaned['text']);
        }
        
        if (isset($cleaned['fields'])) {
            foreach ($cleaned['fields'] as $key => &$field) {
                if (is_array($field) && isset($field['value'])) {
                    $field['value'] = $this->cleanFieldValue($field['value'], $field['type'] ?? 'string');
                } elseif (is_string($field)) {
                    $field = $this->cleanFieldValue($field, 'string');
                }
            }
        }
        
        return $cleaned;
    }

    protected function cleanFieldValue($value, $type): string
    {
        $value = trim($value);
        
        switch ($type) {
            case 'number':
            case 'numeric':
                $value = preg_replace('/[^0-9.,\-]/', '', $value);
                $value = str_replace(',', '', $value);
                break;
                
            case 'date':
                $value = $this->normalizeDate($value);
                break;
                
            case 'currency':
                $value = preg_replace('/[^0-9.,\-]/', '', $value);
                $value = number_format((float)str_replace(',', '', $value), 2, '.', '');
                break;
                
            case 'email':
                $value = strtolower(trim($value));
                $value = filter_var($value, FILTER_SANITIZE_EMAIL);
                break;
                
            case 'phone':
                $value = preg_replace('/[^0-9+\-()]/', '', $value);
                break;
        }
        
        return $value;
    }

    protected function fuzzyExtract(array $data, string $key): ?string
    {
        if (isset($data[$key])) {
            return is_array($data[$key]) ? ($data[$key]['value'] ?? null) : $data[$key];
        }
        
        if (isset($data['fields'][$key])) {
            return is_array($data['fields'][$key]) ? ($data['fields'][$key]['value'] ?? null) : $data['fields'][$key];
        }
        
        $normalizedKey = $this->normalizeKey($key);
        
        foreach ($data as $dataKey => $value) {
            if ($this->normalizeKey($dataKey) === $normalizedKey) {
                return is_array($value) ? ($value['value'] ?? null) : $value;
            }
        }
        
        if (isset($data['text'])) {
            $patterns = $this->getFuzzyPatterns($key);
            
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $data['text'], $matches)) {
                    return trim($matches[1]);
                }
            }
        }
        
        return null;
    }

    protected function normalizeKey($key): string
    {
        $key = strtolower($key);
        $key = preg_replace('/[^a-z0-9]/', '', $key);
        
        $replacements = [
            'number' => 'no',
            'num' => 'no',
            'amount' => 'amt',
            'quantity' => 'qty',
            'description' => 'desc',
        ];
        
        foreach ($replacements as $long => $short) {
            $key = str_replace($long, $short, $key);
        }
        
        return $key;
    }

    protected function getFuzzyPatterns($key): array
    {
        $patterns = [];
        $variations = $this->getKeyVariations($key);
        
        foreach ($variations as $variation) {
            $patterns[] = '/' . preg_quote($variation, '/') . '\s*[:=]?\s*([^\n]+)/i';
        }
        
        return $patterns;
    }

    protected function getKeyVariations($key): array
    {
        $variations = [$key];
        
        $variations[] = str_replace('_', ' ', $key);
        $variations[] = str_replace('-', ' ', $key);
        $variations[] = ucwords(str_replace(['_', '-'], ' ', $key));
        
        if (stripos($key, 'number') !== false) {
            $variations[] = str_ireplace('number', 'no', $key);
            $variations[] = str_ireplace('number', '#', $key);
        }
        
        return array_unique($variations);
    }

    protected function fixOCRPatterns($text): string
    {
        $patterns = [
            '/\brn\b/i' => 'm',
            '/\b0\b(?=\s*[a-zA-Z])/i' => 'O',
            '/\bl\b(?=\s*[0-9])/i' => '1',
            '/\bO\b(?=\s*[0-9])/i' => '0',
        ];
        
        foreach ($patterns as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text);
        }
        
        return $text;
    }

    protected function normalizeDate($date): string
    {
        $timestamp = strtotime($date);
        
        if ($timestamp === false) {
            $date = preg_replace('/[^\d\/\-\.]/', '', $date);
            $timestamp = strtotime($date);
        }
        
        return $timestamp !== false ? date('Y-m-d', $timestamp) : $date;
    }

    protected function getCommonCorrections(): array
    {
        return [
            'invOice' => 'invoice',
            'inv0ice' => 'invoice',
            'arnount' => 'amount',
            'arn0unt' => 'amount',
            'nurnber' => 'number',
            'custorner' => 'customer',
            'payrnent' => 'payment',
        ];
    }

    protected function getCleanupPrompt(array $options): string
    {
        $documentType = $options['document_type'] ?? 'general';
        
        return "You are a document data extraction and cleanup assistant. 
                Clean and structure the OCR-extracted data, fixing typos and formatting issues.
                Document type: {$documentType}
                Return a clean, structured JSON object with corrected data.
                Preserve all important information while fixing obvious OCR errors.";
    }

    protected function getDocumentStructure($type): ?array
    {
        $structures = [
            'invoice' => [
                'header' => [
                    ['key' => 'invoice_number', 'type' => 'string'],
                    ['key' => 'invoice_date', 'type' => 'date'],
                    ['key' => 'due_date', 'type' => 'date'],
                ],
                'vendor' => [
                    ['key' => 'vendor_name', 'type' => 'string'],
                    ['key' => 'vendor_address', 'type' => 'string'],
                    ['key' => 'vendor_tax_id', 'type' => 'string'],
                ],
                'customer' => [
                    ['key' => 'customer_name', 'type' => 'string'],
                    ['key' => 'customer_address', 'type' => 'string'],
                    ['key' => 'customer_tax_id', 'type' => 'string'],
                ],
                'items' => [
                    ['key' => 'line_items', 'type' => 'array'],
                ],
                'totals' => [
                    ['key' => 'subtotal', 'type' => 'currency'],
                    ['key' => 'tax', 'type' => 'currency'],
                    ['key' => 'total', 'type' => 'currency'],
                ],
            ],
            'receipt' => [
                'header' => [
                    ['key' => 'store_name', 'type' => 'string'],
                    ['key' => 'store_address', 'type' => 'string'],
                    ['key' => 'receipt_date', 'type' => 'date'],
                    ['key' => 'receipt_number', 'type' => 'string'],
                ],
                'items' => [
                    ['key' => 'line_items', 'type' => 'array'],
                ],
                'payment' => [
                    ['key' => 'subtotal', 'type' => 'currency'],
                    ['key' => 'tax', 'type' => 'currency'],
                    ['key' => 'total', 'type' => 'currency'],
                    ['key' => 'payment_method', 'type' => 'string'],
                ],
            ],
        ];
        
        return $structures[$type] ?? null;
    }

    protected function autoStructure(array $data): array
    {
        return $data;
    }

    protected function applyTransformation($value, $transform): string
    {
        switch ($transform) {
            case 'uppercase':
                return strtoupper($value);
            case 'lowercase':
                return strtolower($value);
            case 'capitalize':
                return ucwords(strtolower($value));
            case 'trim':
                return trim($value);
            default:
                return $value;
        }
    }

    protected function calculateConfidence($value, $field): float
    {
        $confidence = 0.9;
        
        if (empty($value)) {
            return 0.0;
        }
        
        if (isset($field['type'])) {
            switch ($field['type']) {
                case 'date':
                    if (!strtotime($value)) {
                        $confidence -= 0.3;
                    }
                    break;
                case 'email':
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $confidence -= 0.4;
                    }
                    break;
                case 'numeric':
                    if (!is_numeric(str_replace([',', '.'], '', $value))) {
                        $confidence -= 0.3;
                    }
                    break;
            }
        }
        
        return max(0, min(1, $confidence));
    }

    protected function cleanWithAnthropic(array $data, array $options): array
    {
        return $this->cleanWithBasicRules($data, $options);
    }

    protected function cleanWithLocalModel(array $data, array $options): array
    {
        return $this->cleanWithBasicRules($data, $options);
    }
}