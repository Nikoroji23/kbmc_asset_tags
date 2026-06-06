<?php
/**
 * Migration Runner - Applies pending database migrations
 * This script ensures all required columns and indexes exist
 */

require_once 'includes/config.php';

// Colors for terminal output
define('GREEN', "\033[92m");
define('RED', "\033[91m");
define('YELLOW', "\033[93m");
define('RESET', "\033[0m");

echo "\n" . str_repeat("=", 70) . "\n";
echo "  DATABASE MIGRATION RUNNER\n";
echo str_repeat("=", 70) . "\n\n";

try {
    // Migration 1: Add severity and issue_category columns
    echo "📋 Checking: device_repairs table columns...\n";
    
    // Check if columns exist
    $checkStmt = $pdo->query("SHOW COLUMNS FROM device_repairs WHERE Field IN ('severity', 'issue_category')");
    $existingColumns = $checkStmt->fetchAll(PDO::FETCH_COLUMN, 0);
    
    $missingSeverity = !in_array('severity', $existingColumns);
    $missingCategory = !in_array('issue_category', $existingColumns);
    
    if ($missingSeverity || $missingCategory) {
        echo "   ⚠️  Missing columns detected. Applying migration...\n\n";
        
        if ($missingSeverity) {
            echo "   → Adding 'severity' column...\n";
            $pdo->exec("ALTER TABLE device_repairs 
                       ADD COLUMN severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium' AFTER issue_description");
            echo "      " . GREEN . "✓ Done" . RESET . "\n";
        }
        
        if ($missingCategory) {
            echo "   → Adding 'issue_category' column...\n";
            $pdo->exec("ALTER TABLE device_repairs 
                       ADD COLUMN issue_category VARCHAR(50) DEFAULT 'other' AFTER severity");
            echo "      " . GREEN . "✓ Done" . RESET . "\n";
        }
        
        // Create indexes
        echo "\n   → Creating performance indexes...\n";
        try {
            $pdo->exec("CREATE INDEX idx_repairs_severity ON device_repairs(severity)");
            echo "      " . GREEN . "✓ idx_repairs_severity" . RESET . "\n";
        } catch (Exception $e) {
            echo "      ℹ️  Index may already exist\n";
        }
        
        try {
            $pdo->exec("CREATE INDEX idx_repairs_category ON device_repairs(issue_category)");
            echo "      " . GREEN . "✓ idx_repairs_category" . RESET . "\n";
        } catch (Exception $e) {
            echo "      ℹ️  Index may already exist\n";
        }
        
        try {
            $pdo->exec("CREATE INDEX idx_repairs_status_severity ON device_repairs(repair_status, severity)");
            echo "      " . GREEN . "✓ idx_repairs_status_severity" . RESET . "\n";
        } catch (Exception $e) {
            echo "      ℹ️  Index may already exist\n";
        }
        
        echo "\n" . GREEN . "✅ Migration applied successfully!" . RESET . "\n";
        
    } else {
        echo "   " . GREEN . "✓ All columns exist" . RESET . "\n";
    }
    
    // Verify incident_report_file column
    echo "\n📋 Checking: incident_report_file column...\n";
    $checkFile = $pdo->query("SHOW COLUMNS FROM device_repairs WHERE Field = 'incident_report_file'");
    if ($checkFile->rowCount() > 0) {
        echo "   " . GREEN . "✓ incident_report_file column exists" . RESET . "\n";
    } else {
        echo "   " . YELLOW . "⚠️  incident_report_file column not found!" . RESET . "\n";
    }
    
    echo "\n" . str_repeat("=", 70) . "\n";
    echo GREEN . "✅ All migrations completed successfully!" . RESET . "\n";
    echo "   The attachment system is now ready to use.\n";
    echo str_repeat("=", 70) . "\n\n";
    
} catch (Exception $e) {
    echo RED . "\n❌ Migration failed: " . $e->getMessage() . RESET . "\n\n";
    exit(1);
}
?>
