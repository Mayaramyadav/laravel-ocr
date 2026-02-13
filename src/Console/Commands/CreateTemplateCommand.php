<?php

namespace Mayaram\LaravelOcr\Console\Commands;

use Illuminate\Console\Command;
use Mayaram\LaravelOcr\Services\TemplateManager;

class CreateTemplateCommand extends Command
{
    protected $signature = 'laravel-ocr:create-template 
                            {name : The name of the template}
                            {type : The document type (invoice, receipt, contract, etc.)}
                            {--interactive : Create template interactively}';

    protected $description = 'Create a new document template for OCR processing';

    protected TemplateManager $templateManager;

    public function __construct(TemplateManager $templateManager)
    {
        parent::__construct();
        $this->templateManager = $templateManager;
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

                $field = [
                    'key' => $fieldKey,
                    'label' => $this->ask('Field label (human-readable name)'),
                    'type' => $this->choice('Field type', [
                        'string', 'numeric', 'date', 'currency', 'email', 'phone'
                    ], 'string'),
                ];

                if ($this->confirm('Add a regex pattern for this field?')) {
                    $field['pattern'] = $this->ask('Regex pattern');
                }

                if ($this->confirm('Add validators for this field?')) {
                    $validators = [];
                    
                    if ($this->confirm('Is this field required?')) {
                        $validators['required'] = true;
                    }

                    if ($field['type'] === 'string' && $this->confirm('Add length validation?')) {
                        $validators['length'] = (int) $this->ask('Expected length');
                    }

                    $field['validators'] = $validators;
                }

                $data['fields'][] = $field;
                $this->info("Field '{$fieldKey}' added successfully!");
            }
        }

        try {
            $template = $this->templateManager->create($data);
            
            $this->info("Template '{$name}' created successfully!");
            $this->table(
                ['ID', 'Name', 'Type', 'Fields Count'],
                [[$template->id, $template->name, $template->type, $template->fields->count()]]
            );

            if ($this->confirm('Would you like to export this template to a file?')) {
                $filename = $this->ask('Filename', str_slug($name) . '.json');
                $path = storage_path('app/ocr-templates/' . $filename);
                
                file_put_contents($path, $this->templateManager->exportTemplate($template->id));
                $this->info("Template exported to: {$path}");
            }
        } catch (\Exception $e) {
            $this->error('Failed to create template: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}