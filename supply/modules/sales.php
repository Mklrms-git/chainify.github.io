<?php
require_once '../config/config.php';
requireModuleAccess('sales');

$conn = getDBConnection();
$message = '';
$error = '';
$sales_items = [];
$total_amount = 0;
$total_quantity = 0;
$sales_date = date('Y-m-d');
$sales_number = 'SALE-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));

// Handle Save Sales
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_sales'])) {
    $sales_date = $_POST['sales_date'] ?? date('Y-m-d');
    $sales_items = $_SESSION['sales_items'] ?? [];
    
    if (empty($sales_items)) {
        $error = 'No sales items to save. Please import CSV data first.';
    } else {
        $saved_count = 0;
        $error_items = [];
        
        $stmt = $conn->prepare("INSERT INTO sales_history (product_id, sale_date, quantity, unit_price) VALUES (?, ?, ?, ?)");
        
        foreach ($sales_items as $item) {
            // Clean product name (trim whitespace)
            $product_name = trim($item['product']);
            
            // Find product by name (case-insensitive) or SKU
            $prod_stmt = $conn->prepare("SELECT id FROM products WHERE LOWER(TRIM(name)) = LOWER(?) OR LOWER(TRIM(sku)) = LOWER(?) LIMIT 1");
            $prod_stmt->bind_param("ss", $product_name, $product_name);
            $prod_stmt->execute();
            $prod_result = $prod_stmt->get_result();
            $prod_data = $prod_result->fetch_assoc();
            $prod_stmt->close();
            
            if ($prod_data) {
                $product_id = $prod_data['id'];
                $quantity = intval($item['quantity']);
                $unit_price = floatval($item['price']);
                
                $stmt->bind_param("isid", $product_id, $sales_date, $quantity, $unit_price);
                if ($stmt->execute()) {
                    $saved_count++;
                } else {
                    $error_items[] = $product_name . ' (SQL Error: ' . $conn->error . ')';
                }
            } else {
                $error_items[] = $product_name . ' (Product not found in database)';
            }
        }
        
        $stmt->close();
        
        if ($saved_count > 0) {
            $message = "Successfully saved $saved_count sales record(s) to database.";
            if (!empty($error_items)) {
                $error = "Some items could not be saved: " . implode(", ", array_slice($error_items, 0, 5));
            }
            // Clear session after successful save
            unset($_SESSION['sales_items']);
            $sales_items = [];
        } else {
            $error = "Failed to save sales data. " . (!empty($error_items) ? "Errors: " . implode(", ", array_slice($error_items, 0, 5)) : "");
        }
    }
}

// Handle Clear Data
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['clear_data'])) {
    unset($_SESSION['sales_items']);
    $sales_items = [];
    $message = 'Sales data cleared successfully.';
}

