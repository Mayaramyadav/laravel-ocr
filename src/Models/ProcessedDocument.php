<?php

namespace Mayaram\LaravelOcr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessedDocument extends Model
{
    protected $table = 'ocr_processed_documents';

    protected $fillable = [
        'original_filename',
        'document_type',
        'extracted_data',
        'template_id',
        'confidence_score',
        'processing_time',
        'user_id',
        'status',
        'error_message',
    ];

    protected $casts = [
        'extracted_data' => 'array',
        'confidence_score' => 'float',
        'processing_time' => 'float',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'template_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'user_id');
    }

    public function getFieldValue($fieldKey)
    {
        $field = data_get($this->extracted_data, "fields.{$fieldKey}");
        
        if (is_array($field)) {
            return $field['value'] ?? null;
        }
        
        return $field;
    }

    public function getAllFieldValues(): array
    {
        $fields = data_get($this->extracted_data, 'fields', []);
        $values = [];
        
        foreach ($fields as $key => $field) {
            $values[$key] = is_array($field) ? ($field['value'] ?? null) : $field;
        }
        
        return $values;
    }

    public function isValid(): bool
    {
        return $this->status === 'completed' && $this->confidence_score >= 0.7;
    }
}