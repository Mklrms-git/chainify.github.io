<?php
require_once '../config/config.php';
requireModuleAccess('demand_forecasting');

$conn = getDBConnection();
$message = '';
$error = '';


// Handle export
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    // Add BOM for UTF-8 Excel compatibility
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="demand_forecast_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, ['Product Name', 'SKU', 'Forecast Period', 'Forecasted Quantity', 'Confidence Level', 'Created By', 'Created Date']);
    
    $export_query = "
        SELECT df.*, p.name as product_name, p.sku, u.full_name as created_by_name
        FROM demand_forecasts df
        JOIN products p ON df.product_id = p.id
        JOIN users u ON df.created_by = u.id
        ORDER BY df.forecast_period DESC, df.created_at DESC
    ";
    
    $result = $conn->query($export_query);
    if ($result !== false) {
        while ($row = $result->fetch_assoc()) {
            // Format dates properly for Excel (YYYY-MM-DD format)
            $forecast_period = !empty($row['forecast_period']) ? date('Y-m-d', strtotime($row['forecast_period'])) : '';
            $created_date = !empty($row['created_at']) ? date('Y-m-d H:i:s', strtotime($row['created_at'])) : '';
            
            fputcsv($output, [
                $row['product_name'],
                $row['sku'],
                $forecast_period,
                $row['forecasted_quantity'],
                number_format($row['confidence_level'], 2) . '%',
                $row['created_by_name'],
                $created_date
            ]);
        }
    }
    
    fclose($output);
    closeDBConnection($conn);
    exit();
}

// Adjust production plan (create purchase order from forecast)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['adjust_production'])) {
    requireCreatePermission('procurement');
    $forecast_id = $_POST['forecast_id'] ?? 0;
    $supplier_id = $_POST['supplier_id'] ?? 0;
    $quantity = $_POST['quantity'] ?? 0;
    $delivery_date = $_POST['delivery_date'] ?? '';
    
    if ($forecast_id && $supplier_id && $quantity && $delivery_date) {
        // Get forecast details
        $stmt = $conn->prepare("SELECT df.*, p.id as product_id, p.name as product_name, p.unit_price FROM demand_forecasts df JOIN products p ON df.product_id = p.id WHERE df.id = ?");
        $stmt->bind_param("i", $forecast_id);
        $stmt->execute();
        $forecast_data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($forecast_data) {
            // Create purchase order
            $po_number = 'PO-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
            $unit_price = $forecast_data['unit_price'] ?? 0;
            $total_amount = $quantity * $unit_price;
            
            $stmt = $conn->prepare("INSERT INTO purchase_orders (po_number, supplier_id, created_by, order_date, delivery_date, total_amount, notes) VALUES (?, ?, ?, CURDATE(), ?, ?, ?)");
            $notes = "Generated from Forecast #{$forecast_id} for {$forecast_data['product_name']}";
            $stmt->bind_param("siisds", $po_number, $supplier_id, $_SESSION['user_id'], $delivery_date, $total_amount, $notes);
            
            if ($stmt->execute()) {
                $po_id = $stmt->insert_id;
                
                // Insert purchase order item
                $item_stmt = $conn->prepare("INSERT INTO purchase_order_items (po_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)");
                $subtotal = $quantity * $unit_price;
                $item_stmt->bind_param("iiidd", $po_id, $forecast_data['product_id'], $quantity, $unit_price, $subtotal);
                $item_stmt->execute();
                $item_stmt->close();
                
                $message = "Purchase Order {$po_number} created successfully from forecast!";
            } else {
                $error = 'Failed to create purchase order.';
            }
            $stmt->close();
        } else {
            $error = 'Forecast not found.';
        }
    } else {
        $error = 'Please fill all required fields.';
    }
}

