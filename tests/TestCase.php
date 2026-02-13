<?php

namespace Mayaram\LaravelOcr\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Mayaram\LaravelOcr\LaravelOcrServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();
    }

    protected function getPackageProviders($app)
    {
        return [
            LaravelOcrServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app)
    {
        return [
            'LaravelOcr' => \Mayaram\LaravelOcr\Facades\LaravelOcr::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        config()->set('laravel-ocr.default', 'tesseract');
        config()->set('laravel-ocr.drivers.tesseract', [
            'binary' => '/usr/bin/tesseract',
            'language' => 'eng',
        ]);
        
        config()->set('laravel-ocr.storage.disk', 'testing');
        config()->set('filesystems.disks.testing', [
            'driver' => 'local',
            'root' => __DIR__ . '/temp',
        ]);
    }

    protected function setUpDatabase()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        
        $this->artisan('migrate', ['--database' => 'testing']);
    }

    protected function getSampleDocument($type = 'invoice')
    {
        return __DIR__ . "/fixtures/{$type}-sample.txt";
    }

    protected function createSampleTemplate($type = 'invoice')
    {
        $templateManager = app('laravel-ocr.templates');
        
        return $templateManager->create([
            'name' => 'Test ' . ucfirst($type) . ' Template',
            'type' => $type,
            'fields' => $this->getTemplateFields($type)
        ]);
    }

    protected function getTemplateFields($type)
    {
        $fields = [
            'invoice' => [
                [
                    'key' => 'invoice_number',
                    'label' => 'Invoice Number',
                    'type' => 'string',
                    'pattern' => '/Invoice\s*#?\s*:\s*([A-Z0-9\-]+)/i',
                ],
                [
                    'key' => 'date',
                    'label' => 'Invoice Date',
                    'type' => 'date',
                    'pattern' => '/Date\s*:\s*([0-9\/\-]+)/i',
                ],
                [
                    'key' => 'total',
                    'label' => 'Total Amount',
                    'type' => 'currency',
                    'pattern' => '/Total\s*:\s*\$?\s*([0-9,.]+)/i',
                ],
            ],
            'receipt' => [
                [
                    'key' => 'store_name',
                    'label' => 'Store Name',
                    'type' => 'string',
                ],
                [
                    'key' => 'receipt_number',
                    'label' => 'Receipt Number',
                    'type' => 'string',
                    'pattern' => '/Receipt\s*#?\s*:\s*([0-9]+)/i',
                ],
                [
                    'key' => 'total',
                    'label' => 'Total',
                    'type' => 'currency',
                    'pattern' => '/Total\s*:\s*\$?\s*([0-9,.]+)/i',
                ],
            ],
        ];

        return $fields[$type] ?? [];
    }

    protected function mockOCRResponse($text = null)
    {
        return [
            'text' => $text ?? $this->getDefaultOCRText(),
            'confidence' => 0.95,
            'bounds' => [],
            'metadata' => [
                'engine' => 'tesseract',
                'language' => 'eng',
                'processing_time' => 0.5
            ]
        ];
    }

    protected function getDefaultOCRText()
    {
        return "ACME Corporation\n" .
               "123 Business St, Suite 100\n" .
               "New York, NY 10001\n\n" .
               "INVOICE\n\n" .
               "Invoice #: INV-2024-001\n" .
               "Date: 01/15/2024\n" .
               "Due Date: 02/15/2024\n\n" .
               "Bill To:\n" .
               "John Doe\n" .
               "456 Client Ave\n" .
               "Los Angeles, CA 90001\n\n" .
               "Description: Professional Services\n" .
               "Quantity: 10\n" .
               "Rate: $100.00\n" .
               "Amount: $1,000.00\n\n" .
               "Subtotal: $1,000.00\n" .
               "Tax (8%): $80.00\n" .
               "Total: $1,080.00";
    }

    protected function cleanupTestFiles()
    {
        $testDir = __DIR__ . '/temp';
        if (is_dir($testDir)) {
            $this->deleteDirectory($testDir);
        }
    }

    protected function deleteDirectory($dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (is_dir($dir . "/" . $object)) {
                    $this->deleteDirectory($dir . "/" . $object);
                } else {
                    unlink($dir . "/" . $object);
                }
            }
        }
        rmdir($dir);
    }

    protected function tearDown(): void
    {
        $this->cleanupTestFiles();
        parent::tearDown();
    }
}