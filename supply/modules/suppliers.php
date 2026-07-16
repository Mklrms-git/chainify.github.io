<?php
require_once '../config/config.php';
requireModuleAccess('suppliers');

$conn = getDBConnection();
$message = '';
$error = '';

// Check if viewing supplier history
$view_history = isset($_GET['view']) && $_GET['view'] == 'history';
$supplier_id = $_GET['id'] ?? 0;

if ($view_history && $supplier_id) {
    // Get supplier info
    $stmt = $conn->prepare("SELECT * FROM suppliers WHERE id = ?");
    $stmt->bind_param("i", $supplier_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $supplier = $result->fetch_assoc();
    $stmt->close();

    if (!$supplier) {
        header('Location: suppliers.php');
        exit();
    }

    // Get purchase orders
    $purchase_orders = [];
    $result = $conn->query("
        SELECT po.*, u.full_name as created_by_name
        FROM purchase_orders po
        JOIN users u ON po.created_by = u.id
        WHERE po.supplier_id = $supplier_id
        ORDER BY po.order_date DESC
    ");
    while ($row = $result->fetch_assoc()) {
        $purchase_orders[] = $row;
    }

    // Get order statistics
    $stats = [];
    $result = $conn->query("
        SELECT 
            COUNT(*) as total_orders,
            COUNT(CASE WHEN status = 'received' THEN 1 END) as completed_orders,
            COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled_orders,
            SUM(CASE WHEN status = 'received' THEN total_amount ELSE 0 END) as total_value,
            AVG(CASE WHEN status = 'received' THEN DATEDIFF(delivery_date, order_date) ELSE NULL END) as avg_delivery_days
        FROM purchase_orders
        WHERE supplier_id = $supplier_id
    ");
    $stats = $result->fetch_assoc();

    closeDBConnection($conn);

    $pageTitle = 'Supplier History - ' . htmlspecialchars($supplier['company_name']);
    include '../includes/header.php';
    ?>
    
    <div class="page-header">
        <h2><i class="fas fa-history"></i> Supplier History: <?php echo htmlspecialchars($supplier['company_name']); ?></h2>
        <a href="suppliers.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Suppliers
        </a>
    </div>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon blue">
                <i class="fas fa-clipboard-list"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo $stats['total_orders']; ?></h3>
                <p>Total Orders</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon green">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo $stats['completed_orders']; ?></h3>
                <p>Completed Orders</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon orange">
                <i class="fas fa-dollar-sign"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo formatCurrency($stats['total_value'] ?? 0); ?></h3>
                <p>Total Value</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon purple">
                <i class="fas fa-calendar"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo $stats['avg_delivery_days'] ? round($stats['avg_delivery_days']) : 'N/A'; ?></h3>
                <p>Avg Delivery Days</p>
            </div>
        </div>
    </div>
    
    <div class="dashboard-card">
        <div class="card-header">
            <h3><i class="fas fa-list"></i> Purchase Orders</h3>
        </div>
        <div class="card-body">
            <?php if (empty($purchase_orders)): ?>
                <p class="text-muted">No purchase orders found for this supplier.</p>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>PO Number</th>
                            <th>Order Date</th>
                            <th>Delivery Date</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Created By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($purchase_orders as $po): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($po['po_number']); ?></strong></td>
                                <td><?php echo formatDate($po['order_date']); ?></td>
                                <td><?php echo $po['delivery_date'] ? formatDate($po['delivery_date']) : 'N/A'; ?></td>
                                <td><?php echo formatCurrency($po['total_amount']); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $po['status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $po['status'])); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($po['created_by_name']); ?></td>
                                <td>
                                    <a href="../modules/procurement.php" class="btn btn-sm btn-secondary">
                                        <i class="fas fa-eye"></i> View Details
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <?php include '../includes/footer.php'; ?>
    <?php
    exit();
}

// Add/Update Supplier
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    requireCreatePermission('suppliers');
    $supplier_id = $_POST['supplier_id'] ?? 0;
    $company_name = $_POST['company_name'] ?? '';
    $contact_person = $_POST['contact_person'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = normalizePhoneNumber($_POST['phone'] ?? '');
    $address = $_POST['address'] ?? '';
    $products_supplied = $_POST['products_supplied'] ?? '';
    $performance_rating = $_POST['performance_rating'] ?? 0;
    
    if ($company_name) {
        if ($supplier_id > 0) {
            // Update
            $stmt = $conn->prepare("UPDATE suppliers SET company_name=?, contact_person=?, email=?, phone=?, address=?, products_supplied=?, performance_rating=? WHERE id=?");
            $stmt->bind_param("ssssssdi", $company_name, $contact_person, $email, $phone, $address, $products_supplied, $performance_rating, $supplier_id);
        } else {
            // Insert
            $stmt = $conn->prepare("INSERT INTO suppliers (company_name, contact_person, email, phone, address, products_supplied, performance_rating) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssssd", $company_name, $contact_person, $email, $phone, $address, $products_supplied, $performance_rating);
        }
        
        if ($stmt->execute()) {
            $message = $supplier_id > 0 ? 'Supplier updated successfully!' : 'Supplier added successfully!';
        } else {
            $error = 'Failed to save supplier.';
        }
        $stmt->close();
    } else {
        $error = 'Company name is required.';
    }
}

// Delete Single Supplier (Admin Only)
if (isset($_GET['delete']) && !isset($_POST['bulk_delete'])) {
    requireLogin();
    if (!isAdmin()) {
        header('Location: ' . BASE_URL . 'dashboard.php?error=permission_denied');
        exit();
    }
    
    $supplier_id = intval($_GET['delete']);
    
    // Get supplier name for error messages
    $stmt = $conn->prepare("SELECT company_name FROM suppliers WHERE id = ?");
    $stmt->bind_param("i", $supplier_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $supplier = $result->fetch_assoc();
    $stmt->close();
    
    if (!$supplier) {
        $error = 'Supplier not found.';
    } else {
        // Check if supplier has ANY purchase orders (foreign key constraint prevents deletion)
        $stmt = $conn->prepare("SELECT COUNT(*) as po_count FROM purchase_orders WHERE supplier_id = ?");
        $stmt->bind_param("i", $supplier_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $po_check = $result->fetch_assoc();
        $stmt->close();
        
        // Check if supplier has ANY shipments
        $stmt = $conn->prepare("SELECT COUNT(*) as ship_count FROM shipments WHERE supplier_id = ?");
        $stmt->bind_param("i", $supplier_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $ship_check = $result->fetch_assoc();
        $stmt->close();
        
        if ($po_check['po_count'] > 0 || $ship_check['ship_count'] > 0) {
            $error = "Cannot delete supplier '{$supplier['company_name']}' because they have related records in the system:\n";
            if ($po_check['po_count'] > 0) {
                $error .= "- {$po_check['po_count']} purchase order(s)\n";
            }
            if ($ship_check['ship_count'] > 0) {
                $error .= "- {$ship_check['ship_count']} shipment(s)\n";
            }
            $error .= "\nTo preserve historical data, the supplier has been marked as 'inactive' instead. You can reactivate them later if needed.";
            
            // Soft delete: Mark as inactive instead of hard delete
            $stmt = $conn->prepare("UPDATE suppliers SET status = 'inactive' WHERE id = ?");
            $stmt->bind_param("i", $supplier_id);
            if ($stmt->execute()) {
                $message = "Supplier '{$supplier['company_name']}' has been deactivated (marked as inactive) due to existing related records.";
            }
            $stmt->close();
        } else {
            // No related records, safe to delete
            // Delete related records first
            $stmt = $conn->prepare("DELETE FROM supplier_products WHERE supplier_id = ?");
            $stmt->bind_param("i", $supplier_id);
            $stmt->execute();
            $stmt->close();
            
            // Delete supplier
            $stmt = $conn->prepare("DELETE FROM suppliers WHERE id = ?");
            $stmt->bind_param("i", $supplier_id);
            
            if ($stmt->execute()) {
                $message = 'Supplier deleted successfully!';
            } else {
                $error = 'Failed to delete supplier: ' . $stmt->error;
            }
            $stmt->close();
        }
    }
}

// Bulk Delete Suppliers (Admin Only)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_delete']) && isset($_POST['selected_items'])) {
    requireLogin();
    if (!isAdmin()) {
        header('Location: ' . BASE_URL . 'dashboard.php?error=permission_denied');
        exit();
    }
    
    $selected_ids = $_POST['selected_items'];
    $deleted_count = 0;
    $deactivated_count = 0;
    $errors = [];
    $deactivated_details = [];
    
    if (!empty($selected_ids) && is_array($selected_ids)) {
        foreach ($selected_ids as $supplier_id) {
            $supplier_id = intval($supplier_id);
            if ($supplier_id > 0) {
                // Get supplier name for error messages
                $stmt = $conn->prepare("SELECT company_name FROM suppliers WHERE id = ?");
                $stmt->bind_param("i", $supplier_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $supplier = $result->fetch_assoc();
                $stmt->close();
                
                if (!$supplier) {
                    $errors[] = "Supplier ID $supplier_id not found";
                    continue;
                }
                
                // Check for ANY purchase orders (foreign key constraint prevents deletion)
                $stmt = $conn->prepare("SELECT COUNT(*) as po_count FROM purchase_orders WHERE supplier_id = ?");
                $stmt->bind_param("i", $supplier_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $po_check = $result->fetch_assoc();
                $stmt->close();
                
                // Check for ANY shipments
                $stmt = $conn->prepare("SELECT COUNT(*) as ship_count FROM shipments WHERE supplier_id = ?");
                $stmt->bind_param("i", $supplier_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $ship_check = $result->fetch_assoc();
                $stmt->close();
                
                if ($po_check['po_count'] > 0 || $ship_check['ship_count'] > 0) {
                    // Soft delete: Mark as inactive instead of hard delete
                    $stmt = $conn->prepare("UPDATE suppliers SET status = 'inactive' WHERE id = ?");
                    $stmt->bind_param("i", $supplier_id);
                    if ($stmt->execute()) {
                        $deactivated_count++;
                        $deactivated_details[] = "'{$supplier['company_name']}' (has {$po_check['po_count']} PO(s), {$ship_check['ship_count']} shipment(s))";
                    } else {
                        $errors[] = "Failed to deactivate '{$supplier['company_name']}'";
                    }
                    $stmt->close();
                    continue;
                }
                
                // No related records, safe to delete
                // Delete supplier_products
                $stmt = $conn->prepare("DELETE FROM supplier_products WHERE supplier_id = ?");
                $stmt->bind_param("i", $supplier_id);
                $stmt->execute();
                $stmt->close();
                
                // Delete supplier
                $stmt = $conn->prepare("DELETE FROM suppliers WHERE id = ?");
                $stmt->bind_param("i", $supplier_id);
                
                if ($stmt->execute()) {
                    $deleted_count++;
                } else {
                    $errors[] = "Failed to delete supplier ID: $supplier_id - " . $stmt->error;
                }
                $stmt->close();
            }
        }
        
        // Build success message
        $message_parts = [];
        if ($deleted_count > 0) {
            $message_parts[] = "Deleted $deleted_count supplier(s)";
        }
        if ($deactivated_count > 0) {
            $message_parts[] = "Deactivated $deactivated_count supplier(s) (have related records)";
        }
        
        if (!empty($message_parts)) {
            $message = implode(". ", $message_parts) . ".";
            if (!empty($deactivated_details)) {
                $error = "Deactivated suppliers:<br>" . implode("<br>", array_slice($deactivated_details, 0, 10));
                if (count($deactivated_details) > 10) {
                    $error .= "<br>... and " . (count($deactivated_details) - 10) . " more";
                }
            }
        } else {
            $error = !empty($errors) ? implode("<br>", $errors) : 'No suppliers were processed.';
        }
    } else {
        $error = 'Please select at least one supplier to delete.';
    }
}

// Get supplier for editing
$edit_supplier = null;
if (isset($_GET['edit'])) {
    $supplier_id = $_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM suppliers WHERE id=?");
    $stmt->bind_param("i", $supplier_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $edit_supplier = $result->fetch_assoc();
    $stmt->close();
}

// Add/Remove Product Link
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['link_product'])) {
    requireCreatePermission('suppliers');
    $supplier_id = $_POST['supplier_id'] ?? 0;
    $product_id = $_POST['product_id'] ?? 0;
    $supplier_sku = $_POST['supplier_sku'] ?? '';
    $supplier_price = $_POST['supplier_price'] ?? null;
    $lead_time_days = $_POST['lead_time_days'] ?? 7;
    $is_primary = isset($_POST['is_primary']) ? 1 : 0;
    
    if ($supplier_id && $product_id) {
        // If setting as primary, unset other primary suppliers for this product
        if ($is_primary) {
            $stmt = $conn->prepare("UPDATE supplier_products SET is_primary = 0 WHERE product_id = ?");
            $stmt->bind_param("i", $product_id);
            $stmt->execute();
            $stmt->close();
        }
        
        // Check if link already exists
        $check = $conn->prepare("SELECT id FROM supplier_products WHERE supplier_id = ? AND product_id = ?");
        $check->bind_param("ii", $supplier_id, $product_id);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        $check->close();
        
        if ($exists) {
            // Update existing link
            $stmt = $conn->prepare("UPDATE supplier_products SET supplier_sku = ?, supplier_price = ?, lead_time_days = ?, is_primary = ?, status = 'active' WHERE id = ?");
            $stmt->bind_param("siiii", $supplier_sku, $supplier_price, $lead_time_days, $is_primary, $exists['id']);
        } else {
            // Insert new link
            $stmt = $conn->prepare("INSERT INTO supplier_products (supplier_id, product_id, supplier_sku, supplier_price, lead_time_days, is_primary) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iisiii", $supplier_id, $product_id, $supplier_sku, $supplier_price, $lead_time_days, $is_primary);
        }
        
        if ($stmt->execute()) {
            $message = 'Product linked to supplier successfully!';
        } else {
            $error = 'Failed to link product.';
        }
        $stmt->close();
    }
}

// Remove Product Link
if (isset($_GET['unlink_product'])) {
    requireDeletePermission('suppliers');
    $link_id = $_GET['unlink_product'];
    $stmt = $conn->prepare("UPDATE supplier_products SET status = 'inactive' WHERE id = ?");
    $stmt->bind_param("i", $link_id);
    
    if ($stmt->execute()) {
        $message = 'Product unlinked from supplier successfully!';
    }
    $stmt->close();
}

// Get suppliers
$suppliers = [];
$result = $conn->query("
    SELECT s.*, 
           COUNT(DISTINCT po.id) as total_orders,
           COUNT(DISTINCT CASE WHEN po.status = 'received' THEN po.id END) as completed_orders
    FROM suppliers s
    LEFT JOIN purchase_orders po ON s.id = po.supplier_id
    GROUP BY s.id
    ORDER BY s.company_name
");
while ($row = $result->fetch_assoc()) {
    $suppliers[] = $row;
}

// Get products for linking (only those in inventory)
$products = [];
$result = $conn->query("SELECT DISTINCT p.id, p.name, p.sku, p.unit_price FROM products p INNER JOIN inventory i ON p.id = i.product_id ORDER BY p.name");
while ($row = $result->fetch_assoc()) {
    $products[] = $row;
}

closeDBConnection($conn);

$pageTitle = 'Suppliers Management';
include '../includes/header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-truck"></i> Suppliers Management</h2>
    <?php if (canCreate('suppliers')): ?>
    <button class="btn btn-primary" onclick="openModal('supplierModal')">
        <i class="fas fa-plus"></i> Add Supplier
    </button>
    <?php endif; ?>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="dashboard-card">
    <div class="card-header">
        <h3><i class="fas fa-list"></i> Supplier List</h3>
        <?php if (isAdmin()): ?>
        <div id="bulkDeleteHeaderActions" style="display: none;">
            <button type="button" class="btn btn-danger btn-sm" onclick="if(confirmBulkDelete()) document.getElementById('suppliersForm').submit();">
                <i class="fas fa-trash"></i> Delete Selected (<span id="headerSelectedCount">0</span>)
            </button>
        </div>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (empty($suppliers)): ?>
            <p class="text-muted">No suppliers found. Add a new supplier to get started.</p>
        <?php else: ?>
            <form method="POST" action="" id="suppliersForm">
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
                                <th>Company Name</th>
                                <th>Contact Person</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Products Supplied</th>
                                <th>Performance Rating</th>
                                <th>Total Orders</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($suppliers as $supplier): ?>
                                <tr>
                                    <?php if (isAdmin()): ?>
                                    <td>
                                        <input type="checkbox" name="selected_items[]" value="<?php echo $supplier['id']; ?>" class="supplier-checkbox">
                                    </td>
                                    <?php endif; ?>
                                    <td><strong><?php echo htmlspecialchars($supplier['company_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($supplier['contact_person'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($supplier['email'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars(formatPhoneNumber($supplier['phone'] ?? '')); ?></td>
                                    <td>
                                        <?php 
                                        $products_supplied = $supplier['products_supplied'] ?? 'N/A';
                                        if ($products_supplied !== 'N/A' && strlen($products_supplied) > 60) {
                                            $truncated = htmlspecialchars(substr($products_supplied, 0, 60));
                                            echo $truncated . '... <a href="#" class="view-more-link" data-products="' . htmlspecialchars($products_supplied, ENT_QUOTES) . '" data-supplier-name="' . htmlspecialchars($supplier['company_name'], ENT_QUOTES) . '" style="color: black; text-decoration: none; font-size: 12px; cursor: pointer;">View More</a>';
                                        } else {
                                            echo htmlspecialchars($products_supplied);
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo $supplier['performance_rating'] >= 4 ? 'success' : ($supplier['performance_rating'] >= 3 ? 'warning' : 'danger'); ?>">
                                            <?php echo number_format($supplier['performance_rating'], 2); ?>/5.0
                                        </span>
                                    </td>
                                    <td><?php echo $supplier['total_orders']; ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $supplier['status']; ?>">
                                            <?php echo ucfirst($supplier['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (canEdit('suppliers')): ?>
                                        <a href="?edit=<?php echo $supplier['id']; ?>" class="btn btn-sm btn-secondary" onclick="openSupplierModal(<?php echo $supplier['id']; ?>, '<?php echo htmlspecialchars(addslashes($supplier['company_name'])); ?>', '<?php echo htmlspecialchars(addslashes($supplier['contact_person'] ?? '')); ?>', '<?php echo htmlspecialchars(addslashes($supplier['email'] ?? '')); ?>', '<?php echo htmlspecialchars(addslashes($supplier['phone'] ?? '')); ?>', '<?php echo htmlspecialchars(addslashes($supplier['address'] ?? '')); ?>', '<?php echo htmlspecialchars(addslashes($supplier['products_supplied'] ?? '')); ?>', <?php echo $supplier['performance_rating']; ?>); return false;">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <?php endif; ?>
                                        <a href="?view=history&id=<?php echo $supplier['id']; ?>" class="btn btn-sm btn-info">
                                            <i class="fas fa-history"></i> History
                                        </a>
                                        <?php if (isAdmin()): ?>
                                            <a href="?delete=<?php echo $supplier['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to permanently delete this supplier? This action cannot be undone.')">
                                                <i class="fas fa-trash"></i> Delete
                                            </a>
                                        <?php endif; ?>
                                    </td>
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
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Supplier Modal -->
<?php if (canCreate('suppliers')): ?>
<div id="supplierModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Add Supplier</h3>
            <button class="modal-close" onclick="closeModal('supplierModal')">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="supplier_id" id="supplier_id" value="0">
            
            <div class="form-group">
                <label for="company_name">Company Name *</label>
                <input type="text" name="company_name" id="company_name" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label for="contact_person">Contact Person</label>
                <input type="text" name="contact_person" id="contact_person" class="form-control">
            </div>
            
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" name="email" id="email" class="form-control">
            </div>
            
            <div class="form-group">
                <label for="phone">Phone (Philippine Format)</label>
                <input type="text" name="phone" id="phone" class="form-control" 
                       placeholder="09123456789 or +639123456789">
                <small class="text-muted">Format: 09123456789 or +639123456789</small>
            </div>
            
            <div class="form-group">
                <label for="address">Address</label>
                <textarea name="address" id="address" class="form-control" rows="2"></textarea>
            </div>
            
            <div class="form-group">
                <label for="products_supplied">Products Supplied</label>
                <textarea name="products_supplied" id="products_supplied" class="form-control" rows="2" placeholder="e.g., Electronics, Components, Raw Materials"></textarea>
            </div>
            
            <div class="form-group">
                <label for="performance_rating">Performance Rating (0-5)</label>
                <input type="number" name="performance_rating" id="performance_rating" class="form-control" step="0.01" min="0" max="5" value="0">
            </div>
            
            <div class="form-group">
                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-save"></i> Save Supplier
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function formatPhoneInput(input) {
    let value = input.value.replace(/\D/g, ''); // Remove non-digits
    
    // If starts with 63, format as +63 9 123456789
    if (value.startsWith('63') && value.length >= 12) {
        input.value = '+63 ' + value.substring(2, 3) + ' ' + value.substring(3);
    }
    // If starts with 0, format as 09 1234 5678
    else if (value.startsWith('0') && value.length >= 11) {
        input.value = value.substring(0, 2) + ' ' + value.substring(2, 6) + ' ' + value.substring(6);
    }
    // If starts with 9 and is 10 digits, format as 09 1234 5678
    else if (value.startsWith('9') && value.length === 10) {
        input.value = '0' + value.substring(0, 1) + ' ' + value.substring(1, 5) + ' ' + value.substring(5);
    }
}

function openSupplierModal(id, companyName, contactPerson, email, phone, address, productsSupplied, rating) {
    document.getElementById('supplier_id').value = id;
    document.getElementById('company_name').value = companyName;
    document.getElementById('contact_person').value = contactPerson;
    document.getElementById('email').value = email;
    document.getElementById('phone').value = phone;
    document.getElementById('address').value = address;
    document.getElementById('products_supplied').value = productsSupplied;
    document.getElementById('performance_rating').value = rating;
    document.getElementById('modalTitle').textContent = 'Edit Supplier';
    openModal('supplierModal');
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Phone input formatting
    const phoneInput = document.getElementById('phone');
    if (phoneInput) {
        phoneInput.addEventListener('input', function() {
            formatPhoneInput(this);
        });
        phoneInput.addEventListener('blur', function() {
            formatPhoneInput(this);
        });
    }
    
    // Bulk delete checkboxes
    const selectAllCheckbox = document.getElementById('selectAll');
    const supplierCheckboxes = document.querySelectorAll('.supplier-checkbox');
    const bulkDeleteActions = document.getElementById('bulkDeleteActions');
    const bulkDeleteHeaderActions = document.getElementById('bulkDeleteHeaderActions');
    const selectedCountSpan = document.getElementById('selectedCount');
    const headerSelectedCountSpan = document.getElementById('headerSelectedCount');
    
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            supplierCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateBulkDeleteUI();
        });
        
        supplierCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                updateBulkDeleteUI();
                const allChecked = Array.from(supplierCheckboxes).every(cb => cb.checked);
                const someChecked = Array.from(supplierCheckboxes).some(cb => cb.checked);
                selectAllCheckbox.checked = allChecked;
                selectAllCheckbox.indeterminate = someChecked && !allChecked;
            });
        });
        
        function updateBulkDeleteUI() {
            const selectedCount = Array.from(supplierCheckboxes).filter(cb => cb.checked).length;
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
    
    // View more links for products supplied
    document.querySelectorAll('.view-more-link').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const productsText = this.getAttribute('data-products');
            const supplierName = this.getAttribute('data-supplier-name');
            
            document.getElementById('productsSuppliedSupplierName').textContent = supplierName || 'N/A';
            
            const productsList = document.getElementById('productsSuppliedList');
            if (productsText && productsText !== 'N/A') {
                const products = productsText.split(',').map(p => p.trim()).filter(p => p);
                productsList.innerHTML = products.length > 0 
                    ? '<ul style="margin: 0; padding-left: 20px;">' + products.map(p => '<li>' + p + '</li>').join('') + '</ul>'
                    : '<p class="text-muted">No products listed.</p>';
            } else {
                productsList.innerHTML = '<p class="text-muted">No products listed.</p>';
            }
            
            openModal('productsSuppliedModal');
        });
    });
});

// Reset form when opening modal for new supplier
document.getElementById('supplierModal').addEventListener('click', function(e) {
    if (e.target.classList.contains('modal') || e.target.classList.contains('modal-close')) {
        document.getElementById('supplierModal').querySelector('form').reset();
        document.getElementById('supplier_id').value = '0';
        document.getElementById('modalTitle').textContent = 'Add Supplier';
    }
});

<?php if (canEdit('suppliers')): ?>
function viewSupplierProducts(supplierId, supplierName) {
    document.getElementById('link_supplier_id').value = supplierId;
    document.getElementById('supplierProductsTitle').textContent = 'Manage Products - ' + supplierName;
    
    // Load supplier's products
    fetch('?ajax=get_supplier_products&supplier_id=' + supplierId)
        .then(response => response.json())
        .then(products => {
            let html = '<div style="overflow-x: auto;"><table class="data-table"><thead><tr><th>Product</th><th>SKU</th><th>Supplier SKU</th><th>Price</th><th>Lead Time</th><th>Primary</th><th>Actions</th></tr></thead><tbody>';
            if (products.length === 0) {
                html += '<tr><td colspan="7" class="text-muted">No products linked to this supplier.</td></tr>';
            } else {
                products.forEach(product => {
                    html += `<tr>
                        <td><strong>${product.product_name}</strong></td>
                        <td>${product.product_sku}</td>
                        <td>${product.supplier_sku || 'N/A'}</td>
                        <td>${product.supplier_price ? '₱' + parseFloat(product.supplier_price).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',') : 'Default'}</td>
                        <td>${product.lead_time_days} days</td>
                        <td>${product.is_primary ? '<span class="badge badge-success">Yes</span>' : '<span class="badge badge-secondary">No</span>'}</td>
                        <td><a href="?unlink_product=${product.id}" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to unlink this product?')"><i class="fas fa-unlink"></i> Unlink</a></td>
                    </tr>`;
                });
            }
            html += '</tbody></table></div>';
            document.getElementById('supplierProductsList').innerHTML = html;
        })
        .catch(error => {
            console.error('Error loading products:', error);
            document.getElementById('supplierProductsList').innerHTML = '<p class="text-danger">Error loading products.</p>';
        });
    
    openModal('supplierProductsModal');
}

// Auto-fill supplier price when product is selected
document.addEventListener('change', function(e) {
    if (e.target.id === 'link_product_id') {
        const price = e.target.options[e.target.selectedIndex].dataset.price;
        const priceInput = document.getElementById('supplier_price');
        if (priceInput && price && !priceInput.value) {
            priceInput.value = price;
        }
    }
});
<?php endif; ?>
</script>

<!-- Supplier Products Modal -->
<?php if (canEdit('suppliers')): ?>
<div id="supplierProductsModal" class="modal">
    <div class="modal-content modal-large">
        <div class="modal-header">
            <h3 id="supplierProductsTitle">Manage Products</h3>
            <button class="modal-close" onclick="closeModal('supplierProductsModal')">&times;</button>
        </div>
        <div class="card-body">
            <div id="supplierProductsList">
                <p class="text-muted">Loading products...</p>
            </div>
            <hr>
            <h4>Link New Product</h4>
            <form method="POST" action="" id="linkProductForm">
                <input type="hidden" name="supplier_id" id="link_supplier_id" value="0">
                <div class="form-group">
                    <label for="link_product_id">Product *</label>
                    <select name="product_id" id="link_product_id" class="form-control" required>
                        <option value="">Select Product</option>
                        <?php foreach ($products as $product): ?>
                            <option value="<?php echo $product['id']; ?>" data-price="<?php echo $product['unit_price']; ?>">
                                <?php echo htmlspecialchars($product['name'] . ' (' . $product['sku'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="supplier_sku">Supplier SKU</label>
                    <input type="text" name="supplier_sku" id="supplier_sku" class="form-control" placeholder="Supplier's SKU for this product">
                </div>
                <div class="form-group">
                    <label for="supplier_price">Supplier Price</label>
                    <input type="number" name="supplier_price" id="supplier_price" class="form-control" step="0.01" min="0" placeholder="Leave blank to use product default price">
                </div>
                <div class="form-group">
                    <label for="lead_time_days">Lead Time (Days)</label>
                    <input type="number" name="lead_time_days" id="lead_time_days" class="form-control" min="1" value="7">
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="is_primary" id="is_primary"> Set as Primary Supplier for this product
                    </label>
                </div>
                <div class="form-group">
                    <button type="submit" name="link_product" class="btn btn-primary">
                        <i class="fas fa-link"></i> Link Product
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
// AJAX endpoint: Get products for a specific supplier
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_supplier_products' && isset($_GET['supplier_id'])) {
    header('Content-Type: application/json');
    $supplier_id = intval($_GET['supplier_id']);
    
    $stmt = $conn->prepare("
        SELECT DISTINCT sp.id, sp.supplier_sku, sp.supplier_price, sp.lead_time_days, sp.is_primary,
               p.name as product_name, p.sku as product_sku
        FROM supplier_products sp
        JOIN products p ON sp.product_id = p.id
        INNER JOIN inventory i ON p.id = i.product_id
        WHERE sp.supplier_id = ? AND sp.status = 'active'
        ORDER BY sp.is_primary DESC, p.name
    ");
    $stmt->bind_param("i", $supplier_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $supplier_products = [];
    while ($row = $result->fetch_assoc()) {
        $supplier_products[] = $row;
    }
    $stmt->close();
    
    echo json_encode($supplier_products);
    closeDBConnection($conn);
    exit;
}
?>

<script>
// Confirm bulk delete
function confirmBulkDelete() {
    const selectedCount = Array.from(document.querySelectorAll('.supplier-checkbox:checked')).length;
    if (selectedCount === 0) {
        alert('Please select at least one supplier to delete.');
        return false;
    }
    return confirm(`Are you sure you want to permanently delete ${selectedCount} supplier(s)? This action cannot be undone.`);
}
</script>

<!-- Products Supplied View Modal -->
<div id="productsSuppliedModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="productsSuppliedTitle">Products Supplied</h3>
            <button class="modal-close" onclick="closeModal('productsSuppliedModal')">&times;</button>
        </div>
        <div class="card-body">
            <p><strong>Supplier:</strong> <span id="productsSuppliedSupplierName"></span></p>
            <div style="margin-top: 15px;">
                <strong>Products:</strong>
                <div id="productsSuppliedList" style="margin-top: 10px; padding: 15px; background: #f5f5f5; border-radius: 5px; line-height: 1.8;">
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

