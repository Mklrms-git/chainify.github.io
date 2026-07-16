<?php
/**
 * Setup Verification Script
 * Run this file once to verify your installation
 */

require_once 'config/database.php';

echo "<!DOCTYPE html><html><head><title>Setup Verification</title>";
echo "<style>body{font-family:Arial;max-width:800px;margin:50px auto;padding:20px;}";
echo ".success{color:green;}.error{color:red;}.info{color:blue;}</style></head><body>";
echo "<h1>Supply Chain Management System - Setup Verification</h1>";

$errors = [];
$warnings = [];
$success = [];

// Check PHP Version
if (version_compare(PHP_VERSION, '7.4.0', '>=')) {
    $success[] = "PHP Version: " . PHP_VERSION . " ✓";
} else {
    $errors[] = "PHP Version: " . PHP_VERSION . " (Requires 7.4 or higher)";
}

// Check Database Connection
try {
    $conn = getDBConnection();
    $success[] = "Database connection: Success ✓";
    
    // Check if database exists
    $result = $conn->query("SELECT DATABASE()");
    if ($result) {
        $db_name = $result->fetch_array()[0];
        $success[] = "Database: {$db_name} ✓";
    }
    
    // Check required tables
    $required_tables = [
        'users', 'suppliers', 'products', 'warehouses', 'inventory',
        'purchase_orders', 'purchase_order_items', 'shipments', 'shipment_items',
        'stock_movements', 'warehouse_transfers', 'transfer_items',
        'demand_forecasts', 'sales_history'
    ];
    
    foreach ($required_tables as $table) {
        $result = $conn->query("SHOW TABLES LIKE '$table'");
        if ($result && $result->num_rows > 0) {
            $success[] = "Table '$table': Exists ✓";
        } else {
            $errors[] = "Table '$table': Missing ✗";
        }
    }
    
    // Check admin user
    $result = $conn->query("SELECT COUNT(*) as count FROM users WHERE username = 'admin'");
    $row = $result->fetch_assoc();
    if ($row['count'] > 0) {
        $success[] = "Admin user: Exists ✓";
    } else {
        $warnings[] = "Admin user: Not found (run database/schema.sql)";
    }
    
    closeDBConnection($conn);
    
} catch (Exception $e) {
    $errors[] = "Database connection: Failed - " . $e->getMessage();
}

// Check file permissions
$writable_dirs = [];
if (is_writable('.')) {
    $success[] = "Directory permissions: Writable ✓";
} else {
    $warnings[] = "Directory permissions: May need write access for sessions";
}

// Check required PHP extensions
$required_extensions = ['mysqli', 'session', 'json'];
foreach ($required_extensions as $ext) {
    if (extension_loaded($ext)) {
        $success[] = "PHP Extension '$ext': Loaded ✓";
    } else {
        $errors[] = "PHP Extension '$ext': Not loaded ✗";
    }
}

// Display Results
echo "<h2>Results:</h2>";

if (!empty($success)) {
    echo "<h3 class='success'>✓ Success:</h3><ul>";
    foreach ($success as $msg) {
        echo "<li class='success'>{$msg}</li>";
    }
    echo "</ul>";
}

if (!empty($warnings)) {
    echo "<h3 class='info'>⚠ Warnings:</h3><ul>";
    foreach ($warnings as $msg) {
        echo "<li class='info'>{$msg}</li>";
    }
    echo "</ul>";
}

if (!empty($errors)) {
    echo "<h3 class='error'>✗ Errors:</h3><ul>";
    foreach ($errors as $msg) {
        echo "<li class='error'>{$msg}</li>";
    }
    echo "</ul>";
}

if (empty($errors)) {
    echo "<h2 class='success'>✓ Setup Complete!</h2>";
    echo "<p>You can now <a href='index.php'>login to the system</a>.</p>";
    echo "<p><strong>Default credentials:</strong><br>";
    echo "Username: <code>admin</code><br>";
    echo "Password: <code>admin123</code></p>";
} else {
    echo "<h2 class='error'>✗ Setup Incomplete</h2>";
    echo "<p>Please fix the errors above before using the system.</p>";
}

echo "</body></html>";
?>

