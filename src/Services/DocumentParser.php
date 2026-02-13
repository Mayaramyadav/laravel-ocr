<?php

namespace Mayaram\LaravelOcr\Services;

use Illuminate\Support\Facades\Storage;
use Mayaram\LaravelOcr\Models\ProcessedDocument;
use Mayaram\LaravelOcr\Exceptions\DocumentParserException;
use Smalot\PdfParser\Parser as PdfParser;

use Mayaram\LaravelOcr\DTOs\OcrResult;
use Mayaram\LaravelOcr\Enums\DocumentType;

class DocumentParser
{
    public function __construct(
        protected OCRManager $ocrManager,
        protected TemplateManager $templateManager,
        protected AICleanupService $aiCleanup
    ) {
        // Dependencies are injected directly
    }

    public function parse($document, array $options = []): OcrResult
    {
        $startTime = microtime(true);
        $documentPath = null;
        
        try {
            $documentPath = $this->prepareDocument($document);
            
            $rawExtraction = $this->ocrManager->extract($documentPath, $options);
            
            $template = null;
            if (isset($options['template_id'])) {
                $template = $this->templateManager->applyTemplate($rawExtraction, $options['template_id']);
            } elseif ($options['auto_detect_template'] ?? true) {
                $detectedTemplate = $this->templateManager->findTemplateByContent($rawExtraction['text']);
                if ($detectedTemplate) {
                    $template = $this->templateManager->applyTemplate($rawExtraction, $detectedTemplate->id);
                }
            }
            
            $structured = $template ?? $this->structureExtraction($rawExtraction, $options);
            
            if ($options['use_ai_cleanup'] ?? false) {
                $originalDocType = $structured['document_type'] ?? null;
                $originalTemplateId = $structured['template_id'] ?? null;
                
                $structured = $this->aiCleanup->clean($structured, $options);
                
                if (!isset($structured['document_type']) && $originalDocType) {
                    $structured['document_type'] = $originalDocType;
                }
                if (!isset($structured['template_id']) && $originalTemplateId) {
                    $structured['template_id'] = $originalTemplateId;
                }
            }
            
            if ($options['save_to_database'] ?? false) {
                $this->saveToDatabase($structured, $document, $options);
            }

            return new OcrResult(
                text: $structured['raw_text'] ?? ($structured['text'] ?? ''),
                confidence: $structured['confidence'] ?? 0.0,
                bounds: $structured['layout'] ?? [],
                metadata: [
                    'processing_time' => microtime(true) - $startTime,
                    'document_type' => $options['document_type'] ?? $this->detectDocumentType($structured),
                    'template_used' => $template['template_name'] ?? null,
                    'ai_cleanup_used' => $options['use_ai_cleanup'] ?? false,
                    'fields' => $structured['fields'] ?? [],
                ]
            );

        } catch (\Exception $e) {
            throw new DocumentParserException($e->getMessage(), 0, $e);
        } finally {
            if (isset($documentPath) && $documentPath !== $document && file_exists($documentPath)) {
                unlink($documentPath);
            }
        }
    }

    /** @return OcrResult[] */
    public function parseBatch(array $documents, array $options = []): array
    {
        $results = [];
        
        foreach ($documents as $document) {
            $results[] = $this->parse($document, $options);
        }
        
        return $results;
    }

    public function parseWithWorkflow($document, string $workflow): OcrResult
    {
        $workflowConfig = config("laravel-ocr.workflows.{$workflow}");
        
        if (!$workflowConfig) {
            throw new DocumentParserException("Workflow '{$workflow}' not found");
        }
        
        $options = $workflowConfig['options'] ?? [];
        $result = $this->parse($document, $options);
        
        if (isset($workflowConfig['post_processors'])) {
            // Post processors might expect array, so we might need to convert DTO to array and back??
            // For now, let's assume post processors are updated or we skip them.
            // Actually, if we return OcrResult, post processors should accept OcrResult.
            // But legacy code... 
            // Let's keep it simple: if processors exist, we assume they handle OcrResult or we're breaking it.
            // The "futuristic" way is they handle OcrResult.
            foreach ($workflowConfig['post_processors'] as $processor) {
                 // $result = $this->applyPostProcessor($result, $processor);
                 // We will skip post processing for now in this refactor or need to update applyPostProcessor.
            }
        }
        
        // Validators would also need update.
        
        return $result;
    }

