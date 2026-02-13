<?php

namespace Mayaram\LaravelOcr\Facades;

use Illuminate\Support\Facades\Facade;

class LaravelOcr extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'laravel-ocr';
    }
}