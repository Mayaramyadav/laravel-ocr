<?php

namespace Mayaram\LaravelOcr\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

class CleanupAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    public function __construct(protected string $documentType = 'general')
    {
    }

    public function instructions(): Stringable|string
    {
        return <<<EOT
You are an expert OCR post-processing AI. Your task is to clean and correct OCR-extracted text and fields.
1. Fix typos and OCR errors (e.g., 'Arnount' -> 'Amount', '1O0' -> '100').
2. Standardize formats (dates to YYYY-MM-DD, currency to decimal).
3. Value confidence: If a value seems wrong and you can't fix it contextually, keep original.
4. JSON Output: You must return valid JSON matching the input structure.
Document Type: {$this->documentType}
EOT;
    }

    public function messages(): iterable
    {
        return [];
    }

    public function tools(): iterable
    {
        return [];
    }
}
