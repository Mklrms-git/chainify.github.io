<?php
require_once '../config/config.php';
requireModuleAccess('inventory');

$conn = getDBConnection();
$message = '';
$error = '';

// Helper function to generate SKU from supplier initials
function generateSKUFromSupplier($supplierName) {
    global $conn;
    $words = preg_split('/\s+/', trim($supplierName));
    $initials = '';
    foreach ($words as $word) {
        if (!empty($word)) {
            $initials .= strtoupper(substr($word, 0, 1));
        }
    }
    
    if (empty($initials)) {
        $initials = 'SUP';
    }
    
    // Find next available number for this supplier prefix
    $num = 1;
    do {
        $sku = $initials . '-' . str_pad($num, 3, '0', STR_PAD_LEFT);
        $stmt = $conn->prepare("SELECT id FROM products WHERE sku = ?");
        $stmt->bind_param("s", $sku);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result->fetch_assoc();
        $stmt->close();
        
        if (!$exists) {
            return $sku;
        }
        $num++;
    } while ($num < 1000);
    
    // Fallback if all numbers are taken
    return $initials . '-' . time();
}

// CSV Bulk Import
// Accessible to: Admin, Warehouse Officer (via canCreate('inventory') permission)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['import_csv']) && isset($_FILES['csv_file'])) {
    requireCreatePermission('inventory');
    
    $file = $_FILES['csv_file'];
    $import_errors = [];
    $import_success = 0;
    
    if ($file['error'] == UPLOAD_ERR_OK && ($file['type'] == 'text/csv' || $file['type'] == 'application/vnd.ms-excel' || pathinfo($file['name'], PATHINFO_EXTENSION) == 'csv')) {
        $handle = fopen($file['tmp_name'], 'r');
        
        // Read header row
        $headers = fgetcsv($handle);
        if (!$headers || count($headers) < 6) {
            $error = 'Invalid CSV format. Expected columns: Product, [SKU], Warehouse, Quantity, Min Level, Supplier, Unit Price (SKU is optional)';
        } else {
            // Detect format: check if SKU column exists by examining header or first data row
            $has_sku_column = false;
            $header_count = count($headers);
            
            // Check header for SKU column
            $header_lower = array_map('strtolower', array_map('trim', $headers));
            if (in_array('sku', $header_lower)) {
                $has_sku_column = true;
            } elseif ($header_count >= 7) {
                // If 7+ columns, assume SKU is present
                $has_sku_column = true;
            }
            
            $rowNum = 1;
            $supplier_products = []; // Track products per supplier for updating products_supplied field
            while (($data = fgetcsv($handle)) !== FALSE) {
                $rowNum++;
                if (count($data) < 6) continue;
                
                // Parse data based on format
                if ($has_sku_column && count($data) >= 7) {
                    // Format: Product, SKU, Warehouse, Quantity, Min Level, Supplier, Unit Price
                    $product_name = trim($data[0] ?? '');
                    $sku = trim($data[1] ?? '');
                    $warehouse_name = trim($data[2] ?? '');
                    $quantity = intval($data[3] ?? 0);
                    $min_level = intval($data[4] ?? 10);
                    $supplier_name = trim($data[5] ?? '');
                    $unit_price = floatval($data[6] ?? 0);
                } else {
                    // Format: Product, Warehouse, Quantity, Min Level, Supplier, Unit Price (no SKU)
                    $product_name = trim($data[0] ?? '');
                    $sku = ''; // Will be auto-generated
                    $warehouse_name = trim($data[1] ?? '');
                    $quantity = intval($data[2] ?? 0);
                    $min_level = intval($data[3] ?? 10);
                    $supplier_name = trim($data[4] ?? '');
                    $unit_price = floatval($data[5] ?? 0);
                }
                
                if (empty($product_name) || empty($warehouse_name) || empty($supplier_name) || $quantity <= 0) {
                    $import_errors[] = "Row $rowNum: Missing required fields";
                    continue;
                }
                
                // Get or create supplier (check both active and inactive to avoid duplicates)
                $stmt = $conn->prepare("SELECT id, products_supplied FROM suppliers WHERE company_name = ?");
                $stmt->bind_param("s", $supplier_name);
                $stmt->execute();
                $result = $stmt->get_result();
                $supplier = $result->fetch_assoc();
                $stmt->close();
                
                if (!$supplier) {
                    // Create new supplier
                    $stmt = $conn->prepare("INSERT INTO suppliers (company_name, status) VALUES (?, 'active')");
                    $stmt->bind_param("s", $supplier_name);
                    $stmt->execute();
                    $supplier_id = $conn->insert_id;
                    $stmt->close();
                } else {
                    $supplier_id = $supplier['id'];
                    // Reactivate if inactive
                    $stmt = $conn->prepare("UPDATE suppliers SET status = 'active' WHERE id = ?");
                    $stmt->bind_param("i", $supplier_id);
                    $stmt->execute();
                    $stmt->close();
                }
                
                // Generate SKU if not provided
                if (empty($sku)) {
                    $sku = generateSKUFromSupplier($supplier_name);
                }
                
                // Get or create product - always create new product for each CSV row to avoid conflicts
                // Check if product with same name and SKU exists
                $stmt = $conn->prepare("SELECT id FROM products WHERE name = ? AND sku = ?");
                $stmt->bind_param("ss", $product_name, $sku);
                $stmt->execute();
                $result = $stmt->get_result();
                $existing_product = $result->fetch_assoc();
                $stmt->close();
                
                if ($existing_product) {
                    // Product exists, use it and update details
                    $product_id = $existing_product['id'];
                    $stmt = $conn->prepare("UPDATE products SET min_stock_level = ?, unit_price = ? WHERE id = ?");
                    $stmt->bind_param("idi", $min_level, $unit_price, $product_id);
                    $stmt->execute();
                    $stmt->close();
                } else {
                    // Create new product - ensure unique SKU if it already exists
                    $stmt = $conn->prepare("SELECT id FROM products WHERE sku = ?");
                    $stmt->bind_param("s", $sku);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $sku_exists = $result->fetch_assoc();
                    $stmt->close();
                    
                    // If SKU exists, generate a new one
                    if ($sku_exists) {
                        $sku = generateSKUFromSupplier($supplier_name);
                    }
                    
                    // Insert new product
                    $stmt = $conn->prepare("INSERT INTO products (sku, name, min_stock_level, unit_price) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("ssid", $sku, $product_name, $min_level, $unit_price);
                    if ($stmt->execute()) {
                        $product_id = $conn->insert_id;
                    } else {
                        $import_errors[] = "Row $rowNum: Failed to create product - " . $stmt->error;
                        $stmt->close();
                        continue;
                    }
                    $stmt->close();
                }
                
                // Ensure product is linked to supplier (check without status filter)
                $stmt = $conn->prepare("SELECT id, status FROM supplier_products WHERE supplier_id = ? AND product_id = ?");
                $stmt->bind_param("ii", $supplier_id, $product_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $link = $result->fetch_assoc();
                $stmt->close();
                
                if (!$link) {
                    // Unset other primary suppliers for this product
                    $stmt = $conn->prepare("UPDATE supplier_products SET is_primary = 0 WHERE product_id = ?");
                    $stmt->bind_param("i", $product_id);
                    $stmt->execute();
                    $stmt->close();
                    
                    // Insert new link as primary
                    $stmt = $conn->prepare("INSERT INTO supplier_products (supplier_id, product_id, is_primary, status) VALUES (?, ?, 1, 'active')");
                    $stmt->bind_param("ii", $supplier_id, $product_id);
                    if (!$stmt->execute()) {
                        $import_errors[] = "Row $rowNum: Failed to link supplier - " . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    // Update existing link to ensure it's active and primary
                    $stmt = $conn->prepare("UPDATE supplier_products SET is_primary = 1, status = 'active' WHERE id = ?");
                    $stmt->bind_param("i", $link['id']);
                    $stmt->execute();
                    $stmt->close();
                }
                
                // Get warehouse
                $stmt = $conn->prepare("SELECT id FROM warehouses WHERE name = ? AND status = 'active'");
                $stmt->bind_param("s", $warehouse_name);
                $stmt->execute();
                $result = $stmt->get_result();
                $warehouse = $result->fetch_assoc();
                $stmt->close();
                
                if (!$warehouse) {
                    $import_errors[] = "Row $rowNum: Warehouse '$warehouse_name' not found";
                    continue;
                }
                
                $warehouse_id = $warehouse['id'];
                
                // Update inventory
                $stmt = $conn->prepare("SELECT id, quantity FROM inventory WHERE product_id = ? AND warehouse_id = ?");
                $stmt->bind_param("ii", $product_id, $warehouse_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $existing = $result->fetch_assoc();
                $stmt->close();
                
                if ($existing) {
                    $new_quantity = $existing['quantity'] + $quantity;
                    $stmt = $conn->prepare("UPDATE inventory SET quantity = ? WHERE id = ?");
                    $stmt->bind_param("ii", $new_quantity, $existing['id']);
                    $stmt->execute();
                    $stmt->close();
                } else {
                    $stmt = $conn->prepare("INSERT INTO inventory (product_id, warehouse_id, quantity) VALUES (?, ?, ?)");
                    $stmt->bind_param("iii", $product_id, $warehouse_id, $quantity);
                    $stmt->execute();
                    $stmt->close();
                }
                
                // Record movement
                $notes = "Bulk import from CSV";
                $stmt = $conn->prepare("INSERT INTO stock_movements (product_id, warehouse_id, movement_type, quantity, notes, created_by) VALUES (?, ?, 'in', ?, ?, ?)");
                $stmt->bind_param("iiisi", $product_id, $warehouse_id, $quantity, $notes, $_SESSION['user_id']);
                $stmt->execute();
                $stmt->close();
                
                // Track product for this supplier (after successful processing)
                if (!isset($supplier_products[$supplier_id])) {
                    $supplier_products[$supplier_id] = [];
                }
                if (!in_array($product_name, $supplier_products[$supplier_id])) {
                    $supplier_products[$supplier_id][] = $product_name;
                }
                
                $import_success++;
            }
            fclose($handle);
            
            // Update products_supplied field for each supplier
            foreach ($supplier_products as $supplier_id => $product_names) {
                $products_list = implode(', ', $product_names);
                $stmt = $conn->prepare("UPDATE suppliers SET products_supplied = ? WHERE id = ?");
                $stmt->bind_param("si", $products_list, $supplier_id);
                $stmt->execute();
                $stmt->close();
            }
            
            if ($import_success > 0) {
                $message = "Successfully imported $import_success product(s).";
                if (!empty($import_errors)) {
                    $message .= " " . count($import_errors) . " error(s) occurred.";
                }
            }
            if (!empty($import_errors)) {
                $error = implode("<br>", array_slice($import_errors, 0, 10));
                if (count($import_errors) > 10) {
                    $error .= "<br>... and " . (count($import_errors) - 10) . " more errors";
                }
            }
        }
    } else {
        $error = 'Please upload a valid CSV file.';
    }
}

// Add/Update Stock
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_stock'])) {
    requireCreatePermission('inventory');
    $product_id = $_POST['product_id'] ?? 0;
    $warehouse_id = $_POST['warehouse_id'] ?? 0;
    $quantity = $_POST['quantity'] ?? 0;
    $movement_type = $_POST['movement_type'] ?? 'adjustment';
    $notes = $_POST['notes'] ?? '';
    
    if ($product_id && $warehouse_id) {
        // Check if inventory record exists
        $stmt = $conn->prepare("SELECT id, quantity FROM inventory WHERE product_id = ? AND warehouse_id = ?");
        $stmt->bind_param("ii", $product_id, $warehouse_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $existing = $result->fetch_assoc();
        $stmt->close();
        
        if ($existing) {
            // Update existing
            $new_quantity = $movement_type === 'in' ? $existing['quantity'] + $quantity : 
                          ($movement_type === 'out' ? max(0, $existing['quantity'] - $quantity) : $quantity);
            
            $stmt = $conn->prepare("UPDATE inventory SET quantity = ? WHERE id = ?");
            $stmt->bind_param("ii", $new_quantity, $existing['id']);
            $stmt->execute();
            $stmt->close();
        } else {
            // Insert new
            if ($movement_type === 'in' || $movement_type === 'adjustment') {
                $stmt = $conn->prepare("INSERT INTO inventory (product_id, warehouse_id, quantity) VALUES (?, ?, ?)");
                $stmt->bind_param("iii", $product_id, $warehouse_id, $quantity);
                $stmt->execute();
                $stmt->close();
            }
        }
        
        // Record movement
        $stmt = $conn->prepare("INSERT INTO stock_movements (product_id, warehouse_id, movement_type, quantity, notes, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iisiis", $product_id, $warehouse_id, $movement_type, $quantity, $notes, $_SESSION['user_id']);
        $stmt->execute();
        $stmt->close();
        
        $message = 'Stock updated successfully!';
    } else {
        $error = 'Please select product and warehouse.';
    }
}

// Bulk Delete Inventory Records (Admin Only - Warehouse Officer cannot delete, only adjust)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_delete']) && isset($_POST['selected_items']) && !isset($_POST['delete_products'])) {
    requireLogin();
    if (!canDelete('inventory')) {
        header('Location: ' . BASE_URL . 'dashboard.php?error=permission_denied');
        exit();
    }
    
    $selected_ids = $_POST['selected_items'];
    $deleted_count = 0;
    $errors = [];
    
    if (!empty($selected_ids) && is_array($selected_ids)) {
        foreach ($selected_ids as $inventory_id) {
            $inventory_id = intval($inventory_id);
            if ($inventory_id > 0) {
                // Get inventory record for logging
                $stmt = $conn->prepare("SELECT product_id, warehouse_id, quantity FROM inventory WHERE id = ?");
                $stmt->bind_param("i", $inventory_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $inventory_record = $result->fetch_assoc();
                $stmt->close();
                
                if ($inventory_record) {
                    // Delete the inventory record
                    $stmt = $conn->prepare("DELETE FROM inventory WHERE id = ?");
                    $stmt->bind_param("i", $inventory_id);
                    
                    if ($stmt->execute()) {
                        $deleted_count++;
                        // Record movement for audit trail
                        $notes = "Bulk delete by admin";
                        $movement_stmt = $conn->prepare("INSERT INTO stock_movements (product_id, warehouse_id, movement_type, quantity, notes, created_by) VALUES (?, ?, 'out', ?, ?, ?)");
                        $movement_stmt->bind_param("iiisi", $inventory_record['product_id'], $inventory_record['warehouse_id'], $inventory_record['quantity'], $notes, $_SESSION['user_id']);
                        $movement_stmt->execute();
                        $movement_stmt->close();
                    } else {
                        $errors[] = "Failed to delete inventory record ID: $inventory_id";
                    }
                    $stmt->close();
                } else {
                    $errors[] = "Inventory record ID $inventory_id not found";
                }
            }
        }
        
        if ($deleted_count > 0) {
            $message = "Successfully deleted $deleted_count inventory record(s).";
            if (!empty($errors)) {
                $error = implode("<br>", $errors);
            }
        } else {
            $error = !empty($errors) ? implode("<br>", $errors) : 'No records were deleted.';
        }
    } else {
        $error = 'Please select at least one inventory record to delete.';
    }
}

// Helper function to check if product can be deleted
function checkProductCanBeDeleted($conn, $product_id) {
    // Tables that reference products and block deletion (no CASCADE)
    $tables = [
        'stock_movements' => 'stock movement records',
        'purchase_order_items' => 'purchase order items',
        'shipment_items' => 'shipment items',
        'sales_history' => 'sales history records',
        'demand_forecasts' => 'demand forecast records',
        'transfer_items' => 'transfer items'
    ];
    
    $blocking = [];
    foreach ($tables as $table => $label) {
        $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM $table WHERE product_id = ?");
        $stmt->bind_param("i", $product_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->fetch_assoc()['cnt'] > 0) {
            $blocking[] = $label;
        }
        $stmt->close();
    }
    
    // Check inventory separately (has CASCADE but we want to inform user)
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM inventory WHERE product_id = ?");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->fetch_assoc()['cnt'] > 0) {
        $blocking[] = 'inventory records';
    }
    $stmt->close();
    
    return $blocking;
}

// Bulk Delete Products (Admin Only)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_delete']) && isset($_POST['selected_items']) && isset($_POST['delete_products'])) {
    requireLogin();
    if (!isAdmin()) {
        header('Location: ' . BASE_URL . 'dashboard.php?error=permission_denied');
        exit();
    }
    
    $selected_ids = $_POST['selected_items'];
    $deleted_count = 0;
    $errors = [];
    
    if (!empty($selected_ids) && is_array($selected_ids)) {
        foreach ($selected_ids as $product_id) {
            $product_id = intval($product_id);
            if ($product_id <= 0) continue;
            
            // Get product info
            $stmt = $conn->prepare("SELECT name, sku FROM products WHERE id = ?");
            $stmt->bind_param("i", $product_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $product = $result->fetch_assoc();
            $stmt->close();
            
            if (!$product) {
                $errors[] = "Product ID $product_id not found.";
                continue;
            }
            
            // Get product display name
            $name = trim($product['name'] ?? '');
            $sku = trim($product['sku'] ?? '');
            $product_name = (!empty($name) && $name !== '0') ? $name : 
                          ((!empty($sku) && $sku !== '0') ? $sku : "Product ID $product_id");
            
            // Check if product can be deleted
            $blocking = checkProductCanBeDeleted($conn, $product_id);
            
            if (!empty($blocking)) {
                $errors[] = "Product '$product_name' (ID: $product_id) cannot be deleted - it has " . implode(", ", $blocking) . ".";
                continue;
            }
            
            // Delete product (no blocking records)
            if (empty($blocking)) {
                $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
                $stmt->bind_param("i", $product_id);
                
                if ($stmt->execute()) {
                    $deleted_count++;
                } else {
                    $errors[] = "Failed to delete product '$product_name' (ID: $product_id) - " . $stmt->error;
                }
                $stmt->close();
            } else {
                $errors[] = "Product '$product_name' (ID: $product_id) still cannot be deleted after force delete - it has " . implode(", ", $blocking) . ".";
            }
        }
        
        if ($deleted_count > 0) {
            $message = "Successfully deleted $deleted_count product(s).";
            if (!empty($errors)) {
                $error = implode("<br>", $errors);
            }
        } else {
            $error = !empty($errors) ? implode("<br>", $errors) : 'No products were deleted.';
        }
    } else {
        $error = 'Please select at least one product to delete.';
    }
}

// Pagination parameters
$per_page = 20;
$inventory_page = isset($_GET['inv_page']) ? max(1, intval($_GET['inv_page'])) : 1;
$movements_page = isset($_GET['mov_page']) ? max(1, intval($_GET['mov_page'])) : 1;
$products_page = isset($_GET['prod_page']) ? max(1, intval($_GET['prod_page'])) : 1;

// Get filters (sanitize inputs)
$product_filter = isset($_GET['product']) ? intval($_GET['product']) : 0;
$warehouse_filter = isset($_GET['warehouse']) ? intval($_GET['warehouse']) : 0;
$status_filter = $_GET['status'] ?? '';

$where_conditions = [];
if ($product_filter > 0) {
    $where_conditions[] = "p.id = " . intval($product_filter);
}
if ($warehouse_filter > 0) {
    $where_conditions[] = "w.id = " . intval($warehouse_filter);
}
if ($status_filter == 'low') {
    $where_conditions[] = "i.quantity <= p.min_stock_level";
}
$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get total inventory count
$count_result = $conn->query("SELECT COUNT(DISTINCT i.id) as total FROM inventory i JOIN products p ON i.product_id = p.id JOIN warehouses w ON i.warehouse_id = w.id $where_clause");
$inventory_total = $count_result->fetch_assoc()['total'];
$inventory_total_pages = ceil($inventory_total / $per_page);
$inventory_offset = ($inventory_page - 1) * $per_page;

// Get inventory with supplier information (paginated)
$inventory = [];
$result = $conn->query("
    SELECT i.id, i.product_id, i.warehouse_id, i.quantity, i.reserved_quantity, i.last_updated,
           p.name as product_name, 
           p.sku, 
           p.min_stock_level,
           p.unit_price,
           w.name as warehouse_name,
           CASE WHEN i.quantity <= p.min_stock_level THEN 'low' ELSE 'ok' END as stock_status,
           COALESCE(GROUP_CONCAT(DISTINCT CONCAT(s.company_name, IF(sp.is_primary = 1, ' [Primary]', '')) SEPARATOR ', '), '') as suppliers
    FROM inventory i
    JOIN products p ON i.product_id = p.id
    JOIN warehouses w ON i.warehouse_id = w.id
    LEFT JOIN supplier_products sp ON p.id = sp.product_id AND sp.status = 'active'
    LEFT JOIN suppliers s ON sp.supplier_id = s.id AND s.status = 'active'
    $where_clause
    GROUP BY i.id, i.product_id, i.warehouse_id, i.quantity, i.reserved_quantity, i.last_updated,
             p.name, p.sku, p.min_stock_level, p.unit_price, w.name
    ORDER BY p.name, w.name
    LIMIT $per_page OFFSET $inventory_offset
");
while ($row = $result->fetch_assoc()) {
    $inventory[] = $row;
}

// Calculate total inventory value (from all inventory, not just current page)
$total_value_result = $conn->query("
    SELECT SUM(i.quantity * p.unit_price) as total_value
    FROM inventory i
    JOIN products p ON i.product_id = p.id
    JOIN warehouses w ON i.warehouse_id = w.id
    $where_clause
");
$total_value_row = $total_value_result->fetch_assoc();
$total_value = floatval($total_value_row['total_value'] ?? 0);

// Get total low stock count (from all inventory, respecting filters)
$low_stock_where = [];
if ($product_filter > 0) {
    $low_stock_where[] = "p.id = " . intval($product_filter);
}
if ($warehouse_filter > 0) {
    $low_stock_where[] = "w.id = " . intval($warehouse_filter);
}
$low_stock_where_clause = !empty($low_stock_where) ? "AND " . implode(" AND ", $low_stock_where) : "";
$low_stock_count_result = $conn->query("
    SELECT COUNT(DISTINCT i.id) as total
    FROM inventory i
    JOIN products p ON i.product_id = p.id
    JOIN warehouses w ON i.warehouse_id = w.id
    WHERE i.quantity <= p.min_stock_level $low_stock_where_clause
");
$low_stock_count = intval($low_stock_count_result->fetch_assoc()['total'] ?? 0);

// Get products (only those in inventory)
$products = [];
$result = $conn->query("SELECT DISTINCT p.id, p.name, p.sku FROM products p INNER JOIN inventory i ON p.id = i.product_id ORDER BY p.name");
while ($row = $result->fetch_assoc()) {
    $products[] = $row;
}

// Get warehouses
$warehouses = [];
$result = $conn->query("SELECT id, name FROM warehouses WHERE status = 'active' ORDER BY name");
while ($row = $result->fetch_assoc()) {
    $warehouses[] = $row;
}

// Get total movements count
$movements_count_result = $conn->query("SELECT COUNT(*) as total FROM stock_movements");
$movements_total = $movements_count_result->fetch_assoc()['total'];
$movements_total_pages = ceil($movements_total / $per_page);
$movements_offset = ($movements_page - 1) * $per_page;

// Get recent movements (paginated)
$recent_movements = [];
$result = $conn->query("
    SELECT sm.*, 
           p.name as product_name, 
           p.sku,
           w.name as warehouse_name,
           u.full_name as created_by_name
    FROM stock_movements sm
    JOIN products p ON sm.product_id = p.id
    JOIN warehouses w ON sm.warehouse_id = w.id
    JOIN users u ON sm.created_by = u.id
    ORDER BY sm.created_at DESC
    LIMIT $per_page OFFSET $movements_offset
");
while ($row = $result->fetch_assoc()) {
    $recent_movements[] = $row;
}

// Get all products (for Products table - not filtered by inventory, unique by name)
$all_products_count_result = $conn->query("SELECT COUNT(DISTINCT name) as total FROM products");
$all_products_total = $all_products_count_result->fetch_assoc()['total'];
$all_products_total_pages = ceil($all_products_total / $per_page);
$all_products_offset = ($products_page - 1) * $per_page;

$all_products = [];
$result = $conn->query("SELECT MIN(id) as id, name FROM products GROUP BY name ORDER BY name LIMIT $per_page OFFSET $all_products_offset");
while ($row = $result->fetch_assoc()) {
    $all_products[] = $row;
}

closeDBConnection($conn);

$pageTitle = 'Inventory Management';
include '../includes/header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-warehouse"></i> Inventory Management</h2>
    <?php if (canCreate('inventory')): ?>
    <div style="display: flex; gap: 10px;">
        <button class="btn btn-primary" onclick="openModal('updateStockModal')">
            <i class="fas fa-plus"></i> Update Stock
        </button>
        <button class="btn btn-secondary" onclick="openModal('csvImportModal')">
            <i class="fas fa-file-csv"></i> Import CSV
        </button>
    </div>
    <?php endif; ?>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="fas fa-box"></i>
        </div>
        <div class="stat-info">
            <h3><?php echo $inventory_total; ?></h3>
            <p>Total Inventory Items</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="fas fa-dollar-sign"></i>
        </div>
        <div class="stat-info">
            <h3><?php echo formatCurrency($total_value); ?></h3>
            <p>Total Inventory Value</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon orange">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="stat-info">
            <h3><?php echo $low_stock_count; ?></h3>
            <p>Low Stock Items</p>
        </div>
    </div>
</div>

<div class="filters-bar">
    <div class="filter-group">
        <label>Filter by Product</label>
        <select class="form-control" onchange="window.location.href='?product=' + this.value + '&warehouse=<?php echo $warehouse_filter; ?>&status=<?php echo htmlspecialchars($status_filter); ?>&inv_page=1&mov_page=<?php echo $movements_page; ?>&prod_page=<?php echo $products_page; ?>#inventory'">
            <option value="">All Products</option>
            <?php foreach ($products as $product): ?>
                <option value="<?php echo $product['id']; ?>" <?php echo $product_filter == $product['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($product['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <div class="filter-group">
        <label>Filter by Warehouse</label>
        <select class="form-control" onchange="window.location.href='?warehouse=' + this.value + '&product=<?php echo $product_filter; ?>&status=<?php echo htmlspecialchars($status_filter); ?>&inv_page=1&mov_page=<?php echo $movements_page; ?>&prod_page=<?php echo $products_page; ?>#inventory'">
            <option value="">All Warehouses</option>
            <?php foreach ($warehouses as $warehouse): ?>
                <option value="<?php echo $warehouse['id']; ?>" <?php echo $warehouse_filter == $warehouse['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($warehouse['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <div class="filter-group">
        <label>Filter by Status</label>
        <select class="form-control" onchange="window.location.href='?status=' + this.value + '&product=<?php echo $product_filter; ?>&warehouse=<?php echo $warehouse_filter; ?>&inv_page=1&mov_page=<?php echo $movements_page; ?>&prod_page=<?php echo $products_page; ?>#inventory'">
            <option value="">All Statuses</option>
            <option value="low" <?php echo $status_filter == 'low' ? 'selected' : ''; ?>>Low Stock</option>
        </select>
    </div>
</div>

<!-- Tab Navigation -->
<div class="dashboard-card">
    <div class="tabs-container">
        <div class="tabs-nav">
            <button class="tab-btn active" onclick="switchTab('inventory')" id="tab-inventory">
                <i class="fas fa-list"></i> Inventory
            </button>
            <button class="tab-btn" onclick="switchTab('movements')" id="tab-movements">
                <i class="fas fa-history"></i> Recent Stock Movements
            </button>
            <button class="tab-btn" onclick="switchTab('products')" id="tab-products">
                <i class="fas fa-box"></i> Products
            </button>
        </div>
        
        <!-- Inventory Tab -->
        <div class="tab-content active" id="content-inventory">
            <div class="tab-header">
                <h3><i class="fas fa-list"></i> Inventory</h3>
                <?php if (isAdmin()): ?>
                <div id="bulkDeleteHeaderActions" style="display: none;">
                    <button type="button" class="btn btn-danger btn-sm" onclick="if(confirmBulkDelete()) document.getElementById('inventoryForm').submit();">
                        <i class="fas fa-trash"></i> Delete Selected (<span id="headerSelectedCount">0</span>)
                    </button>
                </div>
                <?php endif; ?>
            </div>
            <div class="tab-body">
            <?php if (empty($inventory)): ?>
                <p class="text-muted">No inventory found.</p>
            <?php else: ?>
                <form method="POST" action="" id="inventoryForm">
                    <input type="hidden" name="bulk_delete" value="1">
                    <div style="overflow-x: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <?php if (isAdmin()): ?>
                                    <th style="width: 40px;">
                                        <input type="checkbox" id="selectAll" title="Select All">
                                    </th>
                                    <?php endif; ?>
                                    <th>Product</th>
                                    <th>SKU</th>
                                    <th>Warehouse</th>
                                    <th>Quantity</th>
                                    <th>Min Level</th>
                                    <th>Suppliers</th>
                                    <?php if (canViewFinancialData()): ?>
                                    <th>Unit Price</th>
                                    <th>Value</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($inventory as $item): ?>
                                    <tr>
                                        <?php if (isAdmin()): ?>
                                        <td>
                                            <input type="checkbox" name="selected_items[]" value="<?php echo $item['id']; ?>" class="inventory-checkbox">
                                        </td>
                                        <?php endif; ?>
                                        <td>
                                            <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                                            <?php if ($item['stock_status'] == 'low'): ?>
                                                <i class="fas fa-exclamation-triangle" style="color: #dc3545; margin-left: 8px;" title="Low Stock: <?php echo $item['quantity']; ?> units (Min: <?php echo $item['min_stock_level']; ?>)"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($item['sku']); ?></td>
                                        <td><?php echo htmlspecialchars($item['warehouse_name']); ?></td>
                                        <td><strong><?php echo $item['quantity']; ?></strong></td>
                                        <td><?php echo $item['min_stock_level']; ?></td>
                                        <td>
                                            <?php if (!empty($item['suppliers'])): ?>
                                                <span class="text-muted" title="<?php echo htmlspecialchars($item['suppliers']); ?>">
                                                    <?php echo htmlspecialchars(strlen($item['suppliers']) > 50 ? substr($item['suppliers'], 0, 50) . '...' : $item['suppliers']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">No supplier linked</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php if (canViewFinancialData()): ?>
                                        <td><?php echo formatCurrency($item['unit_price']); ?></td>
                                        <td><?php echo formatCurrency($item['quantity'] * $item['unit_price']); ?></td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (isAdmin()): ?>
                    <div style="margin-top: 15px; display: none;" id="bulkDeleteActions">
                        <button type="submit" class="btn btn-danger" onclick="return confirmBulkDelete();">
                            <i class="fas fa-trash"></i> Delete Selected (<span id="selectedCount">0</span>)
                        </button>
                    </div>
                    <?php endif; ?>
                    <?php if ($inventory_total_pages > 1): ?>
                    <div style="margin-top: 15px; display: flex; justify-content: center; align-items: center; gap: 10px;">
                        <?php if ($inventory_page > 1): ?>
                            <a href="?inv_page=<?php echo $inventory_page - 1; ?>&mov_page=<?php echo $movements_page; ?>&prod_page=<?php echo $products_page; ?>&product=<?php echo $product_filter; ?>&warehouse=<?php echo $warehouse_filter; ?>&status=<?php echo htmlspecialchars($status_filter); ?>#inventory" class="btn btn-sm btn-secondary">Previous</a>
                        <?php endif; ?>
                        <span>Page <?php echo $inventory_page; ?> of <?php echo $inventory_total_pages; ?></span>
                        <?php if ($inventory_page < $inventory_total_pages): ?>
                            <a href="?inv_page=<?php echo $inventory_page + 1; ?>&mov_page=<?php echo $movements_page; ?>&prod_page=<?php echo $products_page; ?>&product=<?php echo $product_filter; ?>&warehouse=<?php echo $warehouse_filter; ?>&status=<?php echo htmlspecialchars($status_filter); ?>#inventory" class="btn btn-sm btn-secondary">Next</a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
            </div>
        </div>
        
        <!-- Movements Tab -->
        <div class="tab-content" id="content-movements">
            <div class="tab-header">
                <h3><i class="fas fa-history"></i> Recent Stock Movements</h3>
            </div>
            <div class="tab-body">
            <?php if (empty($recent_movements)): ?>
                <p class="text-muted">No stock movements found.</p>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Warehouse</th>
                                <th>Type</th>
                                <th>Quantity</th>
                                <th>Date</th>
                                <th>By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_movements as $movement): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($movement['product_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($movement['warehouse_name']); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $movement['movement_type'] == 'in' ? 'success' : ($movement['movement_type'] == 'out' ? 'danger' : 'secondary'); ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $movement['movement_type'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $movement['quantity']; ?></td>
                                    <td><?php echo formatDateTime($movement['created_at']); ?></td>
                                    <td><?php echo htmlspecialchars($movement['created_by_name']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($movements_total_pages > 1): ?>
                <div style="margin-top: 15px; display: flex; justify-content: center; align-items: center; gap: 10px;">
                    <?php if ($movements_page > 1): ?>
                        <a href="?mov_page=<?php echo $movements_page - 1; ?>&inv_page=<?php echo $inventory_page; ?>&prod_page=<?php echo $products_page; ?>&product=<?php echo $product_filter; ?>&warehouse=<?php echo $warehouse_filter; ?>&status=<?php echo htmlspecialchars($status_filter); ?>#movements" class="btn btn-sm btn-secondary">Previous</a>
                    <?php endif; ?>
                    <span>Page <?php echo $movements_page; ?> of <?php echo $movements_total_pages; ?></span>
                    <?php if ($movements_page < $movements_total_pages): ?>
                        <a href="?mov_page=<?php echo $movements_page + 1; ?>&inv_page=<?php echo $inventory_page; ?>&prod_page=<?php echo $products_page; ?>&product=<?php echo $product_filter; ?>&warehouse=<?php echo $warehouse_filter; ?>&status=<?php echo htmlspecialchars($status_filter); ?>#movements" class="btn btn-sm btn-secondary">Next</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
            </div>
        </div>
        
        <!-- Products Tab -->
        <div class="tab-content" id="content-products">
            <div class="tab-header">
                <h3><i class="fas fa-box"></i> Products</h3>
                <?php if (isAdmin()): ?>
                <div id="bulkDeleteProductsHeaderActions" style="display: none;">
                    <button type="button" class="btn btn-danger btn-sm" onclick="if(confirmBulkDeleteProducts()) document.getElementById('productsForm').submit();">
                        <i class="fas fa-trash"></i> Delete Selected (<span id="headerProductsSelectedCount">0</span>)
                    </button>
                </div>
                <?php endif; ?>
            </div>
            <div class="tab-body">
            <?php if (empty($all_products)): ?>
                <p class="text-muted">No products found.</p>
            <?php else: ?>
                <form method="POST" action="" id="productsForm">
                    <input type="hidden" name="bulk_delete" value="1">
                    <input type="hidden" name="delete_products" value="1">
                    <div style="overflow-x: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <?php if (isAdmin()): ?>
                                    <th style="width: 40px;">
                                        <input type="checkbox" id="selectAllProducts" title="Select All">
                                    </th>
                                    <?php endif; ?>
                                    <th>Product Name</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_products as $product): ?>
                                    <tr>
                                        <?php if (isAdmin()): ?>
                                        <td>
                                            <input type="checkbox" name="selected_items[]" value="<?php echo $product['id']; ?>" class="product-checkbox">
                                        </td>
                                        <?php endif; ?>
                                        <td><strong><?php echo htmlspecialchars($product['name']); ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (isAdmin()): ?>
                    <div style="margin-top: 15px; display: none;" id="bulkDeleteProductsActions">
                        <button type="submit" class="btn btn-danger" onclick="return confirmBulkDeleteProducts();">
                            <i class="fas fa-trash"></i> Delete Selected (<span id="productsSelectedCount">0</span>)
                        </button>
                    </div>
                    <?php endif; ?>
                    <?php if ($all_products_total_pages > 1): ?>
                    <div style="margin-top: 15px; display: flex; justify-content: center; align-items: center; gap: 10px;">
                        <?php if ($products_page > 1): ?>
                            <a href="?prod_page=<?php echo $products_page - 1; ?>&inv_page=<?php echo $inventory_page; ?>&mov_page=<?php echo $movements_page; ?>&product=<?php echo $product_filter; ?>&warehouse=<?php echo $warehouse_filter; ?>&status=<?php echo htmlspecialchars($status_filter); ?>#products" class="btn btn-sm btn-secondary">Previous</a>
                        <?php endif; ?>
                        <span>Page <?php echo $products_page; ?> of <?php echo $all_products_total_pages; ?></span>
                        <?php if ($products_page < $all_products_total_pages): ?>
                            <a href="?prod_page=<?php echo $products_page + 1; ?>&inv_page=<?php echo $inventory_page; ?>&mov_page=<?php echo $movements_page; ?>&product=<?php echo $product_filter; ?>&warehouse=<?php echo $warehouse_filter; ?>&status=<?php echo htmlspecialchars($status_filter); ?>#products" class="btn btn-sm btn-secondary">Next</a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Update Stock Modal -->
<?php if (canCreate('inventory')): ?>
<div id="updateStockModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Update Stock</h3>
            <button class="modal-close" onclick="closeModal('updateStockModal')">&times;</button>
        </div>
        <form method="POST" action="">
            <div class="form-group">
                <label for="product_id">Product *</label>
                <select name="product_id" id="product_id" class="form-control" required>
                    <option value="">Select Product</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?php echo $product['id']; ?>">
                            <?php echo htmlspecialchars($product['name'] . ' (' . $product['sku'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="warehouse_id">Warehouse *</label>
                <select name="warehouse_id" id="warehouse_id" class="form-control" required>
                    <option value="">Select Warehouse</option>
                    <?php foreach ($warehouses as $warehouse): ?>
                        <option value="<?php echo $warehouse['id']; ?>">
                            <?php echo htmlspecialchars($warehouse['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="movement_type">Movement Type *</label>
                <select name="movement_type" id="movement_type" class="form-control" required>
                    <option value="in">Stock In</option>
                    <option value="out">Stock Out</option>
                    <option value="adjustment">Adjustment</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="quantity">Quantity *</label>
                <input type="number" name="quantity" id="quantity" class="form-control" min="1" required>
            </div>
            
            <div class="form-group">
                <label for="notes">Notes</label>
                <textarea name="notes" id="notes" class="form-control" rows="3"></textarea>
            </div>
            
            <div class="form-group">
                <button type="submit" name="update_stock" class="btn btn-primary btn-block">
                    <i class="fas fa-save"></i> Update Stock
                </button>
            </div>
        </form>
    </div>
</div>

<!-- CSV Import Modal -->
<div id="csvImportModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-file-csv"></i> Bulk Import from CSV</h3>
            <button class="modal-close" onclick="closeModal('csvImportModal')">&times;</button>
        </div>
        <form method="POST" action="" enctype="multipart/form-data">
            <div class="form-group">
                <label for="csv_file">CSV File *</label>
                <input type="file" name="csv_file" id="csv_file" class="form-control" accept=".csv" required>
                <small class="text-muted">Upload a CSV file with columns: Product, [SKU], Warehouse, Quantity, Min Level, Supplier, Unit Price (SKU is optional)</small>
            </div>
            
            <div class="form-group">
                <div class="alert alert-info" style="flex-direction: column; align-items: flex-start;">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                        <i class="fas fa-info-circle"></i>
                        <strong>CSV Template Format</strong>
                    </div>
                    <div style="width: 100%; margin-bottom: 12px;">
                        <strong style="display: block; margin-bottom: 6px;">Format 1 (with SKU):</strong>
                        <code style="display: block; padding: 8px 12px; background: rgba(255,255,255,0.7); border-radius: 4px; font-family: 'Courier New', monospace; font-size: 13px; margin-top: 4px;">Product, SKU, Warehouse, Quantity, Min Level, Supplier, Unit Price</code>
                    </div>
                    <div style="width: 100%; margin-bottom: 12px;">
                        <strong style="display: block; margin-bottom: 6px;">Format 2 (without SKU - recommended):</strong>
                        <code style="display: block; padding: 8px 12px; background: rgba(255,255,255,0.7); border-radius: 4px; font-family: 'Courier New', monospace; font-size: 13px; margin-top: 4px;">Product, Warehouse, Quantity, Min Level, Supplier, Unit Price</code>
                    </div>
                    <div style="width: 100%; margin-bottom: 12px;">
                        <strong style="display: block; margin-bottom: 6px;">Example (Format 2):</strong>
                        <code style="display: block; padding: 8px 12px; background: rgba(255,255,255,0.7); border-radius: 4px; font-family: 'Courier New', monospace; font-size: 13px;">Buldak Quattro Cheese, Main Warehouse, 100, 30, Incheon Food Export, 683.76</code>
                    </div>
                    <div style="width: 100%;">
                        <strong style="display: block; margin-bottom: 6px;">Notes:</strong>
                        <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
                            <li>SKU column is optional - will be auto-generated from supplier initials if omitted</li>
                            <li>Warehouse must exist in the system (use exact warehouse name as shown in the system)</li>
                            <li>Supplier will be created if it doesn't exist</li>
                            <li>Value is automatically calculated (Unit Price × Quantity)</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <button type="submit" name="import_csv" class="btn btn-primary btn-block">
                    <i class="fas fa-upload"></i> Import CSV
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
// Master checkbox functionality for bulk delete
document.addEventListener('DOMContentLoaded', function() {
    const selectAllCheckbox = document.getElementById('selectAll');
    const inventoryCheckboxes = document.querySelectorAll('.inventory-checkbox');
    const bulkDeleteActions = document.getElementById('bulkDeleteActions');
    const bulkDeleteHeaderActions = document.getElementById('bulkDeleteHeaderActions');
    const selectedCountSpan = document.getElementById('selectedCount');
    const headerSelectedCountSpan = document.getElementById('headerSelectedCount');
    
    if (selectAllCheckbox) {
        // Master checkbox - select/deselect all
        selectAllCheckbox.addEventListener('change', function() {
            inventoryCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateBulkDeleteUI();
        });
        
        // Individual checkboxes - update master checkbox state
        inventoryCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                updateBulkDeleteUI();
                // Update master checkbox state
                const allChecked = Array.from(inventoryCheckboxes).every(cb => cb.checked);
                const someChecked = Array.from(inventoryCheckboxes).some(cb => cb.checked);
                selectAllCheckbox.checked = allChecked;
                selectAllCheckbox.indeterminate = someChecked && !allChecked;
            });
        });
        
        // Function to update bulk delete UI
        function updateBulkDeleteUI() {
            const selectedCount = Array.from(inventoryCheckboxes).filter(cb => cb.checked).length;
            if (selectedCount > 0) {
                if (bulkDeleteActions) bulkDeleteActions.style.display = 'block';
                if (bulkDeleteHeaderActions) bulkDeleteHeaderActions.style.display = 'block';
                if (selectedCountSpan) selectedCountSpan.textContent = selectedCount;
                if (headerSelectedCountSpan) headerSelectedCountSpan.textContent = selectedCount;
            } else {
                if (bulkDeleteActions) bulkDeleteActions.style.display = 'none';
                if (bulkDeleteHeaderActions) bulkDeleteHeaderActions.style.display = 'none';
            }
        }
    }
});

// Confirm bulk delete
function confirmBulkDelete() {
    const selectedCount = Array.from(document.querySelectorAll('.inventory-checkbox:checked')).length;
    if (selectedCount === 0) {
        alert('Please select at least one inventory record to delete.');
        return false;
    }
    return confirm(`Are you sure you want to delete ${selectedCount} inventory record(s)? This action cannot be undone.`);
}

// Products bulk delete functionality
document.addEventListener('DOMContentLoaded', function() {
    const selectAllProductsCheckbox = document.getElementById('selectAllProducts');
    const productCheckboxes = document.querySelectorAll('.product-checkbox');
    const bulkDeleteProductsActions = document.getElementById('bulkDeleteProductsActions');
    const bulkDeleteProductsHeaderActions = document.getElementById('bulkDeleteProductsHeaderActions');
    const productsSelectedCountSpan = document.getElementById('productsSelectedCount');
    const headerProductsSelectedCountSpan = document.getElementById('headerProductsSelectedCount');
    
    if (selectAllProductsCheckbox) {
        selectAllProductsCheckbox.addEventListener('change', function() {
            productCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateProductsBulkDeleteUI();
        });
        
        productCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                updateProductsBulkDeleteUI();
                const allChecked = Array.from(productCheckboxes).every(cb => cb.checked);
                const someChecked = Array.from(productCheckboxes).some(cb => cb.checked);
                selectAllProductsCheckbox.checked = allChecked;
                selectAllProductsCheckbox.indeterminate = someChecked && !allChecked;
            });
        });
        
        function updateProductsBulkDeleteUI() {
            const selectedCount = Array.from(productCheckboxes).filter(cb => cb.checked).length;
            if (selectedCount > 0) {
                if (bulkDeleteProductsActions) bulkDeleteProductsActions.style.display = 'block';
                if (bulkDeleteProductsHeaderActions) bulkDeleteProductsHeaderActions.style.display = 'block';
                if (productsSelectedCountSpan) productsSelectedCountSpan.textContent = selectedCount;
                if (headerProductsSelectedCountSpan) headerProductsSelectedCountSpan.textContent = selectedCount;
            } else {
                if (bulkDeleteProductsActions) bulkDeleteProductsActions.style.display = 'none';
                if (bulkDeleteProductsHeaderActions) bulkDeleteProductsHeaderActions.style.display = 'none';
            }
        }
    }
});

// Confirm bulk delete products
function confirmBulkDeleteProducts() {
    const selectedCount = Array.from(document.querySelectorAll('.product-checkbox:checked')).length;
    if (selectedCount === 0) {
        alert('Please select at least one product to delete.');
        return false;
    }
    return confirm(`Are you sure you want to delete ${selectedCount} product(s)? This action cannot be undone.\n\nNote: Products with inventory records or purchase order items cannot be deleted.`);
}

// Tab switching functionality
function switchTab(tabName) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
    });
    
    // Remove active class from all tab buttons
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Show selected tab content
    const selectedContent = document.getElementById('content-' + tabName);
    if (selectedContent) {
        selectedContent.classList.add('active');
    }
    
    // Add active class to selected tab button
    const selectedBtn = document.getElementById('tab-' + tabName);
    if (selectedBtn) {
        selectedBtn.classList.add('active');
    }
    
    // Update URL hash without scrolling
    if (history.pushState) {
        history.pushState(null, null, '#' + tabName);
    } else {
        window.location.hash = tabName;
    }
}

// Scroll to section on page load if anchor is present
document.addEventListener('DOMContentLoaded', function() {
    // Check for hash in URL and switch to appropriate tab
    if (window.location.hash) {
        const hash = window.location.hash.substring(1);
        if (hash === 'inventory' || hash === 'movements' || hash === 'products') {
            switchTab(hash);
        } else {
            // Legacy support for old anchor links
            const targetSection = document.getElementById(hash);
            if (targetSection) {
                // Determine which tab based on section ID
                if (hash === 'inventorySection') {
                    switchTab('inventory');
                } else if (hash === 'movementsSection') {
                    switchTab('movements');
                } else if (hash === 'productsSection') {
                    switchTab('products');
                }
            }
        }
    }
});
</script>

<style>
/* Tab Styles */
.tabs-container {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    overflow: hidden;
}

.tabs-nav {
    display: flex;
    background: #f8f9fa;
    border-bottom: 2px solid #e0e0e0;
    padding: 0;
    margin: 0;
}

.tab-btn {
    flex: 1;
    padding: 15px 20px;
    background: transparent;
    border: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    color: #666;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.tab-btn:hover {
    background: #f0f0f0;
    color: #333;
}

.tab-btn.active {
    color: #007bff;
    border-bottom-color: #007bff;
    background: #fff;
    font-weight: 600;
}

.tab-content {
    display: none;
    padding: 0;
}

.tab-content.active {
    display: block;
}

.tab-header {
    padding: 20px;
    border-bottom: 1px solid #e0e0e0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #fafafa;
}

.tab-header h3 {
    margin: 0;
    font-size: 18px;
    color: #333;
}

.tab-body {
    padding: 20px;
}

@media (max-width: 768px) {
    .tabs-nav {
        flex-direction: column;
    }
    
    .tab-btn {
        border-bottom: 1px solid #e0e0e0;
        border-right: none;
    }
    
    .tab-btn.active {
        border-bottom-color: #e0e0e0;
        border-left: 3px solid #007bff;
    }
}
</style>

<?php include '../includes/footer.php'; ?>

