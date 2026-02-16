<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default OCR Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default OCR driver that will be used by the
    | package. You may set this to any of the drivers defined below.
    |
    | Supported: "tesseract", "google_vision", "aws_textract", "azure"
    |
    */
    'default' => env('LARAVEL_OCR_DRIVER', 'tesseract'),

    /*
    |--------------------------------------------------------------------------
    | OCR Drivers
    |--------------------------------------------------------------------------
    |
    | Here you may configure the OCR drivers for your application. Each driver
    | has its own configuration options. Make sure to add your API credentials
    | for cloud-based services.
    |
    */
    'drivers' => [
        'tesseract' => [
            'binary' => env('TESSERACT_BINARY', '/usr/bin/tesseract'),
            'language' => env('TESSERACT_LANGUAGE', 'eng'),
            'timeout' => env('TESSERACT_TIMEOUT', 60),
        ],

        'google_vision' => [
            'key_file' => env('GOOGLE_VISION_KEY_FILE'),
            'project_id' => env('GOOGLE_VISION_PROJECT_ID'),
        ],

        'aws_textract' => [
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        ],

        'azure' => [
            'endpoint' => env('AZURE_OCR_ENDPOINT'),
            'key' => env('AZURE_OCR_KEY'),
            'version' => env('AZURE_OCR_VERSION', '3.2'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Cleanup Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the AI cleanup service that processes and structures the
    | extracted text data. You can use different providers for this service.
    |
    */
    'ai_cleanup' => [
        'enabled' => env('LARAVEL_OCR_AI_CLEANUP', false),
        'default_provider' => env('LARAVEL_OCR_AI_PROVIDER', 'openai'),
        
        'providers' => [
            'openai' => [
                'api_key' => env('OPENAI_API_KEY'),
                'model' => env('OPENAI_MODEL', 'gpt-3.5-turbo'),
                'max_tokens' => 2000,
            ],
            
            'anthropic' => [
                'api_key' => env('ANTHROPIC_API_KEY'),
                'model' => env('ANTHROPIC_MODEL', 'claude-3-haiku'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Template Storage
    |--------------------------------------------------------------------------
    |
    | Configure where document templates should be stored and how they should
    | be managed. Templates can be stored in database or files.
    |
    */
    'templates' => [
        'storage' => 'database', // 'database' or 'file'
        'path' => storage_path('app/ocr-templates'),
        'cache_enabled' => true,
        'cache_ttl' => 3600, // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Processing Options
    |--------------------------------------------------------------------------
    |
    | Configure default processing options for OCR operations.
    |
    */
    'processing' => [
        'image_preprocessing' => true,
        'auto_rotate' => true,
        'enhance_quality' => true,
        'remove_noise' => true,
        'max_file_size' => 10 * 1024 * 1024, // 10MB
        'allowed_formats' => ['jpg', 'jpeg', 'png', 'pdf', 'tiff', 'bmp'],
        'pdf_dpi' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configure queue settings for processing documents asynchronously.
    |
    */
    'queue' => [
        'enabled' => env('LARAVEL_OCR_QUEUE_ENABLED', false),
        'connection' => env('LARAVEL_OCR_QUEUE_CONNECTION', 'default'),
        'queue' => env('LARAVEL_OCR_QUEUE_NAME', 'ocr-processing'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Configuration
    |--------------------------------------------------------------------------
    |
    | Configure where processed documents and temporary files should be stored.
    |
    */
    'storage' => [
        'disk' => env('LARAVEL_OCR_STORAGE_DISK', 'local'),
        'temp_path' => storage_path('app/temp/ocr'),
        'processed_path' => storage_path('app/processed/ocr'),
        'cleanup_after' => 24, // hours
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    |
    | Configure security settings for the OCR package.
    |
    */
    'security' => [
        'encrypt_stored_data' => env('LARAVEL_OCR_ENCRYPT_DATA', false),
        'allowed_mime_types' => [
            'image/jpeg',
            'image/png',
            'image/tiff',
            'image/bmp',
            'application/pdf',
        ],
        'scan_for_malware' => env('LARAVEL_OCR_SCAN_MALWARE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Workflows
    |--------------------------------------------------------------------------
    |
    | Define custom workflows for different document types.
    |
    */
    'workflows' => [
        'invoice' => [
            'options' => [
                'use_ai_cleanup' => true,
                'auto_detect_template' => true,
                'extract_tables' => true,
            ],
            'post_processors' => [
                ['class' => 'App\OCR\Processors\InvoiceProcessor'],
            ],
            'validators' => [
                ['type' => 'required_fields', 'fields' => ['invoice_number', 'total']],
            ],
        ],
        
        'receipt' => [
            'options' => [
                'use_ai_cleanup' => true,
                'extract_line_items' => true,
            ],
            'post_processors' => [
                ['class' => 'App\OCR\Processors\ReceiptProcessor'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | API Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Configure rate limiting for API endpoints if exposed.
    |
    */
    'rate_limiting' => [
        'enabled' => true,
        'max_requests' => 60,
        'per_minutes' => 1,
    ],
];