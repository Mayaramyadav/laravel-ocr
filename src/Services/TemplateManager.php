<?php

namespace Mayaram\LaravelOcr\Services;

use Mayaram\LaravelOcr\Models\DocumentTemplate;
use Mayaram\LaravelOcr\Models\TemplateField;

class TemplateManager
{
    // protected $app;

    // public function __construct($app)
    // {
    //     $this->app = $app;
    // }

    public function create(array $data): DocumentTemplate
    {
        $template = DocumentTemplate::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'],
            'layout' => $data['layout'] ?? [],
            'is_active' => $data['is_active'] ?? true,
        ]);

        if (isset($data['fields']) && is_array($data['fields'])) {
            foreach ($data['fields'] as $field) {
                $template->fields()->create($field);
            }
        }

        return $template->load('fields');
    }

    public function applyTemplate($extractedData, $templateId): array
    {
        $template = DocumentTemplate::with('fields')->findOrFail($templateId);

        $text = is_array($extractedData) ? $extractedData['text'] : $extractedData;

        $result = [
            'template_id' => $template->id,
            'template_name' => $template->name,
            'document_type' => $template->type,
            'fields' => [],
            'raw_text' => $text,
            'metadata' => [
                'processing_time' => microtime(true) - (defined('LARAVEL_START') ? LARAVEL_START : microtime(true)),
                'template_version' => $template->version ?? '1.0',
            ],
        ];

        foreach ($template->fields as $field) {
            $value = $this->extractFieldValue($text, $field);

            $result['fields'][$field->key] = [
                'value' => $value,
                'label' => $field->label,
                'type' => $field->type,
                'confidence' => $this->calculateFieldConfidence($value, $field),
                'validation' => $this->validateField($value, $field),
            ];
        }

        return $result;
    }

    public function findTemplateByContent($text): ?DocumentTemplate
    {
        try {
            $templates = DocumentTemplate::where('is_active', true)->get();
        } catch (\Exception $e) {
            // Table may not exist if migrations haven't been run — fail gracefully
            \Log::warning('OCR templates table not available: '.$e->getMessage());

            return null;
        }

        $bestMatch = null;
        $bestScore = 0;

        foreach ($templates as $template) {
            $score = $this->calculateTemplateMatchScore($text, $template);

            if ($score > $bestScore && $score > 0.7) {
                $bestScore = $score;
                $bestMatch = $template;
            }
        }

        return $bestMatch;
    }

    public function importTemplate($filePath): DocumentTemplate
    {
        $data = json_decode(file_get_contents($filePath), true);

        if (! $data || ! isset($data['name']) || ! isset($data['type'])) {
            throw new \InvalidArgumentException('Invalid template file format');
        }

        return $this->create($data);
    }

    public function exportTemplate($templateId): string
    {
        $template = DocumentTemplate::with('fields')->findOrFail($templateId);

        $export = [
            'name' => $template->name,
            'description' => $template->description,
            'type' => $template->type,
            'layout' => $template->layout,
            'fields' => $template->fields->map(function ($field) {
                return [
                    'key' => $field->key,
                    'label' => $field->label,
                    'type' => $field->type,
                    'pattern' => $field->pattern,
                    'position' => $field->position,
                    'validators' => $field->validators,
                    'default_value' => $field->default_value,
                ];
            })->toArray(),
        ];

        return json_encode($export, JSON_PRETTY_PRINT);
    }

    protected function extractFieldValue($text, TemplateField $field): ?string
    {
        if ($field->pattern) {
            if (preg_match($field->pattern, $text, $matches)) {
                return trim($matches[1] ?? $matches[0]);
            }
        }

        if ($field->position && is_array($field->position)) {
            $lines = explode("\n", $text);

            if (isset($field->position['line']) && isset($lines[$field->position['line']])) {
                $line = $lines[$field->position['line']];

                if (isset($field->position['start']) && isset($field->position['end'])) {
                    return trim(substr($line, $field->position['start'], $field->position['end'] - $field->position['start']));
                }

                return trim($line);
            }
        }

        $searchPatterns = $this->getFieldSearchPatterns($field);

        foreach ($searchPatterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                return trim($matches[1]);
            }
        }

        return $field->default_value;
    }

    protected function getFieldSearchPatterns(TemplateField $field): array
    {
        $patterns = [];

        $labelVariations = $this->generateLabelVariations($field->label);

        foreach ($labelVariations as $variation) {
            $patterns[] = '/'.preg_quote($variation, '/').'\s*:?\s*([^\n]+)/i';
        }

        return $patterns;
    }

    protected function generateLabelVariations($label): array
    {
        $variations = [$label];

        $variations[] = str_replace(' ', '_', $label);
        $variations[] = str_replace(' ', '-', $label);
        $variations[] = strtolower($label);
        $variations[] = strtoupper($label);

        if (strpos($label, 'Number') !== false) {
            $variations[] = str_replace('Number', 'No', $label);
            $variations[] = str_replace('Number', '#', $label);
        }

        return array_unique($variations);
    }

    protected function calculateFieldConfidence($value, TemplateField $field): float
    {
        if (! $value) {
            return 0.0;
        }

        $confidence = 0.8;

        if ($field->validators) {
            $validators = is_string($field->validators) ? json_decode($field->validators, true) : $field->validators;

            if (isset($validators['regex']) && ! preg_match($validators['regex'], $value)) {
                $confidence -= 0.3;
            }

            if (isset($validators['length']) && strlen($value) != $validators['length']) {
                $confidence -= 0.2;
            }
        }

        return max(0, min(1, $confidence));
    }

    protected function validateField($value, TemplateField $field): array
    {
        $validation = ['valid' => true, 'errors' => []];

        if (! $field->validators) {
            return $validation;
        }

        $validators = is_string($field->validators) ? json_decode($field->validators, true) : $field->validators;

        if (isset($validators['required']) && $validators['required'] && ! $value) {
            $validation['valid'] = false;
            $validation['errors'][] = 'Field is required';
        }

        if ($value && isset($validators['regex']) && ! preg_match($validators['regex'], $value)) {
            $validation['valid'] = false;
            $validation['errors'][] = 'Field does not match expected format';
        }

        if ($value && isset($validators['length']) && strlen($value) != $validators['length']) {
            $validation['valid'] = false;
            $validation['errors'][] = 'Field length is incorrect';
        }

        if ($value && isset($validators['type'])) {
            switch ($validators['type']) {
                case 'numeric':
                    if (! is_numeric($value)) {
                        $validation['valid'] = false;
                        $validation['errors'][] = 'Field must be numeric';
                    }
                    break;
                case 'date':
                    if (! strtotime($value)) {
                        $validation['valid'] = false;
                        $validation['errors'][] = 'Field must be a valid date';
                    }
                    break;
                case 'email':
                    if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $validation['valid'] = false;
                        $validation['errors'][] = 'Field must be a valid email';
                    }
                    break;
            }
        }

        return $validation;
    }

    protected function calculateTemplateMatchScore($text, DocumentTemplate $template): float
    {
        $score = 0;
        $totalFields = $template->fields->count();

        if ($totalFields === 0) {
            return 0;
        }

        foreach ($template->fields as $field) {
            if ($this->extractFieldValue($text, $field) !== null) {
                $score++;
            }
        }

        return $score / $totalFields;
    }
}
