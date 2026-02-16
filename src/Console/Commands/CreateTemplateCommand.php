<?php

namespace Mayaram\LaravelOcr\Console\Commands;

use Illuminate\Console\Command;
use Mayaram\LaravelOcr\Services\TemplateManager;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;

class CreateTemplateCommand extends Command
{
    protected $signature = 'laravel-ocr:create-template 
                            {name : The name of the template}
                            {type : The document type (invoice, receipt, contract, etc.)}
                            {--interactive : Create template interactively}';

    protected $description = 'Create a new document template for OCR processing';

    public function __construct(protected \Mayaram\LaravelOcr\Services\TemplateManager $templateManager)
    {
        parent::__construct();
    }

    public function handle()
    {
        $name = $this->argument('name');
        $type = $this->argument('type');

        $data = [
            'name' => $name,
            'type' => $type,
            'description' => $this->ask('Template description (optional)'),
            'fields' => []
        ];

        if ($this->option('interactive')) {
            $this->info('Let\'s add fields to your template. Type "done" when finished.');
            
            while (true) {
                $fieldKey = $this->ask('Field key (e.g., invoice_number) or "done" to finish');
                
                if (strtolower($fieldKey) === 'done') {
                    break;
                }

                if (!preg_match('/^[a-z0-9_]+$/', $fieldKey)) {
                    $this->error('Field key must be snake_case (lowercase letters, numbers, and underscores only).');
                    continue;
                }

                $field = [
                    'key' => $fieldKey,
                    'label' => $this->ask('Field label (human-readable name)', Str::headline($fieldKey)),
                    'type' => $this->choice('Field type', [
                        'string', 'numeric', 'date', 'currency', 'email', 'phone'
                    ], 'string'),
                ];

                if ($this->confirm('Add a regex pattern for this field?')) {
                    $field['pattern'] = $this->ask('Regex pattern');
                }

                if ($this->confirm('Add validators for this field?')) {
                    $validators = [];
                    
                    if ($this->confirm('Is this field required?', true)) {
                        $validators['required'] = true;
                    }

                    if ($field['type'] === 'string' && $this->confirm('Add length validation?')) {
                        $validators['length'] = (int) $this->ask('Expected length');
                    }

                    if ($field['type'] === 'numeric' && $this->confirm('Must be numeric?')) {
                        $validators['type'] = 'numeric';
                    }

                    $field['validators'] = $validators;
                }

                $data['fields'][] = $field;
                $this->info("Field '{$fieldKey}' added successfully!");
            }
        }

        if (empty($data['fields']) && $this->option('interactive')) {
            $this->warn('No fields were added to the template.');
            if (!$this->confirm('Do you want to create the template without fields?')) {
                return 0;
            }
        }

        try {
            $template = $this->templateManager->create($data);
            
            $this->info("Template '{$name}' created successfully!");
            $this->table(
                ['ID', 'Name', 'Type', 'Fields Count'],
                [[$template->id, $template->name, $template->type, $template->fields->count()]]
            );
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Create Template Error: ' . $e->getMessage());
            \Illuminate\Support\Facades\Log::error($e->getTraceAsString());
            $this->error("Failed to create template: " . $e->getMessage());
            return 1;
        }

        return 0;
    }
}