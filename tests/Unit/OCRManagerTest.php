<?php

namespace Mayaram\LaravelOcr\Tests\Unit;

use Mayaram\LaravelOcr\Tests\TestCase;
use Mayaram\LaravelOcr\Services\OCRManager;
use Mayaram\LaravelOcr\Drivers\TesseractDriver;
use Mayaram\LaravelOcr\Facades\LaravelOcr;
use Mockery;

class OCRManagerTest extends TestCase
{
    protected $ocrManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ocrManager = app('laravel-ocr');
    }

    public function test_it_can_be_instantiated()
    {
        $this->assertInstanceOf(OCRManager::class, $this->ocrManager);
    }

    public function test_it_uses_default_driver()
    {
        $driver = $this->ocrManager->driver();
        $this->assertInstanceOf(TesseractDriver::class, $driver);
    }

    public function test_it_can_switch_drivers()
    {
        config(['laravel-ocr.drivers.test' => []]);
        
        $mock = Mockery::mock('Mayaram\LaravelOcr\Contracts\OCRDriver');
        $mock->shouldReceive('extract')->once()->andReturn($this->mockOCRResponse());
        
        $this->app->bind('laravel-ocr.driver.test', function () use ($mock) {
            return $mock;
        });
        
        $this->ocrManager->extend('test', function () use ($mock) {
            return $mock;
        });
        
        $result = $this->ocrManager->driver('test')->extract('test.jpg');
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('text', $result);
    }

    public function test_extract_method_returns_expected_structure()
    {
        $mock = Mockery::mock(TesseractDriver::class);
        $mock->shouldReceive('extract')
            ->once()
            ->andReturn($this->mockOCRResponse());
        
        $this->app->instance('laravel-ocr.driver.tesseract', $mock);
        
        $result = LaravelOcr::extract('test.jpg');
        
        $this->assertArrayHasKey('text', $result);
        $this->assertArrayHasKey('confidence', $result);
        $this->assertArrayHasKey('metadata', $result);
    }

    public function test_extract_with_template_applies_template()
    {
        $template = $this->createSampleTemplate();
        
        $mock = Mockery::mock(TesseractDriver::class);
        $mock->shouldReceive('extract')
            ->once()
            ->andReturn($this->mockOCRResponse());
        
        $this->app->instance('laravel-ocr.driver.tesseract', $mock);
        
        $result = LaravelOcr::extractWithTemplate('test.jpg', $template->id);
        
        $this->assertArrayHasKey('template_id', $result);
        $this->assertArrayHasKey('fields', $result);
        $this->assertEquals($template->id, $result['template_id']);
    }

    public function test_extract_with_language_option()
    {
        $mock = Mockery::mock(TesseractDriver::class);
        $mock->shouldReceive('extract')
            ->once()
            ->with('test.jpg', ['language' => 'spa'])
            ->andReturn($this->mockOCRResponse());
        
        $this->app->instance('laravel-ocr.driver.tesseract', $mock);
        
        $result = LaravelOcr::extract('test.jpg', ['language' => 'spa']);
        
        $this->assertIsArray($result);
    }

    public function test_extract_table_method()
    {
        $tableData = [
            'table' => [
                ['Item', 'Quantity', 'Price'],
                ['Widget A', '10', '$100.00'],
                ['Widget B', '5', '$50.00'],
            ],
            'raw_text' => 'Item    Quantity    Price',
            'metadata' => ['engine' => 'tesseract']
        ];
        
        $mock = Mockery::mock(TesseractDriver::class);
        $mock->shouldReceive('extractTable')
            ->once()
            ->andReturn($tableData);
        
        $this->app->instance('laravel-ocr.driver.tesseract', $mock);
        
        $result = LaravelOcr::extractTable('test.jpg');
        
        $this->assertArrayHasKey('table', $result);
        $this->assertCount(3, $result['table']);
    }

    public function test_it_handles_extraction_errors_gracefully()
    {
        $mock = Mockery::mock(TesseractDriver::class);
        $mock->shouldReceive('extract')
            ->once()
            ->andThrow(new \Exception('OCR extraction failed'));
        
        $this->app->instance('laravel-ocr.driver.tesseract', $mock);
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('OCR extraction failed');
        
        LaravelOcr::extract('test.jpg');
    }

    public function test_facade_accessor()
    {
        $this->assertEquals('laravel-ocr', LaravelOcr::getFacadeAccessor());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}