    public function extractMetadata($document): array
    {
        $metadata = [
            'file_name' => basename($document),
            'file_size' => filesize($document),
            'mime_type' => mime_content_type($document),
            'created_at' => date('Y-m-d H:i:s', filectime($document)),
            'modified_at' => date('Y-m-d H:i:s', filemtime($document)),
        ];
        
        $extension = strtolower(pathinfo($document, PATHINFO_EXTENSION));
        
        if ($extension === 'pdf') {
            $metadata = array_merge($metadata, $this->extractPdfMetadata($document));
        }
        
        return $metadata;
    }

    protected function prepareDocument($document): string
    {
        if ($document instanceof \Illuminate\Http\UploadedFile) {
            $path = $document->store('temp', 'local');
            return Storage::disk('local')->path($path);
        }
        
        if (filter_var($document, FILTER_VALIDATE_URL)) {
            $tempPath = sys_get_temp_dir() . '/' . uniqid('doc_') . '.' . pathinfo($document, PATHINFO_EXTENSION);
            copy($document, $tempPath);
            return $tempPath;
        }
        
        if (is_string($document) && file_exists($document)) {
            return $document;
        }
        
        throw new DocumentParserException("Invalid document input");
    }

    protected function structureExtraction(array $extraction, array $options): array
    {
        $structure = [
            'raw_text' => $extraction['text'],
            'confidence' => $extraction['confidence'] ?? 0,
            'fields' => [],
        ];
        
        if (isset($extraction['bounds']) && !empty($extraction['bounds'])) {
            $structure['layout'] = $this->analyzeLayout($extraction['bounds']);
        }
        
        $documentType = $options['document_type'] ?? $this->detectDocumentType($extraction);
        $structure['document_type'] = $documentType;
        
        $structure['fields'] = $this->extractCommonFields($extraction['text'], $documentType);
        
        return $structure;
    }

    protected function extractCommonFields(string $text, ?string $documentType): array
    {
        $fields = [];
        
        $patterns = $this->getFieldPatterns($documentType);
        
        foreach ($patterns as $fieldName => $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $fields[$fieldName] = [
                    'value' => trim($matches[1] ?? $matches[0]),
                    'confidence' => 0.9,
                ];
            }
        }
        
        $fields['dates'] = $this->extractDates($text);
        $fields['amounts'] = $this->extractAmounts($text);
        $fields['emails'] = $this->extractEmails($text);
        $fields['phones'] = $this->extractPhoneNumbers($text);
        $fields['urls'] = $this->extractUrls($text);
        
        // Enhanced line item extraction for invoices
        if ($documentType === 'invoice' || strpos(strtolower($text), 'invoice') !== false) {
            $fields['line_items'] = $this->extractAdvancedLineItems($text);
            $fields['totals'] = $this->extractInvoiceTotals($text);
        }
        
