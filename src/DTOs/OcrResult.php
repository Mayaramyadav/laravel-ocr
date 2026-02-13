<?php

namespace Mayaram\LaravelOcr\DTOs;

use Illuminate\Contracts\Support\Arrayable;

readonly class OcrResult implements Arrayable
{
    public function __construct(
        public string $text,
        public float $confidence,
        public array $bounds = [],
        public array $metadata = [],
    ) {}

    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'confidence' => $this->confidence,
            'bounds' => $this->bounds,
            'metadata' => $this->metadata,
        ];
    }
}