// Handle PDF Export
if (isset($_GET['export']) && $_GET['export'] == 'pdf') {
    $sales_items = $_SESSION['sales_items'] ?? [];
    
    if (empty($sales_items)) {
        $_SESSION['sales_export_error'] = 'No sales data to export.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
    
    // Calculate totals
    $export_total_amount = 0;
    $export_total_quantity = 0;
    foreach ($sales_items as $item) {
        $export_total_amount += $item['subtotal'];
        $export_total_quantity += $item['quantity'];
    }
    
    // Get exporter information
    $exporter_name = $_SESSION['full_name'] ?? 'Unknown User';
    $export_date = date('F d, Y h:i A');
    $logo_path = BASE_URL . 'assets/img/logo5.png';
    $sales_date = isset($_POST['sales_date']) ? $_POST['sales_date'] : date('Y-m-d');
    $sales_number = isset($_POST['sales_number']) ? $_POST['sales_number'] : ('SALE-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6)));
    
    // PDF export using HTML (browser print to PDF)
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Sales Report - <?php echo date('Y-m-d'); ?></title>
        <style>
            @page {
                margin: 1cm;
            }
            body { 
                font-family: Arial, sans-serif; 
                margin: 0;
                padding: 20px;
                position: relative;
            }
            .header {
                margin-bottom: 20px;
                border-bottom: 2px solid #333;
                padding-bottom: 15px;
                text-align: center;
            }
            .header-logo {
                margin-bottom: 15px;
            }
            .header-logo img {
                height: 80px;
                width: auto;
                max-width: 200px;
            }
            .header h1 {
                color: #333;
                margin: 10px 0 5px 0;
                font-size: 24px;
                font-weight: bold;
            }
            .header .system-name {
                color: #555;
                font-size: 18px;
                margin: 5px 0;
                font-weight: 600;
            }
            .header .company-info {
                color: #777;
                font-size: 12px;
                margin: 5px 0;
                font-style: italic;
            }
            .report-info {
                margin-bottom: 20px;
                color: #666;
                font-size: 12px;
                line-height: 1.8;
            }
            .report-info strong {
                color: #333;
            }
            .summary-section {
                margin-bottom: 20px;
                padding: 15px;
                background-color: #f5f5f5;
                border-radius: 5px;
            }
            .summary-section h3 {
                margin-top: 0;
                color: #333;
            }
            table { 
                width: 100%; 
                border-collapse: collapse; 
                margin-top: 20px;
                font-size: 11px;
            }
            th, td { 
                border: 1px solid #ddd; 
                padding: 8px; 
                text-align: left; 
            }
            th { 
                background-color: #f2f2f2; 
                font-weight: bold;
                color: #333;
            }
            tr:nth-child(even) { 
                background-color: #f9f9f9; 
            }
            .text-right {
                text-align: right;
            }
            .text-center {
                text-align: center;
            }
            .total-row {
                background-color: #f2f2f2;
                font-weight: bold;
            }
            .no-print { 
                display: none; 
            }
            @media print {
                body { margin: 0; }
                .no-print { display: none; }
            }
        </style>
        <script>
            window.onload = function() {
                window.print();
            };
        </script>
    </head>
    <body>
        <div class="header">
            <div class="header-logo">
                <img src="<?php echo htmlspecialchars($logo_path); ?>" alt="Supply Chain Management Logo">
            </div>
            <div class="system-name">CHAINIFY</div>
            <div class="company-info">Supply Chain Management System</div>
            <h1>Sales Report</h1>
        </div>
        
        <div class="report-info">
            <strong>Export Date:</strong> <?php echo htmlspecialchars($export_date); ?><br>
            <strong>Exported By:</strong> <?php echo htmlspecialchars($exporter_name); ?><br>
            <strong>Sales Date:</strong> <?php echo date('M d, Y', strtotime($sales_date)); ?><br>
            <strong>Sales Number:</strong> <?php echo htmlspecialchars($sales_number); ?><br>
        </div>
        
        <div class="summary-section">
            <h3>Summary Overview</h3>
            <p><strong>Total Items:</strong> <?php echo count($sales_items); ?></p>
            <p><strong>Total Quantity:</strong> <?php echo number_format($export_total_quantity, 2); ?></p>
            <p><strong>Total Amount:</strong> <?php echo formatCurrency($export_total_amount); ?></p>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th style="width: 40px;">#</th>
                    <th>Product</th>
                    <th class="text-right">Quantity</th>
                    <th class="text-right">Price</th>
                    <th class="text-right">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $row_num = 1;
                foreach ($sales_items as $item): 
                ?>
                    <tr>
                        <td class="text-center"><?php echo $row_num++; ?></td>
                        <td><?php echo htmlspecialchars($item['product']); ?></td>
                        <td class="text-right"><?php echo number_format($item['quantity'], 2); ?></td>
                        <td class="text-right"><?php echo formatCurrency($item['price']); ?></td>
                        <td class="text-right"><?php echo formatCurrency($item['subtotal']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <td colspan="4" class="text-right" style="padding: 15px; font-size: 1.1em;">Total Amount:</td>
                    <td class="text-right" style="padding: 15px; font-size: 1.2em;"><?php echo formatCurrency($export_total_amount); ?></td>
                </tr>
            </tfoot>
        </table>
        
        <div class="no-print" style="margin-top: 20px;">
            <button onclick="window.print()">Print/Save as PDF</button>
            <button onclick="window.close()">Close</button>
        </div>
    </body>
    </html>
    <?php
    closeDBConnection($conn);
    exit();
}

// Handle CSV Import
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['import_csv']) && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];
    $import_errors = [];
    $import_success = 0;
    
    if ($file['error'] == UPLOAD_ERR_OK && ($file['type'] == 'text/csv' || $file['type'] == 'application/vnd.ms-excel' || pathinfo($file['name'], PATHINFO_EXTENSION) == 'csv')) {
        $handle = fopen($file['tmp_name'], 'r');
        
        // Read header row (skip it)
        $headers = fgetcsv($handle);
        
        // Validate header format
        if ($headers && count($headers) >= 3) {
            $header_lower = array_map('strtolower', array_map('trim', $headers));
            $has_product = in_array('product', $header_lower);
            $has_quantity = in_array('quantity', $header_lower);
            $has_price = in_array('price', $header_lower);
            
            if (!$has_product || !$has_quantity || !$has_price) {
                // Try to parse by position if headers don't match exactly
                $rowNum = 1;
                while (($data = fgetcsv($handle)) !== FALSE) {
                    $rowNum++;
                    if (count($data) < 3) {
                        $import_errors[] = "Row $rowNum: Insufficient columns (expected: Product, Quantity, Price)";
                        continue;
                    }
                    
                    $product = trim($data[0] ?? '');
                    $quantity = trim($data[1] ?? '0');
                    $price = trim($data[2] ?? '0');
                    
                    if (empty($product)) {
                        $import_errors[] = "Row $rowNum: Product name is required";
                        continue;
                    }
                    
                    if (!is_numeric($quantity) || $quantity <= 0) {
                        $import_errors[] = "Row $rowNum: Invalid quantity";
                        continue;
                    }
                    
                    if (!is_numeric($price) || $price < 0) {
                        $import_errors[] = "Row $rowNum: Invalid price";
                        continue;
                    }
                    
                    $sales_items[] = [
                        'product' => $product,
                        'quantity' => floatval($quantity),
                        'price' => floatval($price),
                        'subtotal' => floatval($quantity) * floatval($price)
                    ];
                    $import_success++;
                }
            } else {
                // Parse by header names
                $product_idx = array_search('product', $header_lower);
                $quantity_idx = array_search('quantity', $header_lower);
                $price_idx = array_search('price', $header_lower);
                
                $rowNum = 1;
                while (($data = fgetcsv($handle)) !== FALSE) {
                    $rowNum++;
                    if (count($data) < 3) {
                        $import_errors[] = "Row $rowNum: Insufficient columns";
                        continue;
                    }
                    
                    $product = trim($data[$product_idx] ?? '');
                    $quantity = trim($data[$quantity_idx] ?? '0');
                    $price = trim($data[$price_idx] ?? '0');
                    
                    if (empty($product)) {
                        $import_errors[] = "Row $rowNum: Product name is required";
                        continue;
                    }
                    
                    if (!is_numeric($quantity) || $quantity <= 0) {
                        $import_errors[] = "Row $rowNum: Invalid quantity";
                        continue;
                    }
                    
                    if (!is_numeric($price) || $price < 0) {
                        $import_errors[] = "Row $rowNum: Invalid price";
                        continue;
                    }
                    
                    $sales_items[] = [
                        'product' => $product,
                        'quantity' => floatval($quantity),
                        'price' => floatval($price),
                        'subtotal' => floatval($quantity) * floatval($price)
                    ];
                    $import_success++;
                }
            }
        } else {
            // No header or wrong format, try parsing by position
            rewind($handle);
            $rowNum = 0;
            while (($data = fgetcsv($handle)) !== FALSE) {
                $rowNum++;
                if ($rowNum == 1 && (strtolower(trim($data[0] ?? '')) == 'product' || strtolower(trim($data[0] ?? '')) == 'product name')) {
                    continue; // Skip header row
                }
                
                if (count($data) < 3) {
                    $import_errors[] = "Row $rowNum: Insufficient columns (expected: Product, Quantity, Price)";
                    continue;
                }
                
                $product = trim($data[0] ?? '');
                $quantity = trim($data[1] ?? '0');
                $price = trim($data[2] ?? '0');
                
                if (empty($product)) {
                    $import_errors[] = "Row $rowNum: Product name is required";
                    continue;
                }
                
                if (!is_numeric($quantity) || $quantity <= 0) {
                    $import_errors[] = "Row $rowNum: Invalid quantity";
                    continue;
                }
                
                if (!is_numeric($price) || $price < 0) {
                    $import_errors[] = "Row $rowNum: Invalid price";
                    continue;
                }
                
                $sales_items[] = [
                    'product' => $product,
                    'quantity' => floatval($quantity),
                    'price' => floatval($price),
                    'subtotal' => floatval($quantity) * floatval($price)
                ];
                $import_success++;
            }
        }
        
        fclose($handle);
        
        if ($import_success > 0) {
            $message = "Successfully imported $import_success item(s).";
            if (!empty($import_errors)) {
                $error = "Some rows had errors: " . implode("; ", array_slice($import_errors, 0, 5));
                if (count($import_errors) > 5) {
                    $error .= " and " . (count($import_errors) - 5) . " more.";
                }
            }
        } else {
            $error = "No valid items were imported. " . (!empty($import_errors) ? implode("; ", array_slice($import_errors, 0, 5)) : "Please check your CSV format.");
        }
    } else {
        $error = 'Invalid file. Please upload a CSV file.';
    }
}

