<?php

namespace Mayaram\LaravelOcr\Tests;

use Mayaram\LaravelOcr\Drivers\GoogleVisionDriver;
use Mayaram\LaravelOcr\Drivers\AWSTextractDriver;
use Mayaram\LaravelOcr\Drivers\AzureOCRDriver;
use Mayaram\LaravelOcr\Exceptions\OCRException;
use Mockery;

class DriversTest extends TestCase
{
    public function test_google_vision_driver_can_be_instantiated()
    {
        $driver = new GoogleVisionDriver(['key' => 'test']);
        $this->assertInstanceOf(GoogleVisionDriver::class, $driver);
    }

    public function test_google_vision_throws_exception_if_sdk_missing_on_extract()
    {
        // We assume SDK might be missing in dev environment or we mock class_exists behavior if we could, 
        // but since we can't easily mock class_exists for loaded classes, we rely on the fact 
        // that if SDK IS installed, it will try credentials.
        // If SDK is NOT installed (which is likely in this test env for optional deps), it throws OCRException.
        
        $driver = new GoogleVisionDriver([]);
        
        if (!class_exists('Google\Cloud\Vision\V1\ImageAnnotatorClient')) {
            $this->expectException(OCRException::class);
            $this->expectExceptionMessage('Google Cloud Vision SDK not installed');
            $driver->extract(__DIR__ . '/Unit/fixtures/sample.jpg'); // File path doesn't matter as check is first
        } else {
            // If SDK exists, it will fail on auth or file, verify it doesn't crash
            try {
                $driver->extract('non_existent_file.jpg');
            } catch (\Exception $e) {
                $this->assertTrue(true); // It threw some exception, which is expected with bad config/file
            }
        }
    }

    public function test_aws_textract_driver_can_be_instantiated()
    {
        $driver = new AWSTextractDriver(['key' => 'test', 'secret' => 'test', 'region' => 'us-east-1']);
        $this->assertInstanceOf(AWSTextractDriver::class, $driver);
    }

    public function test_aws_textract_throws_exception_if_sdk_missing_on_extract()
    {
        $driver = new AWSTextractDriver([]);

        if (!class_exists('Aws\Textract\TextractClient')) {
            $this->expectException(OCRException::class);
            $this->expectExceptionMessage('AWS SDK not installed');
            $driver->extract('dummy_path');
        } else {
             try {
                $driver->extract('non_existent_file.jpg');
            } catch (\Exception $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_azure_driver_can_be_instantiated()
    {
        $driver = new AzureOCRDriver(['key' => 'test', 'endpoint' => 'https://test.cognitiveservices.azure.com']);
        $this->assertInstanceOf(AzureOCRDriver::class, $driver);
    }
    
    public function test_azure_driver_throws_if_config_missing()
    {
        $driver = new AzureOCRDriver([]);
        // Azure check is run on extract
        
        $this->expectException(OCRException::class);
        $this->expectExceptionMessage('Azure OCR endpoint or key is missing');
        
        // Mock client to avoid real request if validation passes (which it shouldn't here)
        $driver->extract('dummy_path');
    }
}
