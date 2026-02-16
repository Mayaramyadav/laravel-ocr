<?php

namespace Mayaram\LaravelOcr\Console\Commands;

use Illuminate\Console\Command;
use Mayaram\LaravelOcr\Services\DocumentParser;
use Illuminate\Support\Facades\File;

class ProcessDocumentCommand extends Command
{
    protected $signature = 'laravel-ocr:process 
                            {document : Path to the document to process}
                            {--template= : Template ID to use}
                            {--type= : Document type (invoice, receipt, etc.)}
                            {--ai-cleanup : Enable AI cleanup}
                            {--save : Save to database}
                            {--output= : Output format (json, table)}';

    protected $description = 'Process a document using Smart OCR';

    public function __construct(protected DocumentParser $parser)
    {
        parent::__construct();
    }

    public function handle()
    {
        $documentPath = $this->argument('document');

        if (!File::exists($documentPath)) {
            $this->error("Document not found: {$documentPath}");
            return 1;
        }

        $this->info("Processing document: {$documentPath}");

        $options = [
            'use_ai_cleanup' => $this->option('ai-cleanup'),
            'save_to_database' => $this->option('save'),
        ];

        if ($templateId = $this->option('template')) {
            $options['template_id'] = $templateId;
        }

        if ($type = $this->option('type')) {
            $options['document_type'] = $type;
        }

        $progressBar = $this->output->createProgressBar(100);
        $progressBar->start();

        try {
            $result = $this->parser->parse($documentPath, $options);
            $progressBar->setProgress(100);
            $progressBar->finish();
            $this->line('');

            if ($result['success']) {
                $this->info('Document processed successfully!');
                
                $outputFormat = $this->option('output') ?? 'table';
                
                if ($outputFormat === 'json') {
                    $this->line(json_encode($result['data'], JSON_PRETTY_PRINT));
                } else {
                    $this->displayResults($result['data']);
                }

                $this->info("\nProcessing time: " . round($result['metadata']['processing_time'], 2) . " seconds");
                
                if (isset($result['metadata']['template_used'])) {
                    $this->info("Template used: " . $result['metadata']['template_used']);
                }
            } else {
                $this->error('Processing failed: ' . ($result['error'] ?? 'Unknown error'));
                return 1;
            }
        } catch (\Exception $e) {
            if (isset($progressBar)) {
                $progressBar->finish();
                $this->line('');
            }
            $this->error('An error occurred: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    protected function displayResults(array $data)
    {
        if (isset($data['fields']) && is_array($data['fields'])) {
            $rows = [];
            
            foreach ($data['fields'] as $key => $field) {
                if (is_array($field)) {
                    $rows[] = [
                        $key,
                        $field['label'] ?? $key,
                        $field['value'] ?? 'N/A',
                        isset($field['confidence']) ? round($field['confidence'] * 100) . '%' : 'N/A'
                    ];
                } else {
                    $rows[] = [$key, $key, $field, 'N/A'];
                }
            }

            if (!empty($rows)) {
                $this->table(['Field', 'Label', 'Value', 'Confidence'], $rows);
            } else {
                $this->warn('No structured fields extracted.');
            }
        }

        if (isset($data['document_type'])) {
            $this->info("\nDocument Type: " . $data['document_type']);
        }

        if (isset($data['raw_text']) && $this->confirm('Show raw extracted text?')) {
            $this->line("\nRaw Text:");
            $this->line($data['raw_text']);
        }
    }
}