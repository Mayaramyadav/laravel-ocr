<?php

namespace Mayaram\LaravelOcr\Services;

use Illuminate\Support\Facades\Http;
use Mayaram\LaravelOcr\Exceptions\AICleanupException;
use Mayaram\LaravelOcr\Agents\CleanupAgent;
use Laravel\Ai\Ai;

class AICleanupService
{
    protected \Illuminate\Contracts\Config\Repository $config;

    public function __construct(\Illuminate\Contracts\Config\Repository $config)
    {
        $this->config = $config;
    }

    protected function getConfig(string $key, $default = null)
    {
        return $this->config->get('laravel-ocr.ai_cleanup.' . $key, $default);
    }

    public function clean(array $extractedData, array $options = []): array
    {
        $provider = $options['provider'] ?? $this->getConfig('default_provider', 'openai');
        
        if ($provider === 'basic') {
            return $this->cleanWithBasicRules($extractedData, $options);
        }

        return $this->cleanWithAiSdk($extractedData, array_merge($options, ['provider' => $provider]));
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

    protected function cleanWithAiSdk(array $data, array $options): array
    {
        try {
            $agent = new CleanupAgent($options['document_type'] ?? 'general');
            
            $provider = $options['provider'] === 'local' ? 'ollama' : $options['provider'];
            $model = $options['model'] ?? null;

            $response = $agent->prompt(
                prompt: json_encode($data),
                provider: $provider,
                model: $model,
            );
            
            $text = $response->text;
            
            if (preg_match('/```json\s*(\{.*\})\s*```/s', $text, $matches)) {
                $text = $matches[1];
            } elseif (preg_match('/(\{.*\})/s', $text, $matches)) {
                $text = $matches[1];
            }
            
            return json_decode($text, true) ?? $data;
        } catch (\Exception $e) {
            throw new AICleanupException('AI cleanup failed: ' . $e->getMessage(), 0, $e);
        }
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
                $value = preg_replace('/[^0-9]/', '', $value);
                break;
        }
        
        return $value;
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
}
