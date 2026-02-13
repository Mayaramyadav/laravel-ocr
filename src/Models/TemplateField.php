<?php

namespace Mayaram\LaravelOcr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemplateField extends Model
{
    protected $table = 'smart_ocr_template_fields';

    protected $fillable = [
        'template_id',
        'key',
        'label',
        'type',
        'pattern',
        'position',
        'validators',
        'default_value',
        'description',
        'order',
    ];

    protected $casts = [
        'position' => 'array',
        'validators' => 'array',
        'order' => 'integer',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'template_id');
    }

    public function getValidationRules(): array
    {
        if (!$this->validators) {
            return [];
        }

        $rules = [];
        
        if (isset($this->validators['required']) && $this->validators['required']) {
            $rules[] = 'required';
        }

        if (isset($this->validators['type'])) {
            switch ($this->validators['type']) {
                case 'numeric':
                    $rules[] = 'numeric';
                    break;
                case 'date':
                    $rules[] = 'date';
                    break;
                case 'email':
                    $rules[] = 'email';
                    break;
            }
        }

        if (isset($this->validators['regex'])) {
            $rules[] = 'regex:' . $this->validators['regex'];
        }

        if (isset($this->validators['length'])) {
            $rules[] = 'size:' . $this->validators['length'];
        }

        return $rules;
    }
}