<?php

namespace Mayaram\LaravelOcr\Tests\Integration;

use Mayaram\LaravelOcr\Tests\TestCase;
use Mayaram\LaravelOcr\Models\DocumentTemplate;
use Illuminate\Support\Facades\Storage;

class ConsoleCommandsTest extends TestCase
{
    public function test_create_template_command()
    {
        $this->artisan('laravel-ocr:create-template', [
            'name' => 'Test Invoice Template',
            'type' => 'invoice'
        ])
        ->expectsQuestion('Template description (optional)', 'A test invoice template')
        ->assertExitCode(0);

        $template = DocumentTemplate::where('name', 'Test Invoice Template')->first();
        $this->assertNotNull($template);
        $this->assertEquals('invoice', $template->type);
        $this->assertEquals('A test invoice template', $template->description);
    }

    public function test_create_template_command_interactive()
    {
        $this->artisan('laravel-ocr:create-template', [
            'name' => 'Interactive Template',
            'type' => 'receipt',
            '--interactive' => true
        ])
        ->expectsQuestion('Template description (optional)', 'Interactive test')
        ->expectsQuestion('Field key (e.g., invoice_number) or "done" to finish', 'store_name')
        ->expectsQuestion('Field label (human-readable name)', 'Store Name')
        ->expectsChoice('Field type', 'string', ['string', 'numeric', 'date', 'currency', 'email', 'phone'])
        ->expectsConfirmation('Add a regex pattern for this field?', 'no')
        ->expectsConfirmation('Add validators for this field?', 'yes')
        ->expectsConfirmation('Is this field required?', 'yes')
        ->expectsQuestion('Field key (e.g., invoice_number) or "done" to finish', 'done')
        ->expectsConfirmation('Would you like to export this template to a file?', 'no')
        ->assertExitCode(0);

        $template = DocumentTemplate::where('name', 'Interactive Template')->first();
        $this->assertNotNull($template);
        $this->assertEquals(1, $template->fields->count());
        
        $field = $template->fields->first();
        $this->assertEquals('store_name', $field->key);
        $this->assertEquals('Store Name', $field->label);
        $this->assertTrue($field->validators['required']);
    }

    public function test_process_document_command()
    {
        $this->mockOCRManager();
        
        $this->artisan('laravel-ocr:process', [
            'document' => $this->getSampleDocument('invoice'),
            '--type' => 'invoice',
            '--output' => 'json'
        ])
        ->assertExitCode(0);
    }

    public function test_process_document_command_with_template()
    {
        $this->mockOCRManager();
        $template = $this->createSampleTemplate('invoice');

        $this->artisan('laravel-ocr:process', [
            'document' => $this->getSampleDocument('invoice'),
            '--template' => $template->id,
            '--save' => true
        ])
        ->assertExitCode(0);

        // Verify document was saved to database
        $this->assertDatabaseHas('smart_ocr_processed_documents', [
            'template_id' => $template->id,
            'document_type' => 'invoice'
        ]);
    }

    public function test_process_document_command_with_ai_cleanup()
    {
        $this->mockOCRManager();

        $this->artisan('laravel-ocr:process', [
            'document' => $this->getSampleDocument('poor-quality'),
            '--ai-cleanup' => true,
            '--output' => 'table'
        ])
        ->assertExitCode(0);
    }

    public function test_process_document_command_file_not_found()
    {
        $this->artisan('laravel-ocr:process', [
            'document' => '/non/existent/file.pdf'
        ])
        ->assertExitCode(1);
    }

    protected function mockOCRManager()
    {
        $mock = \Mockery::mock('Mayaram\LaravelOcr\Services\OCRManager');
        $mock->shouldReceive('extract')
            ->andReturn($this->mockOCRResponse());
        
        $this->app->instance('laravel-ocr', $mock);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}