// Store sales items in session for display
if (!empty($sales_items)) {
    $_SESSION['sales_items'] = $sales_items;
} elseif (isset($_SESSION['sales_items'])) {
    $sales_items = $_SESSION['sales_items'];
}

// Calculate totals
foreach ($sales_items as $item) {
    $total_amount += $item['subtotal'];
    $total_quantity += $item['quantity'];
}

// Check for export error from session
if (isset($_SESSION['sales_export_error'])) {
    $error = $_SESSION['sales_export_error'];
    unset($_SESSION['sales_export_error']);
}

closeDBConnection($conn);

$pageTitle = 'Sales';
include '../includes/header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-cash-register"></i> Sales</h2>
    <div class="header-actions">
        <?php if (!empty($sales_items)): ?>
            <a href="?export=pdf" class="btn btn-secondary" target="_blank">
                <i class="fas fa-file-pdf"></i> Export PDF
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- Summary Cards -->
<?php if (!empty($sales_items)): ?>
<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 20px;">
    <div class="dashboard-card">
        <div class="card-body" style="text-align: center; padding: 15px;">
            <h3 style="margin: 0; color: #64748b; font-size: 0.85em; font-weight: normal; margin-bottom: 8px;">Total Items</h3>
            <p style="margin: 0; font-size: 1.8em; font-weight: bold; color: #2563eb;"><?php echo count($sales_items); ?></p>
        </div>
    </div>
    <div class="dashboard-card">
        <div class="card-body" style="text-align: center; padding: 15px;">
            <h3 style="margin: 0; color: #64748b; font-size: 0.85em; font-weight: normal; margin-bottom: 8px;">Total Quantity</h3>
            <p style="margin: 0; font-size: 1.8em; font-weight: bold; color: #10b981;"><?php echo number_format($total_quantity, 2); ?></p>
        </div>
    </div>
    <div class="dashboard-card">
        <div class="card-body" style="text-align: center; padding: 15px;">
            <h3 style="margin: 0; color: #64748b; font-size: 0.85em; font-weight: normal; margin-bottom: 8px;">Total Amount</h3>
            <p style="margin: 0; font-size: 1.8em; font-weight: bold; color: #f59e0b;"><?php echo formatCurrency($total_amount); ?></p>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Import Section -->
