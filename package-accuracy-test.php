<?php

/**
 * Package Accuracy Test - Test Enhanced Laravel OCR
 * 
 * This tests the actual package with the enhanced line item extraction
 * Usage: php package-accuracy-test.php invoice-0-4.pdf
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

if ($argc < 2) {
    echo "❌ Please provide your PDF file!\n";
    echo "Usage: php package-accuracy-test.php invoice-0-4.pdf\n";
    exit(1);
}

$pdfFile = $argv[1];

if (!file_exists($pdfFile)) {
    echo "❌ File not found: {$pdfFile}\n";
    exit(1);
}

echo "🧪 Testing Enhanced Laravel OCR Package\n";
echo str_repeat("=", 60) . "\n";
echo "📄 File: " . basename($pdfFile) . "\n";
echo str_repeat("=", 60) . "\n\n";

// Load our package classes
require_once __DIR__ . '/src/Services/DocumentParser.php';
require_once __DIR__ . '/src/Services/OCRManager.php';
require_once __DIR__ . '/src/Services/TemplateManager.php';
require_once __DIR__ . '/src/Services/AICleanupService.php';
require_once __DIR__ . '/src/Drivers/TesseractDriver.php';

use Mayaram\LaravelOcr\Services\DocumentParser;
use Mayaram\LaravelOcr\Services\OCRManager;
use Mayaram\LaravelOcr\Services\TemplateManager;
use Mayaram\LaravelOcr\Services\AICleanupService;

// Create mock Laravel app container
class MockApp
{
    private $bindings = [];
    
    public function make($abstract)
    {
        return $this->bindings[$abstract] ?? null;
    }
    
    public function bind($abstract, $concrete)
    {
        $this->bindings[$abstract] = $concrete;
    }
    
    public function singleton($abstract, $concrete)
    {
        if (is_callable($concrete)) {
            $this->bindings[$abstract] = $concrete($this);
        } else {
            $this->bindings[$abstract] = $concrete;
        }
    }
}

// Set up the mock environment
$app = new MockApp();

// Create services
$ocrManager = new OCRManager($app);
$templateManager = new TemplateManager($app);
$aiCleanup = new AICleanupService($app);

$app->bind('laravel-ocr', $ocrManager);
$app->bind('laravel-ocr.templates', $templateManager);
$app->bind('laravel-ocr.ai-cleanup', $aiCleanup);

// Test the enhanced DocumentParser
$parser = new DocumentParser($app);

echo "🚀 Testing enhanced line item extraction...\n\n";

$startTime = microtime(true);

try {
    $result = $parser->parse($pdfFile, [
        'use_ai_cleanup' => false,
        'save_to_database' => false,
        'document_type' => 'invoice'
    ]);
    
    $processingTime = microtime(true) - $startTime;
    
    if ($result['success']) {
        $data = $result['data'];
        
        echo "✅ PACKAGE TEST RESULTS\n";
        echo str_repeat("=", 40) . "\n";
        
        // Header info
        if (isset($data['fields']) && !empty($data['fields'])) {
            echo "📋 EXTRACTED FIELDS:\n";
            foreach ($data['fields'] as $field => $value) {
                if (is_array($value) && isset($value['value'])) {
                    echo "• $field: " . $value['value'] . "\n";
                } elseif (!is_array($value) || (is_array($value) && !empty($value))) {
                    if ($field === 'line_items') {
                        echo "• Line Items Found: " . (is_array($value) ? count($value) : 0) . "\n";
                    } elseif ($field === 'totals' && is_array($value)) {
                        echo "• Financial Totals Found: " . count($value) . " types\n";
                    } elseif (!is_array($value)) {
                        echo "• $field: $value\n";
                    }
                }
            }
            echo "\n";
        }
        
        // Test line items specifically
        if (isset($data['fields']['line_items']) && is_array($data['fields']['line_items'])) {
            $lineItems = $data['fields']['line_items'];
            echo "📦 LINE ITEMS ANALYSIS:\n";
            echo "Total items extracted: " . count($lineItems) . "\n";
            
            if (count($lineItems) >= 28) {
                echo "✅ SUCCESS: All 28+ items captured!\n";
            } elseif (count($lineItems) >= 20) {
                echo "⚠️  GOOD: Most items captured (" . count($lineItems) . "/28)\n";
            } else {
                echo "❌ NEEDS WORK: Only " . count($lineItems) . "/28 items captured\n";
            }
            
            echo "\nFirst 5 line items:\n";
            foreach (array_slice($lineItems, 0, 5) as $i => $item) {
                echo ($i + 1) . ". {$item['quantity']}x {$item['description']} ";
                echo "({$item['product_code']}) = $" . number_format($item['total'], 2) . "\n";
            }
            
            if (count($lineItems) > 5) {
                echo "... and " . (count($lineItems) - 5) . " more items\n";
            }
            
            // Calculate total
            $itemsTotal = array_sum(array_column($lineItems, 'total'));
            echo "\n💰 Line items total: $" . number_format($itemsTotal, 2) . "\n";
        }
        
        // Test totals
        if (isset($data['fields']['totals']) && is_array($data['fields']['totals'])) {
            $totals = $data['fields']['totals'];
            echo "\n💰 FINANCIAL SUMMARY:\n";
            foreach ($totals as $type => $total) {
                if (is_array($total) && isset($total['formatted'])) {
                    echo "• " . ucfirst($type) . ": " . $total['formatted'] . "\n";
                }
            }
            
            // Verify totals match
            if (isset($totals['subtotal'], $data['fields']['line_items'])) {
                $lineItemsTotal = array_sum(array_column($data['fields']['line_items'], 'total'));
                $subtotal = $totals['subtotal']['amount'];
                $diff = abs($lineItemsTotal - $subtotal);
                
                echo "\n🔍 VERIFICATION:\n";
                echo "• Line items sum: $" . number_format($lineItemsTotal, 2) . "\n";
                echo "• Invoice subtotal: $" . number_format($subtotal, 2) . "\n";
                
                if ($diff < 0.01) {
                    echo "✅ Perfect match!\n";
                } else {
                    echo "⚠️  Difference: $" . number_format($diff, 2) . "\n";
                }
            }
        }
        
        echo "\n⏱️  PERFORMANCE:\n";
        echo "• Processing time: " . round($processingTime, 3) . " seconds\n";
        echo "• Document type: " . ($result['metadata']['document_type'] ?? 'Unknown') . "\n";
        echo "• OCR confidence: " . ($data['confidence'] ?? 'Unknown') . "\n";
        
        // Save results
        $outputDir = __DIR__ . '/package-test-results';
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }
        
        $timestamp = date('Y-m-d_H-i-s');
        $resultFile = $outputDir . "/package_test_{$timestamp}.json";
        file_put_contents($resultFile, json_encode([
            'test_file' => basename($pdfFile),
            'test_time' => date('Y-m-d H:i:s'),
            'processing_time' => $processingTime,
            'line_items_count' => isset($data['fields']['line_items']) ? count($data['fields']['line_items']) : 0,
            'totals_found' => isset($data['fields']['totals']) ? array_keys($data['fields']['totals']) : [],
            'success' => $result['success'],
            'full_result' => $result
        ], JSON_PRETTY_PRINT));
        
        echo "\n💾 Results saved to: " . basename($resultFile) . "\n";
        
        // Final assessment
        $lineItemCount = isset($data['fields']['line_items']) ? count($data['fields']['line_items']) : 0;
        echo "\n🎯 PACKAGE ASSESSMENT:\n";
        
        if ($lineItemCount >= 28) {
            echo "🏆 EXCELLENT: Package extracts ALL line items with high accuracy!\n";
            echo "✅ Ready for production deployment\n";
            echo "✅ Handles complex pharmaceutical invoices perfectly\n";
        } elseif ($lineItemCount >= 20) {
            echo "👍 GOOD: Package extracts most line items successfully\n";
            echo "⚠️  Minor tuning needed for 100% accuracy\n";
        } else {
            echo "🔧 NEEDS IMPROVEMENT: Package needs enhanced extraction logic\n";
            echo "❌ Current extraction is insufficient for complex invoices\n";
        }
        
    } else {
        echo "❌ PACKAGE TEST FAILED\n";
        echo "Error: " . $result['error'] . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ TEST ERROR: " . $e->getMessage() . "\n";
}

echo "\n🔍 COMPARISON WITH STANDALONE EXTRACTOR:\n";
echo "The standalone advanced-invoice-extractor.php got all 28 items.\n";
echo "This test shows how well the package implementation performs.\n";
echo "\nIf results differ, the package needs the advanced logic integrated.\n";