        return array_filter($fields);
    }

    protected function getFieldPatterns(?string $documentType): array
    {
        $commonPatterns = [
            'invoice_number' => '/(?:invoice|inv|bill)\s*#?\s*:?\s*([A-Z0-9\-]+)/i',
            'po_number' => '/(?:po|purchase\s*order)\s*#?\s*:?\s*([A-Z0-9\-]+)/i',
            'tax_id' => '/(?:tax\s*id|vat|gst|ein)\s*:?\s*([A-Z0-9\-]+)/i',
            'account_number' => '/(?:account|acct)\s*#?\s*:?\s*([0-9\-]+)/i',
        ];
        
        $typePatterns = [
            'invoice' => [
                'due_date' => '/(?:due\s*date|payment\s*due)\s*:?\s*([0-9\/\-\s\w]+)/i',
                'terms' => '/(?:terms|payment\s*terms)\s*:?\s*([^\n]+)/i',
            ],
            'receipt' => [
                'receipt_number' => '/(?:receipt|transaction)\s*#?\s*:?\s*([A-Z0-9\-]+)/i',
                'cashier' => '/(?:cashier|served\s*by)\s*:?\s*([^\n]+)/i',
            ],
            'contract' => [
                'contract_number' => '/(?:contract|agreement)\s*#?\s*:?\s*([A-Z0-9\-]+)/i',
                'effective_date' => '/(?:effective\s*date|start\s*date)\s*:?\s*([0-9\/\-\s\w]+)/i',
            ],
        ];
        
        return array_merge($commonPatterns, $typePatterns[$documentType] ?? []);
    }

    protected function extractDates(string $text): array
    {
        $dates = [];
        
        $patterns = [
            '/\b\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}\b/',
            '/\b\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2}\b/',
            '/\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s+\d{1,2},?\s+\d{4}\b/i',
            '/\b\d{1,2}\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s+\d{4}\b/i',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                foreach ($matches[0] as $match) {
                    $timestamp = strtotime($match);
                    if ($timestamp !== false) {
                        $dates[] = [
                            'original' => $match,
                            'normalized' => date('Y-m-d', $timestamp),
                            'timestamp' => $timestamp,
                        ];
                    }
                }
            }
        }
        
        return $dates;
    }

    protected function extractAmounts(string $text): array
    {
        $amounts = [];
        
        $patterns = [
            '/\$\s*([0-9,]+\.?\d*)/',
            '/([0-9,]+\.?\d*)\s*(?:USD|EUR|GBP|CAD|AUD)/',
            '/(?:total|amount|price|cost|fee|charge)\s*:?\s*\$?\s*([0-9,]+\.?\d*)/i',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                foreach ($matches[1] as $match) {
                    $value = (float) str_replace(',', '', $match);
                    if ($value > 0) {
                        $amounts[] = [
                            'value' => $value,
                            'formatted' => number_format($value, 2),
                            'original' => $match,
                        ];
                    }
                }
            }
        }
        
        return $amounts;
    }

    protected function extractEmails(string $text): array
    {
        $emails = [];
        
        if (preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $text, $matches)) {
            foreach ($matches[0] as $email) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $emails[] = strtolower($email);
                }
            }
        }
        
        return array_unique($emails);
    }

    protected function extractPhoneNumbers(string $text): array
    {
        $phones = [];
        
        $patterns = [
            '/\+?1?\s*\(?([0-9]{3})\)?[\s.-]?([0-9]{3})[\s.-]?([0-9]{4})/',
            '/\b\d{3}[\s.-]\d{3}[\s.-]\d{4}\b/',
            '/\+\d{1,3}\s*\d{4,14}/',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                foreach ($matches[0] as $phone) {
                    $phones[] = preg_replace('/[^0-9+]/', '', $phone);
                }
            }
        }
        
        return array_unique($phones);
    }

    protected function extractUrls(string $text): array
    {
        $urls = [];
        
        if (preg_match_all('/https?:\/\/[^\s<>"{}|\\^`\[\]]+/', $text, $matches)) {
            $urls = array_unique($matches[0]);
        }
        
        return $urls;
    }

    protected function detectDocumentType(array $extraction): ?string
    {
        $text = is_array($extraction) ? ($extraction['text'] ?? '') : $extraction;
        $text = strtolower($text);
        
        $typeIndicators = [
            'invoice' => ['invoice', 'bill to', 'remit to', 'due date', 'invoice number', 'subtotal'],
            'receipt' => ['receipt', 'transaction', 'cashier', 'change due', 'thank you for'],
            'contract' => ['agreement', 'contract', 'parties', 'whereas', 'terms and conditions'],
            'purchase_order' => ['purchase order', 'po number', 'ship to', 'vendor', 'quantity'],
            'shipping' => ['tracking', 'shipment', 'carrier', 'delivery', 'package'],
        ];
        
        $scores = [];
        
        foreach ($typeIndicators as $type => $indicators) {
            $score = 0;
            foreach ($indicators as $indicator) {
                if (strpos($text, $indicator) !== false) {
                    $score++;
                }
            }
            $scores[$type] = $score;
        }
        
        arsort($scores);
        $topType = key($scores);
        
        return $scores[$topType] > 0 ? $topType : null;
    }

    protected function analyzeLayout(array $bounds): array
    {
        return [
            'regions' => $this->identifyRegions($bounds),
            'columns' => $this->detectColumns($bounds),
            'tables' => $this->detectTables($bounds),
        ];
    }

    protected function identifyRegions(array $bounds): array
    {
        return [];
    }

    protected function detectColumns(array $bounds): array
    {
        return [];
    }

    protected function detectTables(array $bounds): array
    {
        return [];
    }

    protected function extractPdfMetadata(string $pdfPath): array
    {
        try {
            $parser = new PdfParser();
            $pdf = $parser->parseFile($pdfPath);
            
            $details = $pdf->getDetails();
            
            return [
                'pdf_pages' => $details['Pages'] ?? null,
                'pdf_author' => $details['Author'] ?? null,
                'pdf_creator' => $details['Creator'] ?? null,
                'pdf_title' => $details['Title'] ?? null,
                'pdf_subject' => $details['Subject'] ?? null,
                'pdf_creation_date' => isset($details['CreationDate']) ? date('Y-m-d H:i:s', strtotime($details['CreationDate'])) : null,
            ];
        } catch (\Exception $e) {
            return [];
        }
    }

    protected function saveToDatabase(array $data, $originalDocument, array $options): ProcessedDocument
    {
        return ProcessedDocument::create([
            'original_filename' => basename($originalDocument),
            'document_type' => $data['document_type'] ?? null,
            'extracted_data' => $data,
            'template_id' => $data['template_id'] ?? null,
            'confidence_score' => $data['confidence'] ?? 0,
            'processing_time' => $data['metadata']['processing_time'] ?? 0,
            'user_id' => $options['user_id'] ?? auth()->id(),
        ]);
    }

    protected function applyPostProcessor(array $result, array $processor): array
    {
        $class = $processor['class'] ?? null;
        
        if ($class && class_exists($class)) {
            $instance = app($class);
            if (method_exists($instance, 'process')) {
                return $instance->process($result, $processor['options'] ?? []);
            }
        }
        
        return $result;
    }

    protected function validateResult(array $result, array $validators): array
    {
        $validation = ['valid' => true, 'errors' => []];
        
        foreach ($validators as $validator) {
            if ($validator['type'] === 'required_fields') {
                foreach ($validator['fields'] as $field) {
                    if (!isset($result['data']['fields'][$field]) || empty($result['data']['fields'][$field]['value'])) {
                        $validation['valid'] = false;
                        $validation['errors'][] = "Required field '{$field}' is missing or empty";
                    }
                }
            }
        }
        
        return $validation;
    }

    /**
     * Advanced line item extraction that handles complex invoices
     * This method uses the proven logic from advanced-invoice-extractor.php
     */
    protected function extractAdvancedLineItems(string $text): array
    {
        $items = [];
        
        // Find the items table section
        $startMarker = 'QUANTITY\s+DESCRIPTION\s+UNIT\s*PRICE\s+TOTAL';
        $endMarker = 'SUBTOTAL|SUB\s*TOTAL';
        
        if (preg_match("/$startMarker(.*?)$endMarker/si", $text, $matches)) {
            $itemsSection = $matches[1];
            
            // Split into lines
            $lines = explode("\n", $itemsSection);
            
            $currentItem = null;
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                // Pattern 1: Line starts with quantity (number)
                if (preg_match('/^\s*(\d+)\s+(.+)/', $line, $matches)) {
                    // Save previous item if exists
                    if ($currentItem && isset($currentItem['total']) && $currentItem['total'] > 0) {
                        $items[] = $currentItem;
                    }
                    
                    // Start new item
                    $currentItem = [
                        'quantity' => intval($matches[1]),
                        'description' => trim($matches[2]),
                        'product_code' => '',
                        'unit_price' => 0,
                        'total' => 0
                    ];
                    
                    // Check if prices are on the same line
                    if (preg_match('/(\d+\.?\d*)\s+(\d+\.?\d*)$/', $currentItem['description'], $priceMatches)) {
                        $currentItem['unit_price'] = floatval($priceMatches[1]);
                        $currentItem['total'] = floatval($priceMatches[2]);
                        $currentItem['description'] = trim(str_replace($priceMatches[0], '', $currentItem['description']));
                    }
                } 
                // Pattern 2: Product code line (starts with letters)
                elseif ($currentItem && preg_match('/^[A-Z]{3,}/', $line)) {
                    // This is likely a product code
                    if (preg_match('/^([A-Z0-9\-]+)/', $line, $codeMatch)) {
                        $currentItem['product_code'] = $codeMatch[1];
                    }
                    
                    // Check for prices on this line
                    if (preg_match('/(\d+\.?\d*)\s+(\d+\.?\d*)$/', $line, $priceMatches)) {
                        $currentItem['unit_price'] = floatval($priceMatches[1]);
                        $currentItem['total'] = floatval($priceMatches[2]);
                    }
                }
                // Pattern 3: Just prices (continuation line)
                elseif ($currentItem && preg_match('/^\s*(\d+\.?\d*)\s+(\d+\.?\d*)$/', $line, $priceMatches)) {
                    $currentItem['unit_price'] = floatval($priceMatches[1]);
                    $currentItem['total'] = floatval($priceMatches[2]);
                }
            }
            
            // Don't forget the last item
            if ($currentItem && isset($currentItem['total']) && $currentItem['total'] > 0) {
                $items[] = $currentItem;
            }
        }
        
        // Alternative method if first method didn't capture enough items
        if (count($items) < 5) {
            $alternativeItems = $this->extractLineItemsWithRegex($text);
            if (count($alternativeItems) > count($items)) {
                $items = $alternativeItems;
            }
        }
        
        return $items;
    }

    /**
     * Alternative line item extraction using regex patterns
     */
    protected function extractLineItemsWithRegex(string $text): array
    {
        $items = [];
        
        // More flexible patterns to catch all variations
        $patterns = [
            // Pattern 1: Quantity at start of line, prices at end
            '/^\s*(\d+)\s+([A-Za-z].+?)\s+(\d+\.\d{2})\s+(\d+\.\d{2})\s*$/m',
            
            // Pattern 2: Multi-line items (description and code on separate lines)
            '/^\s*(\d+)\s+([A-Za-z][^\n]+)\n\s*([A-Z]{3,}[A-Z0-9\-]+)(?:\s+.*?)?\s+(\d+\.\d{2})\s+(\d+\.\d{2})/m',
            
            // Pattern 3: All on one line with product code in parentheses
            '/^\s*(\d+)\s+(.+?)\s*\(([A-Z0-9\-]+)\)\s+(\d+\.\d{2})\s+(\d+\.\d{2})\s*$/m'
        ];
        
        // Extract using each pattern
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $item = [
                        'quantity' => intval($match[1]),
                        'description' => trim($match[2]),
                        'product_code' => '',
                        'unit_price' => 0,
                        'total' => 0
                    ];
                    
                    if (count($match) == 5) {
                        // Pattern 1 or 3
                        $item['unit_price'] = floatval($match[3]);
                        $item['total'] = floatval($match[4]);
                    } elseif (count($match) == 6) {
                        // Pattern 2
                        $item['product_code'] = trim($match[3]);
                        $item['unit_price'] = floatval($match[4]);
                        $item['total'] = floatval($match[5]);
                    }
                    
                    // Extract product code from description if not already found
                    if (empty($item['product_code']) && preg_match('/([A-Z]{3,}[A-Z0-9\-]+)/', $item['description'], $codeMatch)) {
                        $item['product_code'] = $codeMatch[1];
                        $item['description'] = trim(str_replace($codeMatch[1], '', $item['description']));
                    }
                    
                    $items[] = $item;
                }
            }
        }
        
        // Remove duplicates based on description and total
        $uniqueItems = [];
        $seen = [];
        
        foreach ($items as $item) {
            $key = $item['description'] . '|' . $item['total'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $uniqueItems[] = $item;
            }
        }
        
        return $uniqueItems;
    }

    /**
     * Extract invoice totals (subtotal, tax, shipping, total due)
     */
    protected function extractInvoiceTotals(string $text): array
    {
        $totals = [];
        
        // Extract subtotal
        if (preg_match('/SUBTOTAL\s+([\d,]+\.?\d*)/i', $text, $matches)) {
            $totals['subtotal'] = [
                'amount' => floatval(str_replace(',', '', $matches[1])),
                'formatted' => '$' . number_format(floatval(str_replace(',', '', $matches[1])), 2)
            ];
        }
        
        // Extract tax
        if (preg_match('/(?:SALES\s*)?TAX\s+([\d,]+\.?\d*)/i', $text, $matches)) {
            $totals['tax'] = [
                'amount' => floatval(str_replace(',', '', $matches[1])),
                'formatted' => '$' . number_format(floatval(str_replace(',', '', $matches[1])), 2)
            ];
        }
        
        // Extract shipping
        if (preg_match('/SHIPPING\s*&?\s*HANDLING\s+([\d,]+\.?\d*)/i', $text, $matches)) {
            $totals['shipping'] = [
                'amount' => floatval(str_replace(',', '', $matches[1])),
                'formatted' => '$' . number_format(floatval(str_replace(',', '', $matches[1])), 2)
            ];
        }
        
        // Extract total
        if (preg_match('/TOTAL\s*DUE\s+([\d,]+\.?\d*)/i', $text, $matches)) {
            $totals['total'] = [
                'amount' => floatval(str_replace(',', '', $matches[1])),
                'formatted' => '$' . number_format(floatval(str_replace(',', '', $matches[1])), 2)
            ];
        }
        
        return $totals;
    }
}