<div class="dashboard-card" style="margin-bottom: 20px;">
    <div class="card-header">
        <h3><i class="fas fa-file-import"></i> Import Sales Data</h3>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" class="form-group">
            <div class="form-row">
                <div class="form-group" style="flex: 1;">
                    <label for="csv_file">CSV File</label>
                    <input type="file" id="csv_file" name="csv_file" accept=".csv" class="form-control" required>
                    <small class="form-text" style="display: block; margin-top: 5px; color: #64748b;">
                        <i class="fas fa-info-circle"></i> CSV format: Product, Quantity, Price
                    </small>
                </div>
            </div>
            <div class="form-row">
                <button type="submit" name="import_csv" class="btn btn-primary">
                    <i class="fas fa-upload"></i> Import CSV
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Sales Form -->
<div class="dashboard-card">
    <div class="card-header">
        <h3><i class="fas fa-receipt"></i> Sales Form</h3>
    </div>
    <div class="card-body">
        <?php if (empty($sales_items)): ?>
            <!-- Empty State -->
            <div style="text-align: center; padding: 60px 20px; background: #f8fafc; border-radius: 8px; border: 2px dashed #cbd5e1;">
                <i class="fas fa-file-csv" style="font-size: 4em; color: #cbd5e1; margin-bottom: 20px;"></i>
                <h3 style="color: #475569; margin-bottom: 10px;">No Sales Data</h3>
                <p style="color: #64748b; margin-bottom: 30px; max-width: 500px; margin-left: auto; margin-right: auto;">
                    Import a CSV file to get started. The CSV file should contain columns: Product, Quantity, and Price.
                </p>
            </div>
        <?php else: ?>
            <form method="POST" id="salesForm" class="form-group">
                <div class="form-row" style="margin-bottom: 20px;">
                    <div class="form-group">
                        <label for="sales_date">Sales Date</label>
                        <input type="date" id="sales_date" name="sales_date" class="form-control" value="<?php echo htmlspecialchars($sales_date); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Sales Number</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($sales_number); ?>" readonly style="background-color: #f3f4f6;">
                        <small class="form-text" style="display: block; margin-top: 5px; color: #64748b;">Auto-generated</small>
                    </div>
                </div>
                
                <div class="table-responsive" style="margin-top: 1.5rem; margin-bottom: 20px;">
                    <table class="data-table" id="salesItemsTable">
                        <thead>
                            <tr>
                                <th style="width: 40px;">#</th>
                                <th>Product</th>
                                <th style="width: 150px;">Quantity</th>
                                <th style="width: 150px;">Price</th>
                                <th style="width: 150px;">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody id="salesItemsBody">
                            <?php 
                            $row_num = 1;
                            foreach ($sales_items as $item): 
                            ?>
                                <tr>
                                    <td style="text-align: center; color: #64748b;"><?php echo $row_num++; ?></td>
                                    <td>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($item['product']); ?>" readonly>
                                    </td>
                                    <td>
                                        <input type="text" class="form-control quantity-input" value="<?php echo number_format($item['quantity'], 2); ?>" readonly style="text-align: right;">
                                    </td>
                                    <td>
                                        <input type="text" class="form-control price-input" value="<?php echo formatCurrency($item['price']); ?>" readonly style="text-align: right;">
                                    </td>
                                    <td>
                                        <input type="text" class="form-control subtotal-input" value="<?php echo formatCurrency($item['subtotal']); ?>" readonly style="text-align: right; font-weight: bold;">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background-color: #f8fafc;">
                                <th colspan="4" class="text-right" style="padding: 15px; font-size: 1.1em;">Total Amount:</th>
                                <th style="padding: 15px;">
                                    <input type="text" id="totalAmount" class="form-control" value="<?php echo formatCurrency($total_amount); ?>" readonly style="font-weight: bold; font-size: 1.2em; text-align: right; background-color: #fff;">
                                </th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
                <!-- Action Buttons -->
                <div style="display: flex; gap: 10px; justify-content: flex-end; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                    <button type="button" class="btn btn-secondary" onclick="clearSalesData()">
                        <i class="fas fa-trash"></i> Clear Data
                    </button>
                    <button type="submit" name="save_sales" class="btn btn-primary" onclick="return confirmSave()">
                        <i class="fas fa-save"></i> Save Sales
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Clear Data Confirmation Form -->
<form method="POST" id="clearForm" style="display: none;">
    <input type="hidden" name="clear_data" value="1">
</form>

<script>
// Confirm save
function confirmSave() {
    return confirm('Are you sure you want to save this sales data to the database? This action cannot be undone.');
}

// Clear sales data
function clearSalesData() {
    if (confirm('Are you sure you want to clear all sales data? This action cannot be undone.')) {
        document.getElementById('clearForm').submit();
    }
}

// Auto-calculate total amount on page load
document.addEventListener('DOMContentLoaded', function() {
    function calculateTotal() {
        let total = 0;
        const rows = document.querySelectorAll('#salesItemsBody tr');
        
        rows.forEach(row => {
            const subtotalInput = row.querySelector('.subtotal-input');
            if (subtotalInput) {
                const subtotalText = subtotalInput.value.replace(/[₱,\s]/g, '');
                total += parseFloat(subtotalText) || 0;
            }
        });
        
        const totalAmountInput = document.getElementById('totalAmount');
        if (totalAmountInput) {
            const totalFormatted = '₱' + total.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            totalAmountInput.value = totalFormatted;
        }
    }
    
    // Calculate on page load
    calculateTotal();
});
</script>

<?php include '../includes/footer.php'; ?>
