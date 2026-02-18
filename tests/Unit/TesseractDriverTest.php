<?php

namespace Mayaram\LaravelOcr\Tests\Unit;

use Mayaram\LaravelOcr\Tests\TestCase;
use Mayaram\LaravelOcr\Drivers\TesseractDriver;
use Mayaram\LaravelOcr\Exceptions\OCRException;

class TesseractDriverTest extends TestCase
{
    protected $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = new TesseractDriver([
            'language' => 'eng'
        ]);
    }

    public function test_it_implements_ocr_driver_interface()
    {
        $this->assertInstanceOf(\Mayaram\LaravelOcr\Contracts\OCRDriver::class, $this->driver);
    }

    public function test_it_returns_supported_languages()
    {
        $languages = $this->driver->getSupportedLanguages();
        
        $this->assertIsArray($languages);
        $this->assertArrayHasKey('eng', $languages);
        $this->assertArrayHasKey('spa', $languages);
        $this->assertArrayHasKey('fra', $languages);
        $this->assertEquals('English', $languages['eng']);
    }

    public function test_it_returns_supported_formats()
    {
        $formats = $this->driver->getSupportedFormats();
        
        $this->assertIsArray($formats);
        $this->assertContains('jpg', $formats);
        $this->assertContains('png', $formats);
        $this->assertContains('pdf', $formats);
    }

    public function test_it_prepares_documents_correctly()
    {
        // Test with existing image file (supported format)
        $tempFile = sys_get_temp_dir() . '/test.jpg';
        file_put_contents($tempFile, 'test');
        
        $prepared = $this->invokeMethod($this->driver, 'prepareDocument', [$tempFile]);
        $this->assertEquals($tempFile, $prepared);
        
        unlink($tempFile);
    }

    public function test_it_throws_exception_for_unsupported_format()
    {
        $this->expectException(OCRException::class);
        $this->expectExceptionMessage('Unsupported file format');
        
        $tempFile = sys_get_temp_dir() . '/test.doc';
        file_put_contents($tempFile, 'test');
        
        $this->invokeMethod($this->driver, 'prepareDocument', [$tempFile]);
        
        unlink($tempFile);
    }

    public function test_extract_table_processes_text_into_rows()
    {
        // Mock the extract method to return structured text
        $mockDriver = \Mockery::mock(TesseractDriver::class)->makePartial();
        $mockDriver->shouldReceive('extract')
            ->andReturn([
                'text' => "Name    Age    City\nJohn    25     NYC\nJane    30     LA",
                'confidence' => 0.9,
                'metadata' => ['engine' => 'tesseract']
            ]);

        $result = $mockDriver->extractTable('dummy.jpg');

        $this->assertArrayHasKey('table', $result);
        $this->assertCount(3, $result['table']);
        $this->assertEquals(['Name', 'Age', 'City'], $result['table'][0]);
        $this->assertEquals(['John', '25', 'NYC'], $result['table'][1]);
    }

    public function test_barcode_and_qr_extraction_throw_exceptions()
    {
        $this->expectException(OCRException::class);
        $this->expectExceptionMessage('Barcode extraction not supported');
        
        $this->driver->extractBarcode('test.jpg');
    }

    public function test_qr_code_extraction_throws_exception()
    {
        $this->expectException(OCRException::class);
        $this->expectExceptionMessage('QR code extraction not supported');
        
        $this->driver->extractQRCode('test.jpg');
    }

    protected function invokeMethod($object, $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}