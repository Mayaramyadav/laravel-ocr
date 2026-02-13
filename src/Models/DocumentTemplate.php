<?php

namespace Mayaram\LaravelOcr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentTemplate extends Model
{
    protected $table = 'smart_ocr_templates';

    protected $fillable = [
        'name',
        'description',
        'type',
        'layout',
        'is_active',
        'version',
    ];

    protected $casts = [
        'layout' => 'array',
        'is_active' => 'boolean',
    ];

    public function fields(): HasMany
    {
        return $this->hasMany(TemplateField::class, 'template_id');
    }

    public function getFieldByKey($key)
    {
        return $this->fields()->where('key', $key)->first();
    }

    public function duplicate($newName = null): self
    {
        $clone = $this->replicate();
        $clone->name = $newName ?? $this->name . ' (Copy)';
        $clone->save();

        foreach ($this->fields as $field) {
            $fieldClone = $field->replicate();
            $fieldClone->template_id = $clone->id;
            $fieldClone->save();
        }

        return $clone;
    }
}