// Generate forecast for all products from Sales data
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['generate_forecast'])) {
    requireCreatePermission('demand_forecasting');
    $forecast_period = $_POST['forecast_period'] ?? date('Y-m-d', strtotime('+1 month'));
    $sort_by = $_POST['sort_by'] ?? 'quantity'; // 'quantity' or 'sales'
    
    // Try to get sales data from database first (sales_history table)
    // If no data in database, fall back to session data
    $sales_items = [];
    $sales_from_db = [];
    
    // Get recent sales from database (last 90 days)
    $db_sales_query = "
        SELECT p.name as product, sh.quantity, sh.unit_price as price, 
               (sh.quantity * sh.unit_price) as subtotal, sh.sale_date
        FROM sales_history sh
        JOIN products p ON sh.product_id = p.id
        WHERE sh.sale_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
        ORDER BY sh.sale_date DESC
    ";
    $db_result = $conn->query($db_sales_query);
    if ($db_result !== false && $db_result->num_rows > 0) {
        while ($row = $db_result->fetch_assoc()) {
            $sales_from_db[] = [
                'product' => $row['product'],
                'quantity' => floatval($row['quantity']),
                'price' => floatval($row['price']),
                'subtotal' => floatval($row['subtotal'])
            ];
        }
    }
    
    // Use database sales if available, otherwise use session data
    $data_source = '';
    if (!empty($sales_from_db)) {
        $sales_items = $sales_from_db;
        $data_source = 'database (sales_history table)';
    } else {
        $sales_items = $_SESSION['sales_items'] ?? [];
        $data_source = 'session (imported CSV)';
    }
    
    if (empty($sales_items)) {
        $error = 'No sales data available. Please import sales data from the Sales tab and save it to database first, or ensure you have sales_history records in the database.';
    } else {
        // Group sales items by product and calculate totals
        $product_sales = [];
        foreach ($sales_items as $item) {
            $product_name = $item['product'];
            if (!isset($product_sales[$product_name])) {
                $product_sales[$product_name] = [
                    'product' => $product_name,
                    'total_quantity' => 0,
                    'total_sales' => 0,
                    'avg_price' => 0,
                    'count' => 0
                ];
            }
            $product_sales[$product_name]['total_quantity'] += $item['quantity'];
            $product_sales[$product_name]['total_sales'] += $item['subtotal'];
            $product_sales[$product_name]['count']++;
        }
        
        // Calculate average price for each product
        foreach ($product_sales as &$product) {
            $product['avg_price'] = $product['total_sales'] / $product['total_quantity'];
        }
        unset($product);
        
        // Sort products based on sort_by parameter
        if ($sort_by == 'sales') {
            uasort($product_sales, function($a, $b) {
                return $b['total_sales'] <=> $a['total_sales'];
            });
        } else {
            uasort($product_sales, function($a, $b) {
                return $b['total_quantity'] <=> $a['total_quantity'];
            });
        }
        
        // Get or create product IDs for each product
        $forecast_data = [];
        $forecast_insert_stmt = $conn->prepare("INSERT INTO demand_forecasts (product_id, forecast_period, forecasted_quantity, confidence_level, created_by) VALUES (?, ?, ?, ?, ?)");
        
        foreach ($product_sales as $product_name => $sales_data) {
            // Clean product name (trim whitespace)
            $clean_product_name = trim($product_name);
            
            // Try to find product by name (case-insensitive) or SKU
            $prod_stmt = $conn->prepare("SELECT id, min_stock_level FROM products WHERE LOWER(TRIM(name)) = LOWER(?) OR LOWER(TRIM(sku)) = LOWER(?) LIMIT 1");
            $prod_stmt->bind_param("ss", $clean_product_name, $clean_product_name);
            $prod_stmt->execute();
            $prod_result = $prod_stmt->get_result();
            $prod_data = $prod_result->fetch_assoc();
            $prod_stmt->close();
            
            if ($prod_data) {
                $product_id = $prod_data['id'];
                // Use sales quantity as forecast, with minimum of min_stock_level
                $forecasted_quantity = max($sales_data['total_quantity'], $prod_data['min_stock_level'] ?? 10);
                
                // Calculate confidence level based on data quality
                $confidence = min(95, 70 + ($sales_data['count'] * 5));
                
                // Insert forecast
                $forecast_insert_stmt->bind_param("isidi", $product_id, $forecast_period, $forecasted_quantity, $confidence, $_SESSION['user_id']);
                if ($forecast_insert_stmt->execute()) {
                    $forecast_data[] = [
                        'product_id' => $product_id,
                        'product_name' => $clean_product_name,
                        'forecasted_quantity' => $forecasted_quantity,
                        'total_quantity' => $sales_data['total_quantity'],
                        'total_sales' => $sales_data['total_sales'],
                        'avg_price' => $sales_data['avg_price'],
                        'confidence_level' => $confidence
                    ];
                } else {
                    // Log error but continue with other products
                    error_log("Failed to insert forecast for product: $clean_product_name - " . $conn->error);
                }
            } else {
                // Log missing product but continue
                error_log("Product not found for forecast: $clean_product_name");
            }
        }
        
        $forecast_insert_stmt->close();
        
        if (!empty($forecast_data)) {
            // Get sales history and forecast history for PDF
            $conn_pdf = getDBConnection();
            $sales_history_pdf = [];
            $forecast_history_pdf = [];
            
            // Get sales history (monthly for PDF)
            $result = $conn_pdf->query("
                SELECT DATE_FORMAT(sale_date, '%Y-%m') as period, 
                       SUM(quantity) as total_quantity,
                       COUNT(*) as order_count,
                       AVG(unit_price) as avg_price
                FROM sales_history
                GROUP BY DATE_FORMAT(sale_date, '%Y-%m')
                ORDER BY period DESC
                LIMIT 12
            ");
            if ($result !== false) {
                while ($row = $result->fetch_assoc()) {
                    $sales_history_pdf[] = $row;
                }
            }
            
            // Get forecast history (monthly for PDF)
            $result = $conn_pdf->query("
                SELECT DATE_FORMAT(forecast_period, '%Y-%m') as period,
                       SUM(forecasted_quantity) as total_forecasted_quantity,
                       AVG(confidence_level) as avg_confidence
                FROM demand_forecasts
                GROUP BY DATE_FORMAT(forecast_period, '%Y-%m')
                ORDER BY period DESC
                LIMIT 12
            ");
            if ($result !== false) {
                while ($row = $result->fetch_assoc()) {
                    $forecast_history_pdf[] = $row;
                }
            }
            closeDBConnection($conn_pdf);
            
            // Get exporter information
            $exporter_name = $_SESSION['full_name'] ?? 'Unknown User';
            $export_date = date('F d, Y h:i A');
            $logo_path = BASE_URL . 'assets/img/logo5.png';
            
            // Find highest values for highlighting
            $highest_quantity = 0;
            $highest_sales = 0;
            foreach ($forecast_data as $item) {
                if ($item['total_quantity'] > $highest_quantity) {
                    $highest_quantity = $item['total_quantity'];
                }
                if ($item['total_sales'] > $highest_sales) {
                    $highest_sales = $item['total_sales'];
                }
            }
            
            // PDF export using HTML (browser print to PDF) - same format as Reports tab
            header('Content-Type: text/html; charset=utf-8');
            closeDBConnection($conn);
            ?>
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Demand Forecast Report</title>
                <style>
                    @page {
                        margin: 1cm;
                        @top-center {
                            content: "";
                        }
                        @top-left {
                            content: "";
                        }
                        @top-right {
                            content: "";
                        }
                        @bottom-center {
                            content: "";
                        }
                        @bottom-left {
                            content: "";
                        }
                        @bottom-right {
                            content: "";
                        }
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
                    tr.highlight {
                        background-color: #fef3c7 !important;
                        font-weight: bold;
                    }
                    .no-print { 
                        display: none; 
                    }
                    @media print {
                        body { margin: 0; }
                        .no-print { display: none; }
                        @page {
                            margin-top: 0.5cm;
                            margin-bottom: 0.5cm;
                        }
                    }
                </style>
                <script>
                    window.onload = function() {
                        window.print();
                    };
                </script>
            </head>
            <body>
                <h1 style="text-align: center; margin-bottom: 20px; color: #333; font-size: 24px; font-weight: bold; border-bottom: 2px solid #333; padding-bottom: 15px;">Demand Forecast Report</h1>
                
                <div class="report-info">
                    <strong>Export Date:</strong> <?php echo htmlspecialchars($export_date); ?><br>
                    <strong>Forecast Period:</strong> <?php echo date('F d, Y', strtotime($forecast_period)); ?><br>
                    <strong>Total Products:</strong> <?php echo count($forecast_data); ?><br>
                </div>
                
                <div class="summary-section">
                    <p><strong>Highest Quantity:</strong> <?php echo number_format($highest_quantity, 2); ?> units</p>
                    <p><strong>Highest Sales:</strong> <?php echo formatCurrency($highest_sales); ?></p>
                </div>
                
                <table>
                    <thead>
                        <tr>
                            <th>Product Name</th>
                            <th>Forecasted Quantity</th>
                            <th>Historical Quantity</th>
                            <th>Total Sales</th>
                            <th>Avg Price</th>
                            <th>Confidence Level</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($forecast_data as $item): 
                            $is_highlight = false;
                            if ($sort_by == 'quantity' && $item['total_quantity'] == $highest_quantity) {
                                $is_highlight = true;
                            } elseif ($sort_by == 'sales' && $item['total_sales'] == $highest_sales) {
                                $is_highlight = true;
                            }
                            $row_class = $is_highlight ? 'highlight' : '';
                        ?>
                        <tr class="<?php echo $row_class; ?>">
                            <td><?php echo htmlspecialchars($item['product_name']); ?><?php if ($is_highlight): ?> <strong>(TOP)</strong><?php endif; ?></td>
                            <td><?php echo number_format($item['forecasted_quantity'], 2); ?></td>
                            <td><?php echo number_format($item['total_quantity'], 2); ?></td>
                            <td><?php echo formatCurrency($item['total_sales']); ?></td>
                            <td><?php echo formatCurrency($item['avg_price']); ?></td>
                            <td><?php echo number_format($item['confidence_level'], 1); ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php if (!empty($sales_history_pdf) || !empty($forecast_history_pdf)): ?>
                <h2 style="margin-top: 40px; color: #333; border-bottom: 2px solid #333; padding-bottom: 10px;">Historical Sales & Trends</h2>
                
                <div class="summary-section" style="margin-top: 20px;">
                    <?php if (!empty($forecast_history_pdf)): 
                        $total_forecast_qty = array_sum(array_column($forecast_history_pdf, 'total_forecasted_quantity'));
                        $avg_confidence = 0;
                        $conf_count = 0;
                        foreach ($forecast_history_pdf as $item) {
                            if (isset($item['avg_confidence']) && $item['avg_confidence'] > 0) {
                                $avg_confidence += $item['avg_confidence'];
                                $conf_count++;
                            }
                        }
                        $avg_confidence = $conf_count > 0 ? $avg_confidence / $conf_count : 0;
                    ?>
                    <p><strong>Total Forecasted Quantity:</strong> <?php echo number_format($total_forecast_qty, 2); ?> units</p>
                    <p><strong>Average Confidence Level:</strong> <?php echo number_format($avg_confidence, 1); ?>%</p>
                    <?php endif; ?>
                </div>
                
                <table style="margin-top: 20px;">
                    <thead>
                        <tr>
                            <th>Period</th>
                            <th>Historical Sales (Qty)</th>
                            <?php if (!empty($sales_history_pdf[0]['avg_price'])): ?><th>Avg Price</th><?php endif; ?>
                            <th>Forecasted Demand (Qty)</th>
                            <?php if (!empty($forecast_history_pdf[0]['avg_confidence'])): ?><th>Avg Confidence (%)</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Combine sales and forecast data by period
                        $combined_data = [];
                        $periods = [];
                        
                        foreach ($sales_history_pdf as $item) {
                            $period = $item['period'];
                            $periods[$period] = true;
                            $combined_data[$period] = [
                                'period' => $period,
                                'sales_quantity' => $item['total_quantity'] ?? 0,
                                'avg_price' => $item['avg_price'] ?? 0
                            ];
                        }
                        
                        foreach ($forecast_history_pdf as $item) {
                            $period = $item['period'];
                            $periods[$period] = true;
                            if (!isset($combined_data[$period])) {
                                $combined_data[$period] = [
                                    'period' => $period,
                                    'sales_quantity' => 0,
                                    'avg_price' => 0
                                ];
                            }
                            $combined_data[$period]['forecast_quantity'] = $item['total_forecasted_quantity'] ?? 0;
                            $combined_data[$period]['avg_confidence'] = $item['avg_confidence'] ?? 0;
                        }
                        
                        krsort($periods);
                        $sorted_periods = array_keys($periods);
                        
                        foreach ($sorted_periods as $period):
                            $data = $combined_data[$period] ?? ['period' => $period, 'sales_quantity' => 0, 'forecast_quantity' => 0, 'avg_price' => 0, 'avg_confidence' => 0];
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($data['period']); ?></td>
                            <td><?php echo number_format($data['sales_quantity'], 2); ?></td>
                            <?php if (!empty($sales_history_pdf[0]['avg_price'])): ?>
                            <td><?php echo formatCurrency($data['avg_price']); ?></td>
                            <?php endif; ?>
                            <td><?php echo number_format($data['forecast_quantity'] ?? 0, 2); ?></td>
                            <?php if (!empty($forecast_history_pdf[0]['avg_confidence'])): ?>
                            <td><?php echo number_format($data['avg_confidence'] ?? 0, 1); ?>%</td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </body>
            </html>
            <?php
            exit();
        } else {
            $error = 'Failed to generate forecasts. No matching products found in database.';
        }
    }
}

// Check if forecast was generated (for display purposes only, not for PDF download)
$forecast_generated = false;
$forecast_report_data = null;

// Get filter parameters
$chart_type = $_GET['chart_type'] ?? 'monthly'; // weekly, monthly, quarterly, yearly

// Get sales history for chart based on chart type
$sales_history = [];
$forecast_history = [];

// Get sales history based on chart type
if ($chart_type == 'yearly') {
    $result = $conn->query("
        SELECT DATE_FORMAT(sale_date, '%Y') as period, 
               SUM(quantity) as total_quantity,
               COUNT(*) as order_count,
               AVG(unit_price) as avg_price
        FROM sales_history
        GROUP BY DATE_FORMAT(sale_date, '%Y')
        ORDER BY period DESC
        LIMIT 10
    ");
    if ($result !== false) {
        while ($row = $result->fetch_assoc()) {
            $sales_history[] = $row;
        }
    }
    
    // Get forecast data aggregated by year
    $result = $conn->query("
        SELECT DATE_FORMAT(forecast_period, '%Y') as period,
               SUM(forecasted_quantity) as total_forecasted_quantity,
               AVG(confidence_level) as avg_confidence
        FROM demand_forecasts
        GROUP BY DATE_FORMAT(forecast_period, '%Y')
        ORDER BY period DESC
        LIMIT 10
    ");
    if ($result !== false) {
        while ($row = $result->fetch_assoc()) {
            $forecast_history[] = $row;
        }
    }
} elseif ($chart_type == 'quarterly') {
    $result = $conn->query("
        SELECT CONCAT(YEAR(sale_date), '-Q', QUARTER(sale_date)) as period,
               SUM(quantity) as total_quantity,
               COUNT(*) as order_count,
               AVG(unit_price) as avg_price
        FROM sales_history
        GROUP BY YEAR(sale_date), QUARTER(sale_date)
        ORDER BY YEAR(sale_date) DESC, QUARTER(sale_date) DESC
        LIMIT 12
    ");
    if ($result !== false) {
        while ($row = $result->fetch_assoc()) {
            $sales_history[] = $row;
        }
    }
    
    // Get forecast data aggregated by quarter
    $result = $conn->query("
        SELECT CONCAT(YEAR(forecast_period), '-Q', QUARTER(forecast_period)) as period,
               SUM(forecasted_quantity) as total_forecasted_quantity,
               AVG(confidence_level) as avg_confidence
        FROM demand_forecasts
        GROUP BY YEAR(forecast_period), QUARTER(forecast_period)
        ORDER BY YEAR(forecast_period) DESC, QUARTER(forecast_period) DESC
        LIMIT 12
    ");
    if ($result !== false) {
        while ($row = $result->fetch_assoc()) {
            $forecast_history[] = $row;
        }
    }
} elseif ($chart_type == 'weekly') {
    $result = $conn->query("
        SELECT DATE_FORMAT(sale_date, '%Y-%u') as period,
               YEARWEEK(sale_date) as yearweek,
               SUM(quantity) as total_quantity,
               COUNT(*) as order_count,
               AVG(unit_price) as avg_price
        FROM sales_history
        GROUP BY YEARWEEK(sale_date)
        ORDER BY YEARWEEK(sale_date) DESC
        LIMIT 12
    ");
    if ($result !== false) {
        while ($row = $result->fetch_assoc()) {
            $sales_history[] = $row;
        }
    }
    
    // Get forecast data aggregated by week
    $result = $conn->query("
        SELECT DATE_FORMAT(forecast_period, '%Y-%u') as period,
               YEARWEEK(forecast_period) as yearweek,
               SUM(forecasted_quantity) as total_forecasted_quantity,
               AVG(confidence_level) as avg_confidence
        FROM demand_forecasts
        GROUP BY YEARWEEK(forecast_period)
        ORDER BY YEARWEEK(forecast_period) DESC
        LIMIT 12
    ");
    if ($result !== false) {
        while ($row = $result->fetch_assoc()) {
            $forecast_history[] = $row;
        }
    }
} else { // monthly (default)
    $result = $conn->query("
        SELECT DATE_FORMAT(sale_date, '%Y-%m') as period, 
               SUM(quantity) as total_quantity,
               COUNT(*) as order_count,
               AVG(unit_price) as avg_price
        FROM sales_history
        GROUP BY DATE_FORMAT(sale_date, '%Y-%m')
        ORDER BY period DESC
        LIMIT 12
    ");
    if ($result !== false) {
        while ($row = $result->fetch_assoc()) {
            $sales_history[] = $row;
        }
    }
    
    // Get forecast data aggregated by month
    $result = $conn->query("
        SELECT DATE_FORMAT(forecast_period, '%Y-%m') as period,
               SUM(forecasted_quantity) as total_forecasted_quantity,
               AVG(confidence_level) as avg_confidence
        FROM demand_forecasts
        GROUP BY DATE_FORMAT(forecast_period, '%Y-%m')
        ORDER BY period DESC
        LIMIT 12
    ");
    if ($result !== false) {
        while ($row = $result->fetch_assoc()) {
            $forecast_history[] = $row;
        }
    }
}

// Get suppliers for production plan adjustment
$suppliers = [];
$result = $conn->query("SELECT id, company_name FROM suppliers ORDER BY company_name");
if ($result !== false) {
    while ($row = $result->fetch_assoc()) {
        $suppliers[] = $row;
    }
}

closeDBConnection($conn);

$pageTitle = 'Demand Forecasting & Planning';
include '../includes/header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-chart-line"></i> Demand Forecasting & Planning</h2>
    <div class="header-actions">
        <?php if (canCreate('demand_forecasting')): ?>
        <button class="btn btn-primary" onclick="openModal('generateForecastModal')">
            <i class="fas fa-plus"></i> Generate Forecast
        </button>
        <?php endif; ?>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- Filters Section -->
<div class="dashboard-card" style="margin-bottom: 20px;">
    <div class="card-header">
        <h3><i class="fas fa-filter"></i> Filters</h3>
    </div>
    <div class="card-body">
        <form method="GET" action="" class="filter-form" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end;">
            <div class="form-group">
                <label for="chart_type">Chart View</label>
                <select name="chart_type" id="chart_type" class="form-control" onchange="this.form.submit()">
                    <option value="weekly" <?php echo $chart_type == 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                    <option value="monthly" <?php echo $chart_type == 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                    <option value="quarterly" <?php echo $chart_type == 'quarterly' ? 'selected' : ''; ?>>Quarterly</option>
                    <option value="yearly" <?php echo $chart_type == 'yearly' ? 'selected' : ''; ?>>Yearly</option>
                </select>
            </div>
            
            <div class="form-group">
                <button type="button" class="btn btn-secondary" onclick="window.location.href='<?php echo $_SERVER['PHP_SELF']; ?>'">
                    <i class="fas fa-redo"></i> Reset
                </button>
            </div>
        </form>
    </div>
</div>

<div class="dashboard-grid">
    <!-- Forecast Dashboard -->
    <div class="dashboard-card">
        <div class="card-header">
            <h3><i class="fas fa-chart-bar"></i> Historical Sales Data & Trends</h3>
        </div>
        <div class="card-body">
            <?php if (empty($sales_history) && empty($forecast_history)): ?>
                <p class="text-muted">No sales history or forecast data available. Add sales data to view trends.</p>
            <?php else: ?>
                <canvas id="salesChart" height="300"></canvas>
            <?php endif; ?>
        </div>
    </div>
    
</div>

<!-- Generate Forecast Modal -->
<?php if (canCreate('demand_forecasting')): ?>
<div id="generateForecastModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Generate Demand Forecast</h3>
            <button class="modal-close" onclick="closeModal('generateForecastModal')">&times;</button>
        </div>
        <form method="POST" action="">
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> This will generate forecasts for ALL products based on sales data from the Sales tab.
            </div>
            
            <div class="form-group">
                <label for="forecast_period">Forecast Period (Target Date)</label>
                <input type="date" name="forecast_period" id="forecast_period" class="form-control" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                <small class="text-muted">Only present and past dates are allowed.</small>
            </div>
            
            <div class="form-group">
                <label for="sort_by">Highlight By</label>
                <select name="sort_by" id="sort_by" class="form-control" required>
                    <option value="quantity">Highest Quantity</option>
                    <option value="sales">Highest Sales</option>
                </select>
                <small class="text-muted">The report will highlight products with the highest quantity or sales based on your selection.</small>
            </div>
            
            <div class="form-group">
                <button type="submit" name="generate_forecast" class="btn btn-primary btn-block">
                    <i class="fas fa-file-pdf"></i> Export Forecast Report
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Adjust Production Plan Modal -->
<?php if (canCreate('procurement')): ?>
<div id="adjustProductionModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Adjust Production Plan</h3>
            <button class="modal-close" onclick="closeModal('adjustProductionModal')">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="forecast_id" id="adjust_forecast_id">
            
            <div class="form-group">
                <label>Product</label>
                <input type="text" id="adjust_product_name" class="form-control" readonly>
            </div>
            
            <div class="form-group">
                <label for="supplier_id">Supplier</label>
                <select name="supplier_id" id="supplier_id" class="form-control" required>
                    <option value="">Select Supplier</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?php echo $supplier['id']; ?>">
                            <?php echo htmlspecialchars($supplier['company_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="quantity">Procurement Quantity</label>
                <input type="number" name="quantity" id="adjust_quantity" class="form-control" min="1" required>
                <small class="text-muted">Forecasted quantity: <span id="forecasted_qty_display"></span></small>
            </div>
            
            <div class="form-group">
                <label for="delivery_date">Delivery Date</label>
                <input type="date" name="delivery_date" id="delivery_date" class="form-control" required>
            </div>
            
            <div class="form-group">
                <button type="submit" name="adjust_production" class="btn btn-primary btn-block">
                    <i class="fas fa-shopping-cart"></i> Create Purchase Order
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
<?php if (!empty($sales_history) || !empty($forecast_history)): ?>
const ctx = document.getElementById('salesChart');
if (ctx) {
    // Combine sales and forecast data by period
    <?php
    $combined_data = [];
    $periods = [];
    
    // Collect all periods from sales
    foreach ($sales_history as $item) {
        $period = $item['period'];
        $periods[$period] = true;
        $combined_data[$period] = [
            'period' => $period,
            'sales_quantity' => $item['total_quantity'] ?? 0,
            'avg_price' => $item['avg_price'] ?? 0
        ];
    }
    
    // Add forecast data
    foreach ($forecast_history as $item) {
        $period = $item['period'];
        $periods[$period] = true;
        if (!isset($combined_data[$period])) {
            $combined_data[$period] = [
                'period' => $period,
                'sales_quantity' => 0,
                'avg_price' => 0
            ];
        }
        $combined_data[$period]['forecast_quantity'] = $item['total_forecasted_quantity'] ?? 0;
    }
    
    // Sort periods
    ksort($periods);
    $sorted_periods = array_keys($periods);
    $sorted_data = [];
    foreach ($sorted_periods as $period) {
        $sorted_data[] = $combined_data[$period] ?? ['period' => $period, 'sales_quantity' => 0, 'forecast_quantity' => 0, 'avg_price' => 0];
    }
    
    $labels = array_map(function($item) { return "'" . $item['period'] . "'"; }, $sorted_data);
    $sales_data = array_map(function($item) { return $item['sales_quantity'] ?? 0; }, $sorted_data);
    $forecast_data = array_map(function($item) { return $item['forecast_quantity'] ?? 0; }, $sorted_data);
    ?>
    
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: [<?php echo implode(',', $labels); ?>],
            datasets: [{
                label: 'Historical Sales',
                data: [<?php echo implode(',', $sales_data); ?>],
                borderColor: 'rgb(37, 99, 235)',
                backgroundColor: 'rgba(37, 99, 235, 0.1)',
                tension: 0.4,
                fill: true
            }<?php if (!empty($forecast_data)): ?>, {
                label: 'Forecasted Demand',
                data: [<?php echo implode(',', $forecast_data); ?>],
                borderColor: 'rgb(34, 197, 94)',
                backgroundColor: 'rgba(34, 197, 94, 0.1)',
                tension: 0.4,
                borderDash: [5, 5],
                fill: true
            }<?php endif; ?>]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    position: 'left',
                    title: {
                        display: true,
                        text: 'Quantity'
                    }
                }
            },
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
                },
                tooltip: {
                    mode: 'index',
                    intersect: false
                }
            }
        }
    });
}
<?php endif; ?>

// Filter products by category in forecast modal
function filterProductsByCategory() {
    const category = document.getElementById('forecast_category').value;
    const productSelect = document.getElementById('product_id');
    const options = productSelect.querySelectorAll('option');
    
    options.forEach(option => {
        if (option.value === '') {
            option.style.display = 'block';
        } else {
            const optionCategory = option.getAttribute('data-category');
            if (!category || optionCategory === category) {
                option.style.display = 'block';
            } else {
                option.style.display = 'none';
            }
        }
    });
    
    // Reset selection if current selection is hidden
    if (productSelect.value && productSelect.options[productSelect.selectedIndex].style.display === 'none') {
        productSelect.value = '';
    }
}

// Open adjust production plan modal
function openAdjustModal(forecastId, forecastedQty, productName) {
    document.getElementById('adjust_forecast_id').value = forecastId;
    document.getElementById('adjust_product_name').value = productName;
    document.getElementById('adjust_quantity').value = forecastedQty;
    document.getElementById('forecasted_qty_display').textContent = forecastedQty.toLocaleString();
    
    // Set minimum delivery date to tomorrow
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    document.getElementById('delivery_date').min = tomorrow.toISOString().split('T')[0];
    
    openModal('adjustProductionModal');
}
</script>

<?php include '../includes/footer.php'; ?>


