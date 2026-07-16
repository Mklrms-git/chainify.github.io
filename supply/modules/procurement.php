<?php
require_once '../config/config.php';
requireModuleAccess('procurement');

$conn = getDBConnection();

// Get messages from session (set after redirect)
$message = '';
$error = '';

if (isset($_SESSION['procurement_message'])) {
    $message = $_SESSION['procurement_message'];
    unset($_SESSION['procurement_message']);
}

if (isset($_SESSION['procurement_error'])) {
    $error = $_SESSION['procurement_error'];
    unset($_SESSION['procurement_error']);
}

// Also check for GET parameters (for compatibility)
if (isset($_GET['success']) && empty($message)) {
    $message = 'Operation completed successfully!';
}
if (isset($_GET['error']) && empty($error)) {
    $error = 'An error occurred. Please try again.';
}

// Create Automatic Purchase Order from Low Stock Alert
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_automatic_po'])) {
    requireCreatePermission('procurement');
    $expected_date = $_POST['expected_date'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $selected_products = $_POST['selected_products'] ?? [];
    
    // Validate expected date is not in the past
    if ($expected_date && $expected_date < date('Y-m-d')) {
        $_SESSION['procurement_error'] = 'Expected date cannot be in the past. Please select today\'s date or a future date.';
        closeDBConnection($conn);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
        exit;
    }
    
    if ($expected_date && !empty($selected_products)) {
        // Process all items with their warehouse information
        // This handles cases where the same product appears in multiple warehouses
        $product_items = [];
        foreach ($selected_products as $product_id) {
            $product_id = intval($product_id);
            $quantity = intval($_POST['quantity_' . $product_id] ?? 1);
            $unit_price = floatval($_POST['unit_price_' . $product_id] ?? 0);
            $warehouse_id = intval($_POST['warehouse_id_' . $product_id] ?? 0);
            
            if ($quantity > 0 && $unit_price >= 0 && $warehouse_id > 0) {
                // Get supplier for this product
                $stmt = $conn->prepare("
                    SELECT supplier_id 
                    FROM supplier_products 
                    WHERE product_id = ? AND status = 'active' 
                    ORDER BY is_primary DESC 
                    LIMIT 1
                ");
                $stmt->bind_param("i", $product_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $supplier_data = $result->fetch_assoc();
                $stmt->close();
                
                if ($supplier_data) {
                    $supplier_id = $supplier_data['supplier_id'];
                    if (!isset($product_items[$supplier_id])) {
                        $product_items[$supplier_id] = [];
                    }
                    $product_items[$supplier_id][] = [
                        'product_id' => $product_id,
                        'quantity' => $quantity,
                        'unit_price' => $unit_price,
                        'warehouse_id' => $warehouse_id
                    ];
                }
            }
        }
        
        // Create a PO for each supplier group
        $created_pos = [];
        foreach ($product_items as $supplier_id => $items) {
            $po_number = 'PO-AUTO-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
            $total_amount = 0;
            
            // Calculate total amount
            foreach ($items as $item) {
                $total_amount += $item['quantity'] * $item['unit_price'];
            }
            
            if (!empty($items)) {
                // Create PO with admin_approved = FALSE (requires admin approval)
                $stmt = $conn->prepare("INSERT INTO purchase_orders (po_number, supplier_id, created_by, order_date, delivery_date, total_amount, notes, admin_approved) VALUES (?, ?, ?, CURDATE(), ?, ?, ?, 0)");
                $stmt->bind_param("siisds", $po_number, $supplier_id, $_SESSION['user_id'], $expected_date, $total_amount, $notes);
                
                if ($stmt->execute()) {
                    $po_id = $stmt->insert_id;
                    
                    // Insert items with warehouse_id
                    $item_stmt = $conn->prepare("INSERT INTO purchase_order_items (po_id, product_id, warehouse_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?, ?)");
                    
                    foreach ($items as $item) {
                        $subtotal = $item['quantity'] * $item['unit_price'];
                        $warehouse_id = $item['warehouse_id'] ?? null;
                        // Bind parameters: po_id (i), product_id (i), warehouse_id (i or null), quantity (i), unit_price (d), subtotal (d)
                        $item_stmt->bind_param("iiiddd", $po_id, $item['product_id'], $warehouse_id, $item['quantity'], $item['unit_price'], $subtotal);
                        $item_stmt->execute();
                    }
                    
                    $item_stmt->close();
                    $created_pos[] = $po_number;
                    $stmt->close();
                    
                    // Get supplier company name for notification
                    $supplier_stmt = $conn->prepare("SELECT company_name FROM suppliers WHERE id = ?");
                    $supplier_stmt->bind_param("i", $supplier_id);
                    $supplier_stmt->execute();
                    $supplier_result = $supplier_stmt->get_result();
                    $supplier_data = $supplier_result->fetch_assoc();
                    $supplier_name = $supplier_data ? $supplier_data['company_name'] : 'Supplier ID ' . $supplier_id;
                    $supplier_stmt->close();
                    
                    // Get creator name for admin notification
                    $creator_stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
                    $creator_stmt->bind_param("i", $_SESSION['user_id']);
                    $creator_stmt->execute();
                    $creator_result = $creator_stmt->get_result();
                    $creator_data = $creator_result->fetch_assoc();
                    $creator_name = $creator_data ? $creator_data['full_name'] : 'Procurement Staff';
                    $creator_stmt->close();
                    
                    // Create notification for supplier
                    notifySupplier(
                        $supplier_id,
                        'po_created',
                        'New Automatic Purchase Order Created',
                        "A new automatic purchase order {$po_number} has been created for your company. Expected delivery date: " . date('M d, Y', strtotime($expected_date)) . ". This order requires admin approval before processing.",
                        'purchase_order',
                        $po_id
                    );
                    
                    // Create notification for admin users (requires approval)
                    notifyUsersByRole(
                        'admin',
                        'po_created',
                        'Automatic Purchase Order Requires Approval',
                        "Automatic Purchase Order {$po_number} has been created by {$creator_name} from low stock alert. Supplier: {$supplier_name}. Total Amount: " . formatCurrency($total_amount) . ". Expected delivery: " . date('M d, Y', strtotime($expected_date)) . ". Please review and approve.",
                        'purchase_order',
                        $po_id
                    );
                }
            }
        }
        
        if (!empty($created_pos)) {
            $_SESSION['procurement_message'] = "Automatic Purchase Order(s) " . implode(', ', $created_pos) . " created successfully! These orders require admin approval before being sent to suppliers.";
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1');
            exit();
        } else {
            $_SESSION['procurement_error'] = 'Failed to create automatic purchase order. Please check product selections and quantities.';
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit();
        }
    } else {
        $_SESSION['procurement_error'] = 'Please select at least one product and provide expected date.';
        closeDBConnection($conn);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
        exit();
    }
}

// Create Purchase Order
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_po'])) {
    requireCreatePermission('procurement');
    $expected_date = $_POST['expected_date'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $selected_products = $_POST['selected_products'] ?? [];
    
    // Validate expected date is not in the past
    if ($expected_date && $expected_date < date('Y-m-d')) {
        $_SESSION['procurement_error'] = 'Expected date cannot be in the past. Please select today\'s date or a future date.';
        closeDBConnection($conn);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
        exit;
    }
    
    if ($expected_date && !empty($selected_products)) {
        // Get supplier for each product (use primary supplier or first available)
        $product_suppliers = [];
        foreach ($selected_products as $product_id) {
            $product_id = intval($product_id);
            $stmt = $conn->prepare("
                SELECT supplier_id 
                FROM supplier_products 
                WHERE product_id = ? AND status = 'active' 
                ORDER BY is_primary DESC 
                LIMIT 1
            ");
            $stmt->bind_param("i", $product_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $supplier_data = $result->fetch_assoc();
            $stmt->close();
            
            if ($supplier_data) {
                $product_suppliers[$product_id] = $supplier_data['supplier_id'];
            }
        }
        
        // Group products by supplier
        $supplier_groups = [];
        foreach ($product_suppliers as $product_id => $supplier_id) {
            if (!isset($supplier_groups[$supplier_id])) {
                $supplier_groups[$supplier_id] = [];
            }
            $supplier_groups[$supplier_id][] = $product_id;
        }
        
        // Create a PO for each supplier group
        $created_pos = [];
        foreach ($supplier_groups as $supplier_id => $product_ids) {
            $po_number = 'PO-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
            $total_amount = 0;
            $items = [];
            
            // Get product details and quantities
            foreach ($product_ids as $product_id) {
                $quantity = intval($_POST['quantity_' . $product_id] ?? 1);
                $unit_price = floatval($_POST['unit_price_' . $product_id] ?? 0);
                
                if ($quantity > 0 && $unit_price >= 0) {
                    $items[] = [
                        'product_id' => $product_id,
                        'quantity' => $quantity,
                        'unit_price' => $unit_price
                    ];
                    $total_amount += $quantity * $unit_price;
                }
            }
            
            if (!empty($items)) {
                $stmt = $conn->prepare("INSERT INTO purchase_orders (po_number, supplier_id, created_by, order_date, delivery_date, total_amount, notes) VALUES (?, ?, ?, CURDATE(), ?, ?, ?)");
                $stmt->bind_param("siisds", $po_number, $supplier_id, $_SESSION['user_id'], $expected_date, $total_amount, $notes);
                
                if ($stmt->execute()) {
                    $po_id = $stmt->insert_id;
                    
                    // Insert items
                    $item_stmt = $conn->prepare("INSERT INTO purchase_order_items (po_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)");
                    
                    foreach ($items as $item) {
                        $subtotal = $item['quantity'] * $item['unit_price'];
                        $item_stmt->bind_param("iiidd", $po_id, $item['product_id'], $item['quantity'], $item['unit_price'], $subtotal);
                        $item_stmt->execute();
                    }
                    
                    $item_stmt->close();
                    $created_pos[] = $po_number;
                    $stmt->close();
                    
                    // Get supplier company name for notification
                    $supplier_stmt = $conn->prepare("SELECT company_name FROM suppliers WHERE id = ?");
                    $supplier_stmt->bind_param("i", $supplier_id);
                    $supplier_stmt->execute();
                    $supplier_result = $supplier_stmt->get_result();
                    $supplier_data = $supplier_result->fetch_assoc();
                    $supplier_name = $supplier_data ? $supplier_data['company_name'] : 'Supplier ID ' . $supplier_id;
                    $supplier_stmt->close();
                    
                    // Get creator name for admin notification
                    $creator_stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
                    $creator_stmt->bind_param("i", $_SESSION['user_id']);
                    $creator_stmt->execute();
                    $creator_result = $creator_stmt->get_result();
                    $creator_data = $creator_result->fetch_assoc();
                    $creator_name = $creator_data ? $creator_data['full_name'] : 'Procurement Staff';
                    $creator_stmt->close();
                    
                    // Create notification for supplier
                    notifySupplier(
                        $supplier_id,
                        'po_created',
                        'New Purchase Order Created',
                        "A new purchase order {$po_number} has been created for your company. Expected delivery date: " . date('M d, Y', strtotime($expected_date)),
                        'purchase_order',
                        $po_id
                    );
                    
                    // Create notification for admin users
                    notifyUsersByRole(
                        'admin',
                        'po_created',
                        'New Purchase Order Created',
                        "Purchase Order {$po_number} has been created by {$creator_name}. Supplier: {$supplier_name}. Total Amount: " . formatCurrency($total_amount) . ". Expected delivery: " . date('M d, Y', strtotime($expected_date)),
                        'purchase_order',
                        $po_id
                    );
                }
            }
        }
        
        if (!empty($created_pos)) {
            $_SESSION['procurement_message'] = "Purchase Order(s) " . implode(', ', $created_pos) . " created successfully!";
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1');
            exit();
        } else {
            $_SESSION['procurement_error'] = 'Failed to create purchase order. Please check product selections and quantities.';
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit();
        }
    } else {
        $_SESSION['procurement_error'] = 'Please select at least one product and provide expected date.';
        closeDBConnection($conn);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
        exit();
    }
}

// Delete Purchase Order
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_po'])) {
    $po_id = $_POST['po_id'] ?? 0;
    
    if ($po_id) {
        // Get PO details for permission check
        $po_check = $conn->prepare("SELECT po_number, status FROM purchase_orders WHERE id = ?");
        $po_check->bind_param("i", $po_id);
        $po_check->execute();
        $po_result = $po_check->get_result();
        $po_data = $po_result->fetch_assoc();
        $po_check->close();
        
        if (!$po_data) {
            $_SESSION['procurement_error'] = 'Purchase order not found.';
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit();
        } elseif (!canDelete('procurement', ['status' => $po_data['status']])) {
            $_SESSION['procurement_error'] = 'You do not have permission to delete this purchase order.';
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit();
        } else {
            // Check for related shipments (foreign key constraint prevents deletion)
            $shipment_check = $conn->prepare("SELECT COUNT(*) as ship_count FROM shipments WHERE po_id = ?");
            $shipment_check->bind_param("i", $po_id);
            $shipment_check->execute();
            $shipment_result = $shipment_check->get_result();
            $shipment_data = $shipment_result->fetch_assoc();
            $shipment_check->close();
            
            if ($shipment_data['ship_count'] > 0) {
                $_SESSION['procurement_error'] = "Cannot delete Purchase Order '{$po_data['po_number']}' because it has {$shipment_data['ship_count']} related shipment(s). To preserve historical data, please cancel the purchase order instead of deleting it.";
                closeDBConnection($conn);
                header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
                exit();
            } else {
                // No related shipments, safe to delete
                // Note: purchase_order_items will be deleted automatically due to ON DELETE CASCADE
                $stmt = $conn->prepare("DELETE FROM purchase_orders WHERE id = ?");
                $stmt->bind_param("i", $po_id);
                
                if ($stmt->execute()) {
                    $_SESSION['procurement_message'] = "Purchase Order '{$po_data['po_number']}' deleted successfully!";
                    $stmt->close();
                    closeDBConnection($conn);
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1');
                    exit();
                } else {
                    $_SESSION['procurement_error'] = 'Failed to delete purchase order: ' . $stmt->error;
                    $stmt->close();
                    closeDBConnection($conn);
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
                    exit();
                }
            }
        }
    } else {
        $_SESSION['procurement_error'] = 'Invalid purchase order ID for deletion.';
        closeDBConnection($conn);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
        exit();
    }
}

// Handle Delay Request Response (Approve/Reject)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['respond_delay_request'])) {
    $po_id = $_POST['po_id'] ?? 0;
    $action = $_POST['delay_action'] ?? ''; // 'approve' or 'reject'
    $response_notes = $_POST['response_notes'] ?? '';
    
    if ($po_id && in_array($action, ['approve', 'reject'])) {
        // Get PO details
        $po_check = $conn->prepare("SELECT po_number, supplier_id, created_by, delay_status, delay_requested_date FROM purchase_orders WHERE id = ?");
        $po_check->bind_param("i", $po_id);
        $po_check->execute();
        $po_result = $po_check->get_result();
        $po_data = $po_result->fetch_assoc();
        $po_check->close();
        
        if ($po_data && $po_data['delay_status'] === 'requested') {
            $new_status = $action === 'approve' ? 'approved' : 'rejected';
            $new_delivery_date = ($action === 'approve' && $po_data['delay_requested_date']) ? $po_data['delay_requested_date'] : null;
            
            // Update PO with delay response
            if ($action === 'approve' && $new_delivery_date) {
                $stmt = $conn->prepare("UPDATE purchase_orders SET delay_status = ?, delay_response_notes = ?, delay_responded_at = NOW(), delay_responded_by = ?, delivery_date = ? WHERE id = ?");
                $stmt->bind_param("ssiss", $new_status, $response_notes, $_SESSION['user_id'], $new_delivery_date, $po_id);
            } else {
                $stmt = $conn->prepare("UPDATE purchase_orders SET delay_status = ?, delay_response_notes = ?, delay_responded_at = NOW(), delay_responded_by = ? WHERE id = ?");
                $stmt->bind_param("ssis", $new_status, $response_notes, $_SESSION['user_id'], $po_id);
            }
            
            if ($stmt->execute()) {
                $po_number = $po_data['po_number'];
                $supplier_id = $po_data['supplier_id'];
                
                // Notify supplier
                if ($action === 'approve') {
                    $message = "Your delay request for Purchase Order {$po_number} has been approved. New delivery date: " . date('M d, Y', strtotime($new_delivery_date));
                    $title = "Delay Request Approved";
                    $notif_type = 'delay_request_approved';
                } else {
                    $message = "Your delay request for Purchase Order {$po_number} has been rejected. Please contact procurement for further details.";
                    if ($response_notes) {
                        $message .= " Notes: " . $response_notes;
                    }
                    $title = "Delay Request Rejected";
                    $notif_type = 'delay_request_rejected';
                }
                
                notifySupplier(
                    $supplier_id,
                    $notif_type,
                    $title,
                    $message,
                    'purchase_order',
                    $po_id
                );
                
                $_SESSION['procurement_message'] = "Delay request {$action}d successfully!";
                $stmt->close();
                closeDBConnection($conn);
                header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1');
                exit();
            } else {
                $_SESSION['procurement_error'] = 'Failed to respond to delay request.';
                $stmt->close();
            }
        } else {
            $_SESSION['procurement_error'] = 'Purchase order not found or delay request not available.';
        }
    } else {
        $_SESSION['procurement_error'] = 'Invalid delay request response.';
    }
    
    closeDBConnection($conn);
    header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
    exit();
}

// Mark Purchase Order as Received (Procurement Staff Only)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['mark_as_received'])) {
    requireModuleAccess('procurement');
    
    // Only procurement_staff and admin can mark as received
    if ($_SESSION['role'] !== 'procurement_staff' && $_SESSION['role'] !== 'admin') {
        $_SESSION['procurement_error'] = 'You do not have permission to mark purchase orders as received.';
        closeDBConnection($conn);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
        exit();
    }
    
    $po_id = $_POST['po_id'] ?? 0;
    
    if ($po_id) {
        // Get PO details
        $po_check = $conn->prepare("SELECT id, po_number, status FROM purchase_orders WHERE id = ?");
        $po_check->bind_param("i", $po_id);
        $po_check->execute();
        $po_result = $po_check->get_result();
        $po_data = $po_result->fetch_assoc();
        $po_check->close();
        
        if (!$po_data) {
            $_SESSION['procurement_error'] = 'Purchase order not found.';
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit();
        }
        
        if ($po_data['status'] === 'received') {
            $_SESSION['procurement_error'] = 'Purchase order is already marked as received.';
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit();
        }
        
        // Check if all shipments are delivered
        $shipments_stmt = $conn->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered_count FROM shipments WHERE po_id = ? AND status != 'cancelled'");
        $shipments_stmt->bind_param("i", $po_id);
        $shipments_stmt->execute();
        $shipments_result = $shipments_stmt->get_result();
        $shipments_data = $shipments_result->fetch_assoc();
        $shipments_stmt->close();
        
        $all_delivered = ($shipments_data && $shipments_data['total'] > 0 && 
            $shipments_data['delivered_count'] == $shipments_data['total']);
        
        if (!$all_delivered && $shipments_data['total'] > 0) {
            $_SESSION['procurement_error'] = 'Cannot mark as received. Not all shipments have been delivered by logistics.';
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit();
        }
        
        // Update PO status to 'received'
        $stmt = $conn->prepare("UPDATE purchase_orders SET status = 'received' WHERE id = ?");
        $stmt->bind_param("i", $po_id);
        
        if ($stmt->execute()) {
            $stmt->close();
            
            // If all shipments are delivered, update inventory
            if ($all_delivered) {
                // Get all shipments for this PO
                $shipments_list_stmt = $conn->prepare("SELECT id, destination_warehouse_id, type FROM shipments WHERE po_id = ? AND status = 'delivered'");
                $shipments_list_stmt->bind_param("i", $po_id);
                $shipments_list_stmt->execute();
                $shipments_list_result = $shipments_list_stmt->get_result();
                
                $inventory_updated = false;
                while ($shipment = $shipments_list_result->fetch_assoc()) {
                    $shipment_id = $shipment['id'];
                    $shipment_data = [
                        'po_id' => $po_id,
                        'destination_warehouse_id' => $shipment['destination_warehouse_id'],
                        'type' => $shipment['type']
                    ];
                    
                    // Update inventory - check both statuses match (shipment='delivered' AND PO='received')
                    // Since we just set PO to 'received' and we're only processing 'delivered' shipments, both match
                    try {
                        // Get items from purchase order
                        $items_stmt = $conn->prepare("SELECT product_id, quantity FROM purchase_order_items WHERE po_id = ?");
                        $items_stmt->bind_param("i", $po_id);
                        $items_stmt->execute();
                        $items_result = $items_stmt->get_result();
                        
                        if ($items_result && $items_result->num_rows > 0) {
                            $warehouse_id = $shipment['destination_warehouse_id'];
                            
                            if ($warehouse_id) {
                                while ($item = $items_result->fetch_assoc()) {
                                    $product_id = $item['product_id'];
                                    $quantity = $item['quantity'];
                                    
                                    // Add to inventory
                                    $check_stmt = $conn->prepare("SELECT id, quantity FROM inventory WHERE product_id = ? AND warehouse_id = ?");
                                    $check_stmt->bind_param("ii", $product_id, $warehouse_id);
                                    $check_stmt->execute();
                                    $check_result = $check_stmt->get_result();
                                    $existing = $check_result->fetch_assoc();
                                    $check_stmt->close();
                                    
                                    if ($existing) {
                                        $update_stmt = $conn->prepare("UPDATE inventory SET quantity = quantity + ? WHERE product_id = ? AND warehouse_id = ?");
                                        $update_stmt->bind_param("iii", $quantity, $product_id, $warehouse_id);
                                        $update_stmt->execute();
                                        $update_stmt->close();
                                    } else {
                                        $insert_stmt = $conn->prepare("INSERT INTO inventory (product_id, warehouse_id, quantity) VALUES (?, ?, ?)");
                                        $insert_stmt->bind_param("iii", $product_id, $warehouse_id, $quantity);
                                        $insert_stmt->execute();
                                        $insert_stmt->close();
                                    }
                                    
                                    // Create stock movement record
                                    $movement_notes = "Purchase Order received - Inventory updated (Shipment: {$shipment_id})";
                                    $movement_stmt = $conn->prepare("INSERT INTO stock_movements (product_id, warehouse_id, movement_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?, ?, 'in', ?, 'purchase_order', ?, ?, ?)");
                                    $movement_stmt->bind_param("iiissi", $product_id, $warehouse_id, $quantity, $po_id, $movement_notes, $_SESSION['user_id']);
                                    $movement_stmt->execute();
                                    $movement_stmt->close();
                                }
                                $inventory_updated = true;
                            }
                        }
                        
                        $items_stmt->close();
                    } catch (Exception $e) {
                        error_log("Inventory update failed for shipment {$shipment_id}: " . $e->getMessage());
                    }
                }
                
                $shipments_list_stmt->close();
                
                if ($inventory_updated) {
                    $_SESSION['procurement_message'] = "Purchase Order '{$po_data['po_number']}' marked as received and inventory has been updated!";
                } else {
                    $_SESSION['procurement_message'] = "Purchase Order '{$po_data['po_number']}' marked as received. Note: Inventory update may have failed - please check.";
                }
            } else {
                $_SESSION['procurement_message'] = "Purchase Order '{$po_data['po_number']}' marked as received. Inventory will be updated when all shipments are delivered.";
            }
            
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1');
            exit();
        } else {
            $_SESSION['procurement_error'] = 'Failed to mark purchase order as received: ' . $stmt->error;
            $stmt->close();
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit();
        }
    } else {
        $_SESSION['procurement_error'] = 'Invalid purchase order ID.';
        closeDBConnection($conn);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
        exit();
    }
}

// Update PO Status
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status'])) {
    $po_id = $_POST['po_id'] ?? 0;
    $status = $_POST['status'] ?? '';
    
    if ($po_id && $status) {
        // Check if user can approve this PO
        $po_check = $conn->prepare("SELECT created_by, total_amount, status FROM purchase_orders WHERE id = ?");
        $po_check->bind_param("i", $po_id);
        $po_check->execute();
        $po_result = $po_check->get_result();
        $po_data = $po_result->fetch_assoc();
        $po_check->close();
        
        if ($po_data) {
            $can_approve = canApprove('procurement', [
                'created_by' => $po_data['created_by'],
                'total_amount' => $po_data['total_amount']
            ]);
            
            if (!$can_approve && $status === 'approved') {
                $_SESSION['procurement_error'] = 'You do not have permission to approve this purchase order. Large orders require admin approval.';
                closeDBConnection($conn);
                header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
                exit();
            } else {
                // Get PO details including supplier_id, created_by, and approval status
                $po_details_stmt = $conn->prepare("SELECT po_number, supplier_id, created_by, admin_approved, supplier_approved, status FROM purchase_orders WHERE id = ?");
                $po_details_stmt->bind_param("i", $po_id);
                $po_details_stmt->execute();
                $po_details_result = $po_details_stmt->get_result();
                $po_details = $po_details_result->fetch_assoc();
                $po_details_stmt->close();
                
                // Handle admin approval
                $is_fully_approved = false;
                if ($status === 'approved' && !$po_details['admin_approved']) {
                    // Admin is approving - set status to 'approved' immediately (on admin side)
                    // Supplier still needs to approve before it can be scheduled to logistics
                    $stmt = $conn->prepare("UPDATE purchase_orders SET admin_approved = TRUE, admin_approved_by = ?, admin_approved_at = NOW(), status = 'approved' WHERE id = ?");
                    $stmt->bind_param("ii", $_SESSION['user_id'], $po_id);
                    // Check if supplier has already approved to determine if fully approved
                    if ($po_details['supplier_approved']) {
                        $is_fully_approved = true;
                    }
                } else {
                    // Other status updates (not approval) - use normal update
                    $stmt = $conn->prepare("UPDATE purchase_orders SET status = ? WHERE id = ?");
                    $stmt->bind_param("si", $status, $po_id);
                }
                
                if ($stmt->execute()) {
                    // Send notifications when PO is fully approved (both admin and supplier approved)
                    // Check if status is now 'approved' (both parties approved)
                    $final_check_stmt = $conn->prepare("SELECT status, admin_approved, supplier_approved FROM purchase_orders WHERE id = ?");
                    $final_check_stmt->bind_param("i", $po_id);
                    $final_check_stmt->execute();
                    $final_result = $final_check_stmt->get_result();
                    $final_data = $final_result->fetch_assoc();
                    $final_check_stmt->close();
                    
                    if ($final_data && $final_data['status'] === 'approved' && $final_data['admin_approved'] && $final_data['supplier_approved']) {
                        $po_number = $po_details['po_number'];
                        
                        // Notify supplier that both parties have approved
                        if ($po_details['supplier_id']) {
                            $supplier_id = $po_details['supplier_id'];
                            $message = "Purchase Order {$po_number} has been fully approved. You can now schedule the shipment.";
                            
                            // Legacy notification (po_notifications table)
                            $notif_stmt = $conn->prepare("INSERT INTO po_notifications (po_id, supplier_id, notification_type, message) VALUES (?, ?, 'po_approved', ?)");
                            $notif_stmt->bind_param("iis", $po_id, $supplier_id, $message);
                            $notif_stmt->execute();
                            $notif_stmt->close();
                            
                            // New notification system
                            notifySupplier(
                                $supplier_id,
                                'po_fully_approved',
                                'Purchase Order Fully Approved',
                                "Purchase Order {$po_number} has been fully approved by both admin and supplier. You can now schedule the shipment.",
                                'purchase_order',
                                $po_id
                            );
                        }
                        
                        // Notify procurement staff who created the PO
                        if ($po_details['created_by']) {
                            $created_by = $po_details['created_by'];
                            // Get user role
                            $user_stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
                            $user_stmt->bind_param("i", $created_by);
                            $user_stmt->execute();
                            $user_result = $user_stmt->get_result();
                            $user_data = $user_result->fetch_assoc();
                            $user_stmt->close();
                            
                            if ($user_data && $user_data['role']) {
                                createNotification(
                                    $created_by,
                                    $user_data['role'],
                                    'po_fully_approved',
                                    'Purchase Order Fully Approved',
                                    "Purchase Order {$po_number} that you created has been fully approved by both admin and supplier. The supplier can now schedule the shipment.",
                                    'purchase_order',
                                    $po_id
                                );
                            }
                        }
                    } elseif ($final_data && $final_data['admin_approved'] && !$final_data['supplier_approved']) {
                        // Only admin approved - notify supplier that admin has approved
                        $po_number = $po_details['po_number'];
                        
                        if ($po_details['supplier_id']) {
                            $supplier_id = $po_details['supplier_id'];
                            $message = "Purchase Order {$po_number} has been approved by admin. Waiting for your approval.";
                            
                            // Legacy notification (po_notifications table)
                            $notif_stmt = $conn->prepare("INSERT INTO po_notifications (po_id, supplier_id, notification_type, message) VALUES (?, ?, 'po_approved', ?)");
                            $notif_stmt->bind_param("iis", $po_id, $supplier_id, $message);
                            $notif_stmt->execute();
                            $notif_stmt->close();
                            
                            // New notification system
                            notifySupplier(
                                $supplier_id,
                                'po_admin_approved',
                                'Purchase Order Approved by Admin',
                                "Purchase Order {$po_number} has been approved by admin. Please review and approve the order.",
                                'purchase_order',
                                $po_id
                            );
                        }
                    }
                    
                    $_SESSION['procurement_message'] = 'Purchase order status updated successfully!';
                    $stmt->close();
                    closeDBConnection($conn);
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1');
                    exit();
                } else {
                    $_SESSION['procurement_error'] = 'Failed to update status.';
                    $stmt->close();
                    closeDBConnection($conn);
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
                    exit();
                }
            }
        } else {
            $_SESSION['procurement_error'] = 'Purchase order not found.';
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit();
        }
    } else {
        $_SESSION['procurement_error'] = 'Please provide purchase order ID and status.';
        closeDBConnection($conn);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
        exit();
    }
}

// Submit Supplier Rating
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_rating'])) {
    requireModuleAccess('procurement');
    
    // Only procurement_staff and admin can rate suppliers
    if ($_SESSION['role'] !== 'procurement_staff' && $_SESSION['role'] !== 'admin') {
        $_SESSION['procurement_error'] = 'You do not have permission to rate suppliers.';
        closeDBConnection($conn);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
        exit();
    }
    
    $po_id = intval($_POST['po_id'] ?? 0);
    $rating = floatval($_POST['rating'] ?? 0);
    $comments = trim($_POST['comments'] ?? '');
    
    // Validate rating (1-5)
    if ($rating < 1 || $rating > 5) {
        $_SESSION['procurement_error'] = 'Rating must be between 1 and 5 stars.';
        closeDBConnection($conn);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
        exit();
    }
    
    if ($po_id) {
        // Check if PO exists and is received/delivered
        $po_check = $conn->prepare("SELECT id, po_number, supplier_id, status FROM purchase_orders WHERE id = ?");
        $po_check->bind_param("i", $po_id);
        $po_check->execute();
        $po_result = $po_check->get_result();
        $po_data = $po_result->fetch_assoc();
        $po_check->close();
        
        if (!$po_data) {
            $_SESSION['procurement_error'] = 'Purchase order not found.';
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit();
        }
        
        // Only allow rating for received orders
        if ($po_data['status'] !== 'received') {
            $_SESSION['procurement_error'] = 'You can only rate suppliers for received purchase orders.';
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit();
        }
        
        // Check if rating already exists for this PO
        $rating_check = $conn->prepare("SELECT id FROM supplier_ratings WHERE po_id = ?");
        if ($rating_check === false) {
            $_SESSION['procurement_error'] = 'Database error: supplier_ratings table may not exist. Please run the migration: database/migration_add_supplier_ratings.sql';
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit();
        }
        
        $rating_check->bind_param("i", $po_id);
        $rating_check->execute();
        $rating_result = $rating_check->get_result();
        $existing_rating = $rating_result->fetch_assoc();
        $rating_check->close();
        
        if ($existing_rating) {
            $_SESSION['procurement_error'] = 'This purchase order has already been rated.';
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit();
        }
        
        // Insert rating
        $stmt = $conn->prepare("INSERT INTO supplier_ratings (po_id, supplier_id, rated_by, rating, comments) VALUES (?, ?, ?, ?, ?)");
        if ($stmt === false) {
            $_SESSION['procurement_error'] = 'Database error: supplier_ratings table may not exist. Please run the migration: database/migration_add_supplier_ratings.sql. Error: ' . $conn->error;
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit();
        }
        
        $stmt->bind_param("iiids", $po_id, $po_data['supplier_id'], $_SESSION['user_id'], $rating, $comments);
        
        if ($stmt->execute()) {
            $stmt->close();
            $_SESSION['procurement_message'] = "Rating submitted successfully for Purchase Order '{$po_data['po_number']}'!";
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1');
            exit();
        } else {
            $_SESSION['procurement_error'] = 'Failed to submit rating: ' . $stmt->error;
            $stmt->close();
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit();
        }
    } else {
        $_SESSION['procurement_error'] = 'Invalid purchase order ID.';
        closeDBConnection($conn);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
        exit();
    }
}

// Get purchase orders
$status_filter = $_GET['status'] ?? '';
$where_clause = $status_filter ? "WHERE po.status = '$status_filter'" : "";

$purchase_orders = [];
$result = $conn->query("
    SELECT po.*, s.company_name as supplier_name, u.full_name as created_by_name,
           (SELECT COUNT(*) FROM shipments WHERE po_id = po.id AND status != 'cancelled') as shipment_count,
           (SELECT COUNT(*) FROM shipments WHERE po_id = po.id AND status = 'delivered') as delivered_shipment_count,
           u2.full_name as delay_responded_by_name
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    JOIN users u ON po.created_by = u.id
    LEFT JOIN users u2 ON po.delay_responded_by = u2.id
    $where_clause
    ORDER BY po.created_at DESC
");
while ($row = $result->fetch_assoc()) {
    $purchase_orders[] = $row;
}

// Auto-detect delays: If delivery_date passed and status not scheduled/received/cancelled and no delay request yet
$today = date('Y-m-d');
$delay_detections = [];
foreach ($purchase_orders as &$po) {
    if ($po['delivery_date'] && 
        !in_array($po['status'], ['received', 'cancelled', 'scheduled']) &&
        ($po['delay_status'] ?? 'none') === 'none') {
        $expected_date = strtotime($po['delivery_date']);
        $today_timestamp = strtotime($today);
        if ($today_timestamp > $expected_date) {
            // Mark as delayed (but supplier needs to request delay)
            // We don't auto-set delay_status to 'requested' - supplier must request it
        }
    }
}
unset($po);

// Get suppliers
$suppliers = [];
$result = $conn->query("SELECT id, company_name FROM suppliers WHERE status = 'active' ORDER BY company_name");
while ($row = $result->fetch_assoc()) {
    $suppliers[] = $row;
}

// Get products (only those in inventory, will be filtered by supplier via AJAX)
$products = [];
$result = $conn->query("SELECT DISTINCT p.id, p.name, p.sku, p.unit_price FROM products p INNER JOIN inventory i ON p.id = i.product_id ORDER BY p.name");
while ($row = $result->fetch_assoc()) {
    $products[] = $row;
}

// Get warehouses (for shipment scheduling)
$warehouses = [];
$result = $conn->query("SELECT id, name FROM warehouses WHERE status = 'active' ORDER BY name");
while ($row = $result->fetch_assoc()) {
    $warehouses[] = $row;
}

// Get vehicles (for shipment scheduling)
$vehicles = [];
$result = $conn->query("SELECT id, vehicle_number, vehicle_type, status FROM vehicles ORDER BY vehicle_number");
while ($row = $result->fetch_assoc()) {
    $vehicles[] = $row;
}

// Get drivers (for shipment scheduling)
$drivers = [];
$result = $conn->query("SELECT id, driver_code, full_name, status FROM drivers ORDER BY full_name");
while ($row = $result->fetch_assoc()) {
    $drivers[] = $row;
}

// Get low stock items for procurement dashboard
$low_stock_items = [];
$low_stock_result = $conn->query("
    SELECT 
        p.id as product_id,
        p.name as product_name,
        p.sku,
        p.min_stock_level,
        i.quantity as current_quantity,
        i.warehouse_id,
        w.name as warehouse_name,
        sp.supplier_id,
        s.company_name as supplier_name,
        COALESCE(sp.supplier_price, p.unit_price) as unit_price,
        sp.is_primary
    FROM inventory i
    JOIN products p ON i.product_id = p.id
    JOIN warehouses w ON i.warehouse_id = w.id
    LEFT JOIN supplier_products sp ON p.id = sp.product_id AND sp.status = 'active' AND sp.is_primary = 1
    LEFT JOIN suppliers s ON sp.supplier_id = s.id AND s.status = 'active'
    WHERE i.quantity <= p.min_stock_level AND sp.supplier_id IS NOT NULL
    ORDER BY i.quantity ASC, p.name ASC, w.name ASC
");
while ($row = $low_stock_result->fetch_assoc()) {
    $low_stock_items[] = $row;
}

// AJAX endpoint: Get products for a specific supplier
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_products' && isset($_GET['supplier_id'])) {
    header('Content-Type: application/json');
    $supplier_id = intval($_GET['supplier_id']);
    
    // Get products linked to this supplier (only those in inventory)
    $stmt = $conn->prepare("
        SELECT DISTINCT p.id, p.name, p.sku, p.unit_price, 
               sp.supplier_price, sp.supplier_sku, sp.is_primary
        FROM products p
        INNER JOIN supplier_products sp ON p.id = sp.product_id
        INNER JOIN inventory i ON p.id = i.product_id
        WHERE sp.supplier_id = ? AND sp.status = 'active'
        ORDER BY sp.is_primary DESC, p.name
    ");
    $stmt->bind_param("i", $supplier_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $supplier_products = [];
    while ($row = $result->fetch_assoc()) {
        // Use supplier price if available, otherwise use product default price
        $price = $row['supplier_price'] ?? $row['unit_price'];
        $supplier_products[] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'sku' => $row['sku'],
            'unit_price' => $price,
            'supplier_sku' => $row['supplier_sku'],
            'is_primary' => $row['is_primary']
        ];
    }
    $stmt->close();
    
    echo json_encode($supplier_products);
    closeDBConnection($conn);
    exit;
}

// Create Shipment from PO
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_shipment_from_po'])) {
    requireCreatePermission('logistics');
    $po_id = $_POST['po_id'] ?? 0;
    $scheduled_date = $_POST['scheduled_date'] ?? '';
    $transport_cost = $_POST['transport_cost'] ?? 0;
    $destination_warehouse_id = $_POST['destination_warehouse_id'] ?? 0;
    $vehicle_id = $_POST['vehicle_id'] ?? null;
    $driver_id = $_POST['driver_id'] ?? null;
    
    // Validate scheduled date is not in the past
    if ($scheduled_date && $scheduled_date < date('Y-m-d')) {
        $_SESSION['procurement_error'] = 'Scheduled date cannot be in the past. Please select today\'s date or a future date.';
        closeDBConnection($conn);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
        exit;
    }
    
    // Convert empty strings to null for nullable fields
    $vehicle_id = ($vehicle_id === '' || $vehicle_id === 0) ? null : (int)$vehicle_id;
    $driver_id = ($driver_id === '' || $driver_id === 0) ? null : (int)$driver_id;
    
    if ($po_id && $scheduled_date && $destination_warehouse_id) {
        // Get PO details
        $po_stmt = $conn->prepare("SELECT supplier_id FROM purchase_orders WHERE id = ?");
        $po_stmt->bind_param("i", $po_id);
        $po_stmt->execute();
        $po_result = $po_stmt->get_result();
        $po_data = $po_result->fetch_assoc();
        $po_stmt->close();
        
        if (!$po_data) {
            $_SESSION['procurement_error'] = 'Purchase order not found.';
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit;
        }
        
        // Get PO items
        $items_stmt = $conn->prepare("SELECT product_id, quantity FROM purchase_order_items WHERE po_id = ?");
        $items_stmt->bind_param("i", $po_id);
        $items_stmt->execute();
        $items_result = $items_stmt->get_result();
        
        $items = [];
        while ($row = $items_result->fetch_assoc()) {
            $items[] = $row;
        }
        $items_stmt->close();
        
        if (empty($items)) {
            $_SESSION['procurement_error'] = 'Purchase order has no items.';
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit;
        }
        
        // Create shipment
        $shipment_number = 'SHIP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        $tracking_number = 'TRK-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        $type = 'inbound';
        $supplier_id = $po_data['supplier_id'];
        
        $stmt = $conn->prepare("INSERT INTO shipments (shipment_number, po_id, type, destination_warehouse_id, supplier_id, vehicle_id, driver_id, scheduled_date, tracking_number, transport_cost, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sisiiiissdi", $shipment_number, $po_id, $type, $destination_warehouse_id, $supplier_id, $vehicle_id, $driver_id, $scheduled_date, $tracking_number, $transport_cost, $_SESSION['user_id']);
        
        if ($stmt->execute()) {
            $shipment_id = $stmt->insert_id;
            
            // Insert shipment items
            $item_stmt = $conn->prepare("INSERT INTO shipment_items (shipment_id, product_id, quantity) VALUES (?, ?, ?)");
            foreach ($items as $item) {
                $item_stmt->bind_param("iii", $shipment_id, $item['product_id'], $item['quantity']);
                $item_stmt->execute();
            }
            $item_stmt->close();
            
            // Update PO status to 'scheduled' when shipment is scheduled
            $po_update_stmt = $conn->prepare("UPDATE purchase_orders SET status = 'scheduled' WHERE id = ? AND status != 'received' AND status != 'cancelled'");
            $po_update_stmt->bind_param("i", $po_id);
            $po_update_stmt->execute();
            $po_update_stmt->close();
            
            $_SESSION['procurement_message'] = "Shipment {$shipment_number} created successfully!";
            $stmt->close();
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1');
            exit;
        } else {
            $_SESSION['procurement_error'] = 'Failed to create shipment: ' . $stmt->error;
            $stmt->close();
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit;
        }
    } else {
        $_SESSION['procurement_error'] = 'Please fill all required fields.';
        closeDBConnection($conn);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
        exit;
    }
}

// AJAX endpoint: Get low stock items for automatic order
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_low_stock_items') {
    header('Content-Type: application/json');
    
    $low_stock_items_ajax = [];
    $result = $conn->query("
        SELECT 
            p.id as product_id,
            p.name as product_name,
            p.sku,
            p.min_stock_level,
            i.quantity as current_quantity,
            i.warehouse_id,
            w.name as warehouse_name,
            sp.supplier_id,
            s.company_name as supplier_name,
            COALESCE(sp.supplier_price, p.unit_price) as unit_price,
            sp.is_primary
        FROM inventory i
        JOIN products p ON i.product_id = p.id
        JOIN warehouses w ON i.warehouse_id = w.id
        LEFT JOIN supplier_products sp ON p.id = sp.product_id AND sp.status = 'active' AND sp.is_primary = 1
        LEFT JOIN suppliers s ON sp.supplier_id = s.id AND s.status = 'active'
        WHERE i.quantity <= p.min_stock_level AND sp.supplier_id IS NOT NULL
        ORDER BY sp.is_primary DESC, i.quantity ASC, p.name ASC, w.name ASC
    ");
    
    while ($row = $result->fetch_assoc()) {
        // Calculate reorder quantity (min_stock_level * 2 - current_quantity, with minimum of min_stock_level)
        $reorder_qty = max($row['min_stock_level'] * 2 - $row['current_quantity'], $row['min_stock_level']);
        $row['reorder_quantity'] = $reorder_qty;
        $low_stock_items_ajax[] = $row;
    }
    
    echo json_encode($low_stock_items_ajax);
    closeDBConnection($conn);
    exit;
}

// AJAX endpoint: Get PO details
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_po_details' && isset($_GET['po_id'])) {
    header('Content-Type: application/json');
    $po_id = intval($_GET['po_id']);
    
    // Get PO details
    $stmt = $conn->prepare("
        SELECT po.*, s.company_name as supplier_name, u.full_name as created_by_name,
               u2.full_name as delay_responded_by_name
        FROM purchase_orders po
        JOIN suppliers s ON po.supplier_id = s.id
        JOIN users u ON po.created_by = u.id
        LEFT JOIN users u2 ON po.delay_responded_by = u2.id
        WHERE po.id = ?
    ");
    $stmt->bind_param("i", $po_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $po_data = $result->fetch_assoc();
    $stmt->close();
    
    if ($po_data) {
        // Get PO items with warehouse information
        $stmt = $conn->prepare("
            SELECT poi.*, p.name as product_name, p.sku, poi.warehouse_id, w.name as warehouse_name
            FROM purchase_order_items poi
            JOIN products p ON poi.product_id = p.id
            LEFT JOIN warehouses w ON poi.warehouse_id = w.id
            WHERE poi.po_id = ?
            ORDER BY poi.id
        ");
        $stmt->bind_param("i", $po_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $items = [];
        $warehouse_counts = []; // Count occurrences of each warehouse
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
            if ($row['warehouse_id']) {
                $warehouse_counts[$row['warehouse_id']] = ($warehouse_counts[$row['warehouse_id']] ?? 0) + 1;
            }
        }
        $stmt->close();
        
        $po_data['items'] = $items;
        
        // Determine destination warehouse: use the most common warehouse, or the first one if all are different
        if (!empty($warehouse_counts)) {
            // Find the warehouse with the most occurrences
            $max_count = max($warehouse_counts);
            $destination_warehouse_id = array_keys($warehouse_counts, $max_count)[0];
            $po_data['destination_warehouse_id'] = intval($destination_warehouse_id);
            
            // Also check if all items have the same warehouse
            $unique_warehouses = array_unique(array_column($items, 'warehouse_id'));
            $unique_warehouses = array_filter($unique_warehouses); // Remove null values
            if (count($unique_warehouses) === 1) {
                // All items go to the same warehouse - use that one
                $po_data['destination_warehouse_id'] = intval(reset($unique_warehouses));
            }
        } else {
            $po_data['destination_warehouse_id'] = null;
        }
        
        // Get rating information if PO is received
        if ($po_data['status'] === 'received') {
            $rating_stmt = $conn->prepare("
                SELECT sr.*, u.full_name as rated_by_name
                FROM supplier_ratings sr
                LEFT JOIN users u ON sr.rated_by = u.id
                WHERE sr.po_id = ?
            ");
            
            // Check if table exists, if not, set rating to null
            if ($rating_stmt !== false) {
                $rating_stmt->bind_param("i", $po_id);
                $rating_stmt->execute();
                $rating_result = $rating_stmt->get_result();
                $rating_data = $rating_result->fetch_assoc();
                $rating_stmt->close();
                
                $po_data['rating'] = $rating_data;
            } else {
                // Table doesn't exist yet, set rating to null
                $po_data['rating'] = null;
            }
        }
    }
    
    echo json_encode($po_data);
    closeDBConnection($conn);
    exit;
}

closeDBConnection($conn);

$pageTitle = 'Procurement & Supplier Coordination';
include '../includes/header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-shopping-cart"></i> Procurement & Supplier Coordination</h2>
    <?php if (canCreate('procurement')): ?>
    <button class="btn btn-primary" onclick="openModal('createPOModal')">
        <i class="fas fa-plus"></i> Create Purchase Order
    </button>
    <?php endif; ?>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="filters-bar">
    <div class="filter-group">
        <label>Filter by Status</label>
        <select class="form-control" onchange="window.location.href='?status=' + this.value">
            <option value="">All Statuses</option>
            <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
            <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
            <option value="scheduled" <?php echo $status_filter == 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
            <option value="in_transit" <?php echo $status_filter == 'in_transit' ? 'selected' : ''; ?>>In Transit</option>
            <option value="received" <?php echo $status_filter == 'received' ? 'selected' : ''; ?>>Received</option>
        </select>
    </div>
</div>

<div class="dashboard-card">
    <div class="card-header">
        <h3><i class="fas fa-list"></i> Purchase Orders</h3>
    </div>
    <div class="card-body">
        <?php if (empty($purchase_orders)): ?>
            <p class="text-muted">No purchase orders found.</p>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>PO Number</th>
                            <th>Supplier</th>
                            <th>Order Date</th>
                            <th>Expected Date</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Created By</th>
                            <th>Actions</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($purchase_orders as $po): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($po['po_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars($po['supplier_name']); ?></td>
                                <td><?php echo formatDate($po['order_date']); ?></td>
                                <td><?php echo $po['delivery_date'] ? formatDate($po['delivery_date']) : 'N/A'; ?>
                                    <?php 
                                    // Show delay badge if expected date is exceeded
                                    if ($po['delivery_date'] && !in_array($po['status'], ['received', 'cancelled'])) {
                                        $expected_date = strtotime($po['delivery_date']);
                                        $today = strtotime(date('Y-m-d'));
                                        if ($today > $expected_date) {
                                            $days_delayed = floor(($today - $expected_date) / 86400);
                                            echo ' <span class="badge badge-danger" title="Delayed by ' . $days_delayed . ' day(s)">DELAYED</span>';
                                        }
                                    }
                                    ?>
                                </td>
                                <td><?php echo formatCurrency($po['total_amount']); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $po['status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $po['status'])); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($po['created_by_name']); ?></td>
                                <td>
                                    <div style="display: flex; flex-wrap: wrap; gap: 5px; align-items: center;">
                                        <button class="btn btn-sm btn-secondary" onclick="viewPODetails(<?php echo $po['id']; ?>)">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <?php 
                                        // Show delay request response button if delay is requested
                                        if (($po['delay_status'] ?? 'none') === 'requested' && canEdit('procurement')): 
                                        ?>
                                            <button class="btn btn-sm btn-info" 
                                                    data-po-id="<?php echo $po['id']; ?>"
                                                    data-po-number="<?php echo htmlspecialchars($po['po_number'], ENT_QUOTES); ?>"
                                                    data-supplier-name="<?php echo htmlspecialchars($po['supplier_name'], ENT_QUOTES); ?>"
                                                    data-requested-date="<?php echo htmlspecialchars($po['delay_requested_date'] ?? '', ENT_QUOTES); ?>"
                                                    data-delay-notes="<?php echo htmlspecialchars($po['delay_notes'] ?? '', ENT_QUOTES | ENT_HTML5); ?>"
                                                    onclick="openDelayResponseModalFromButton(this)">
                                                <i class="fas fa-clock"></i> Respond to Delay
                                            </button>
                                        <?php endif; ?>
                                        <?php 
                                        if ($po['status'] == 'pending' && canApprove('procurement', [
                                            'created_by' => $po['created_by'],
                                            'total_amount' => $po['total_amount']
                                        ])): 
                                        ?>
                                            <button class="btn btn-sm btn-success" onclick="confirmUpdatePOStatus(<?php echo $po['id']; ?>, 'approved', '<?php echo htmlspecialchars($po['po_number'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($po['supplier_name'], ENT_QUOTES); ?>', <?php echo $po['total_amount']; ?>)">
                                                <i class="fas fa-check"></i> Approve
                                            </button>
                                        <?php endif; ?>
                                        <?php 
                                        // Show "Mark as Received" button for procurement staff when all shipments are delivered
                                        $can_mark_received = ($_SESSION['role'] === 'procurement_staff' || $_SESSION['role'] === 'admin') && 
                                                             $po['status'] !== 'received' && 
                                                             $po['status'] !== 'cancelled' &&
                                                             ($po['shipment_count'] ?? 0) > 0 &&
                                                             ($po['delivered_shipment_count'] ?? 0) == ($po['shipment_count'] ?? 0);
                                        if ($can_mark_received):
                                        ?>
                                            <form method="POST" action="" style="display: inline; margin: 0;" onsubmit="return confirm('Are you sure you want to mark this Purchase Order as received? This will update the inventory if all shipments are delivered.');">
                                                <input type="hidden" name="mark_as_received" value="1">
                                                <input type="hidden" name="po_id" value="<?php echo $po['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-success" title="Mark as Received - Updates inventory when all shipments are delivered">
                                                    <i class="fas fa-check-circle"></i> Mark as Received
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <?php 
                                        // Show Cancel button for POs that can be cancelled (not already cancelled or received)
                                        if (!in_array($po['status'], ['cancelled', 'received']) && canEdit('procurement')): 
                                        ?>
                                            <button class="btn btn-sm btn-warning" onclick="confirmCancelPO(<?php echo $po['id']; ?>, '<?php echo htmlspecialchars($po['po_number'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($po['supplier_name'], ENT_QUOTES); ?>')">
                                                <i class="fas fa-times-circle"></i> Cancel
                                            </button>
                                        <?php endif; ?>
                                        <?php 
                                        // Procurement Staff cannot delete confirmed purchase orders
                                        // Only show delete if no shipments exist and status allows deletion
                                        if (canDelete('procurement', ['status' => $po['status']]) && 
                                            !in_array($po['status'], ['received', 'approved', 'cancelled']) &&
                                            ($po['shipment_count'] ?? 0) == 0): 
                                        ?>
                                            <button class="btn btn-sm btn-danger" onclick="confirmDeletePO(<?php echo $po['id']; ?>, '<?php echo htmlspecialchars($po['po_number'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($po['supplier_name'], ENT_QUOTES); ?>', <?php echo $po['shipment_count']; ?>)">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php 
                                    $delay_status = $po['delay_status'] ?? 'none';
                                    if ($delay_status === 'requested'): 
                                    ?>
                                        <span class="badge badge-warning" title="Supplier has requested a delay">
                                            Delay Requested
                                        </span>
                                    <?php elseif ($delay_status === 'approved'): ?>
                                        <span class="badge badge-info" title="Delay request approved">
                                            Delay Approved
                                        </span>
                                    <?php elseif ($delay_status === 'rejected'): ?>
                                        <span class="badge badge-danger" title="Delay request rejected">
                                            Delay Rejected
                                        </span>
                                    <?php 
                                    // Check if delayed but not requested
                                    elseif ($po['delivery_date'] && !in_array($po['status'], ['received', 'cancelled', 'scheduled'])):
                                        $expected_date = strtotime($po['delivery_date']);
                                        $today = strtotime(date('Y-m-d'));
                                        if ($today > $expected_date):
                                    ?>
                                        <span class="badge badge-danger" title="Past expected date - waiting for supplier delay request">
                                            DELAYED
                                        </span>
                                    <?php 
                                        endif;
                                    endif; 
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Create PO Modal -->
<div id="createPOModal" class="modal">
    <div class="modal-content" style="max-width: 800px;">
        <div class="modal-header">
            <h3>Create Purchase Order</h3>
            <button class="modal-close" onclick="closeModal('createPOModal')">&times;</button>
        </div>
        <form method="POST" action="" id="poForm">
            <div class="form-group">
                <label for="expected_date">Expected Date *</label>
                <input type="date" name="expected_date" id="expected_date" class="form-control" min="<?php echo date('Y-m-d'); ?>" required>
                <small class="text-muted">Expected delivery date for the purchase order</small>
            </div>
            
            <div class="form-group">
                <label>Select Products *</label>
                <div style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 15px; border-radius: 4px;">
                    <?php if (empty($products)): ?>
                        <p class="text-muted">No products available. Please add products to inventory first.</p>
                    <?php else: ?>
                        <table class="data-table" style="width: 100%;">
                            <thead>
                                <tr>
                                    <th style="width: 40px;">Select</th>
                                    <th>Product Name</th>
                                    <th>SKU</th>
                                    <th>Unit Price</th>
                                    <th>Quantity</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($products as $product): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="selected_products[]" value="<?php echo $product['id']; ?>" 
                                                   class="product-checkbox" onchange="toggleProductRow(this, <?php echo $product['id']; ?>)">
                                        </td>
                                        <td><?php echo htmlspecialchars($product['name']); ?></td>
                                        <td><?php echo htmlspecialchars($product['sku']); ?></td>
                                        <td>₱<?php echo number_format($product['unit_price'], 2); ?></td>
                                        <td>
                                            <input type="number" name="quantity_<?php echo $product['id']; ?>" 
                                                   id="quantity_<?php echo $product['id']; ?>" 
                                                   class="form-control" min="1" value="1" 
                                                   style="width: 100px;" disabled>
                                            <input type="hidden" name="unit_price_<?php echo $product['id']; ?>" 
                                                   value="<?php echo $product['unit_price']; ?>">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
                <small class="text-muted">Check the products you want to include in this purchase order</small>
            </div>
            
            <div class="form-group">
                <label for="notes">Notes</label>
                <textarea name="notes" id="notes" class="form-control" rows="3"></textarea>
            </div>
            
            <div class="form-group">
                <button type="submit" name="create_po" class="btn btn-primary btn-block">
                    <i class="fas fa-save"></i> Create Purchase Order
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleProductRow(checkbox, productId) {
    const quantityInput = document.getElementById('quantity_' + productId);
    if (checkbox.checked) {
        quantityInput.disabled = false;
        quantityInput.required = true;
    } else {
        quantityInput.disabled = true;
        quantityInput.required = false;
        quantityInput.value = 1;
    }
}

// Validate form submission
document.getElementById('poForm').addEventListener('submit', function(e) {
    const checkedBoxes = document.querySelectorAll('.product-checkbox:checked');
    if (checkedBoxes.length === 0) {
        e.preventDefault();
        alert('Please select at least one product.');
        return false;
    }
});

function viewPODetails(poId) {
    // Show loading state
    document.getElementById('poDetailsContent').innerHTML = '<p class="text-muted">Loading...</p>';
    openModal('poDetailsModal');
    
    // Fetch PO details
    fetch('?ajax=get_po_details&po_id=' + poId)
        .then(response => response.json())
        .then(data => {
            if (!data || !data.id) {
                document.getElementById('poDetailsContent').innerHTML = '<p class="text-danger">Purchase order not found.</p>';
                return;
            }
            
            // Check if order is received/delivered to show ratings tab
            const showRatingsTab = data.status === 'received';
            
            let html = '<div class="po-details-container">';
            
            // Tabs Navigation
            html += '<div style="border-bottom: 2px solid #ddd; margin-bottom: 1.5rem;">';
            html += '<button class="po-tab-btn active" onclick="switchPOTab(' + poId + ', \'details\')" id="detailsTab_' + poId + '" style="padding: 0.75rem 1.5rem; background: none; border: none; border-bottom: 3px solid #F2ACB9; cursor: pointer; font-weight: 600; color: #F2ACB9; margin-right: 0.5rem;">';
            html += '<i class="fas fa-info-circle"></i> Details';
            html += '</button>';
            
            if (showRatingsTab) {
                html += '<button class="po-tab-btn" onclick="switchPOTab(' + poId + ', \'ratings\')" id="ratingsTab_' + poId + '" style="padding: 0.75rem 1.5rem; background: none; border: none; border-bottom: 3px solid transparent; cursor: pointer; font-weight: 500; color: #64748b; margin-right: 0.5rem;">';
                html += '<i class="fas fa-star"></i> Ratings';
                html += '</button>';
            }
            html += '</div>';
            
            // Details Tab Content
            html += '<div id="detailsTabContent_' + poId + '" class="po-tab-content">';
            html += '<div style="margin-bottom: 20px;">';
            html += '<p><strong>PO Number:</strong> ' + data.po_number + '</p>';
            html += '<p><strong>Supplier:</strong> ' + data.supplier_name + '</p>';
            html += '<p><strong>Order Date:</strong> ' + new Date(data.order_date).toLocaleDateString() + '</p>';
            html += '<p><strong>Expected Date:</strong> ' + (data.delivery_date ? new Date(data.delivery_date).toLocaleDateString() : 'N/A') + '</p>';
            html += '<p><strong>Status:</strong> <span class="badge badge-' + data.status + '">' + data.status.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase()) + '</span></p>';
            html += '<p><strong>Total Amount:</strong> ' + formatCurrency(data.total_amount) + '</p>';
            html += '<p><strong>Created By:</strong> ' + data.created_by_name + '</p>';
            if (data.notes) {
                html += '<p><strong>Notes:</strong> ' + data.notes.replace(/\n/g, '<br>') + '</p>';
            }
            
            // Display delay information
            if (data.delay_status && data.delay_status !== 'none') {
                html += '<div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px; border-left: 4px solid #ffc107;">';
                html += '<h4 style="margin-top: 0;"><i class="fas fa-clock"></i> Delay Information</h4>';
                html += '<p><strong>Delay Status:</strong> ';
                if (data.delay_status === 'requested') {
                    html += '<span class="badge badge-warning">Delay Requested</span>';
                } else if (data.delay_status === 'approved') {
                    html += '<span class="badge badge-info">Delay Approved</span>';
                } else if (data.delay_status === 'rejected') {
                    html += '<span class="badge badge-danger">Delay Rejected</span>';
                }
                html += '</p>';
                
                if (data.delay_requested_date) {
                    html += '<p><strong>Requested New Delivery Date:</strong> ' + new Date(data.delay_requested_date).toLocaleDateString() + '</p>';
                }
                if (data.delay_notes) {
                    html += '<p><strong>Supplier Notes:</strong> ' + data.delay_notes.replace(/\n/g, '<br>') + '</p>';
                }
                if (data.delay_response_notes) {
                    html += '<p><strong>Procurement Response:</strong> ' + data.delay_response_notes.replace(/\n/g, '<br>') + '</p>';
                }
                if (data.delay_responded_by_name) {
                    html += '<p><strong>Responded By:</strong> ' + data.delay_responded_by_name + '</p>';
                }
                html += '</div>';
            }
            html += '</div>';
            
            if (data.items && data.items.length > 0) {
                html += '<div style="margin-top: 20px;"><strong>Items:</strong></div>';
                html += '<div style="overflow-x: auto; margin-top: 10px;">';
                html += '<table class="data-table">';
                html += '<thead><tr><th>Product</th><th>SKU</th><th>Warehouse</th><th>Quantity</th><th>Unit Price</th><th>Subtotal</th></tr></thead>';
                html += '<tbody>';
                data.items.forEach(item => {
                    html += '<tr>';
                    html += '<td>' + item.product_name + '</td>';
                    html += '<td>' + item.sku + '</td>';
                    html += '<td>' + (item.warehouse_name || '<span class="text-muted">N/A</span>') + '</td>';
                    html += '<td>' + item.quantity + '</td>';
                    html += '<td>' + formatCurrency(item.unit_price) + '</td>';
                    html += '<td>' + formatCurrency(item.subtotal) + '</td>';
                    html += '</tr>';
                });
                html += '</tbody></table>';
                html += '</div>';
            } else {
                html += '<p class="text-muted">No items found.</p>';
            }
            
            <?php if (canCreate('logistics')): ?>
            // Add Schedule Shipment button for logistics_manager only when PO is approved
            if (data.status === 'approved') {
                html += '<div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; text-align: center;">';
                html += '<button onclick="openScheduleShipmentModal(' + poId + ')" class="btn btn-primary">';
                html += '<i class="fas fa-shipping-fast"></i> Schedule Shipment';
                html += '</button>';
                html += '</div>';
            }
            <?php endif; ?>
            
            html += '</div>'; // End details tab content
            
            // Ratings Tab Content
            if (showRatingsTab) {
                html += '<div id="ratingsTabContent_' + poId + '" class="po-tab-content" style="display: none;">';
                
                if (data.rating && data.rating.id) {
                    // Show existing rating
                    html += '<div style="padding: 20px; background: #f8f9fa; border-radius: 4px;">';
                    html += '<h4 style="margin-top: 0;"><i class="fas fa-star"></i> Supplier Rating</h4>';
                    html += '<div style="margin-bottom: 15px;">';
                    html += '<strong>Rating:</strong> ';
                    html += '<div class="star-rating-display" style="display: inline-block; margin-left: 10px;">';
                    for (let i = 1; i <= 5; i++) {
                        if (i <= data.rating.rating) {
                            html += '<span class="star filled">★</span>';
                        } else {
                            html += '<span class="star">★</span>';
                        }
                    }
                    html += '<span style="margin-left: 10px; font-weight: bold;">' + parseFloat(data.rating.rating).toFixed(1) + ' / 5.0</span>';
                    html += '</div>';
                    html += '</div>';
                    
                    if (data.rating.comments) {
                        html += '<div style="margin-bottom: 15px;">';
                        html += '<strong>Comments:</strong>';
                        html += '<p style="margin-top: 5px; padding: 10px; background: white; border-radius: 4px;">' + (data.rating.comments.replace(/\n/g, '<br>') || 'No comments provided.') + '</p>';
                        html += '</div>';
                    }
                    
                    html += '<div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd; color: #666; font-size: 0.9em;">';
                    html += '<p><strong>Rated by:</strong> ' + (data.rating.rated_by_name || 'Unknown') + '</p>';
                    html += '<p><strong>Rated on:</strong> ' + new Date(data.rating.created_at).toLocaleString() + '</p>';
                    html += '</div>';
                    html += '</div>';
                } else {
                    // Show rating form
                    <?php if ($_SESSION['role'] === 'procurement_staff' || $_SESSION['role'] === 'admin'): ?>
                    html += '<div style="padding: 20px;">';
                    html += '<h4 style="margin-top: 0;"><i class="fas fa-star"></i> Rate Supplier Delivery</h4>';
                    html += '<form method="POST" action="" id="ratingForm_' + poId + '" onsubmit="return submitRating(' + poId + ');">';
                    html += '<input type="hidden" name="submit_rating" value="1">';
                    html += '<input type="hidden" name="po_id" value="' + poId + '">';
                    html += '<input type="hidden" name="rating" id="ratingValue_' + poId + '" required>';
                    
                    html += '<div style="margin-bottom: 20px;">';
                    html += '<label style="display: block; margin-bottom: 10px; font-weight: bold;">Rating (1-5 stars):</label>';
                    html += '<div class="star-rating-input" id="starRating_' + poId + '">';
                    for (let i = 1; i <= 5; i++) {
                        html += '<span class="star" data-rating="' + i + '" onclick="setRating(' + poId + ', ' + i + ')" onmouseover="hoverRating(' + poId + ', ' + i + ')" onmouseout="resetRating(' + poId + ')">★</span>';
                    }
                    html += '</div>';
                    html += '<span id="ratingText_' + poId + '" style="margin-left: 10px; color: #666;"></span>';
                    html += '</div>';
                    
                    html += '<div style="margin-bottom: 20px;">';
                    html += '<label for="comments_' + poId + '" style="display: block; margin-bottom: 10px; font-weight: bold;">Comments:</label>';
                    html += '<textarea name="comments" id="comments_' + poId + '" class="form-control" rows="4" placeholder="Share your feedback about the supplier delivery..."></textarea>';
                    html += '</div>';
                    
                    html += '<div style="text-align: right;">';
                    html += '<button type="submit" class="btn btn-primary">';
                    html += '<i class="fas fa-check"></i> Submit Rating';
                    html += '</button>';
                    html += '</div>';
                    html += '</form>';
                    html += '</div>';
                    <?php else: ?>
                    html += '<p class="text-muted">No rating has been submitted yet for this purchase order.</p>';
                    <?php endif; ?>
                }
                
                html += '</div>'; // End ratings tab content
            }
            
            html += '</div>'; // End po-details-container
            
            document.getElementById('poDetailsContent').innerHTML = html;
        })
        .catch(error => {
            console.error('Error loading PO details:', error);
            document.getElementById('poDetailsContent').innerHTML = '<p class="text-danger">Error loading purchase order details.</p>';
        });
}

function formatCurrency(amount) {
    return '₱' + parseFloat(amount).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
}

function openScheduleShipmentModal(poId) {
    // Close PO details modal
    closeModal('poDetailsModal');
    
    // Set PO ID in form
    document.getElementById('scheduleShipmentPOId').value = poId;
    
    // Fetch PO details to populate form
    fetch('?ajax=get_po_details&po_id=' + poId)
        .then(response => response.json())
        .then(data => {
            if (!data || !data.id) {
                alert('Error loading purchase order details.');
                return;
            }
            
            // Populate read-only fields
            document.getElementById('scheduleShipmentPONumber').textContent = data.po_number;
            document.getElementById('scheduleShipmentSupplier').textContent = data.supplier_name;
            
            // Populate items table
            let itemsHtml = '';
            if (data.items && data.items.length > 0) {
                data.items.forEach(item => {
                    itemsHtml += '<tr>';
                    itemsHtml += '<td>' + item.product_name + '</td>';
                    itemsHtml += '<td>' + item.sku + '</td>';
                    itemsHtml += '<td>' + item.quantity + '</td>';
                    itemsHtml += '<td>' + formatCurrency(item.unit_price) + '</td>';
                    itemsHtml += '<td>' + formatCurrency(item.subtotal) + '</td>';
                    itemsHtml += '</tr>';
                });
            }
            document.getElementById('scheduleShipmentItemsBody').innerHTML = itemsHtml;
            
            // Set default scheduled date (use PO expected date if available, but not in the past)
            const dateInput = document.getElementById('scheduleShipmentDate');
            const today = new Date().toISOString().split('T')[0];
            dateInput.min = today; // Ensure min is set to today
            
            if (data.delivery_date) {
                const expectedDate = new Date(data.delivery_date);
                const formattedDate = expectedDate.toISOString().split('T')[0];
                // Only set if the date is not in the past
                if (formattedDate >= today) {
                    dateInput.value = formattedDate;
                } else {
                    dateInput.value = today; // Use today if PO date is in the past
                }
            } else {
                dateInput.value = today; // Default to today if no PO expected date
            }
            
            // Auto-populate destination warehouse if available (from automatic low stock orders)
            const destinationWarehouseSelect = document.getElementById('destination_warehouse_id');
            if (data.destination_warehouse_id && destinationWarehouseSelect) {
                // Set the value and trigger change event to ensure it's properly selected
                destinationWarehouseSelect.value = data.destination_warehouse_id;
                // Verify the value was set (in case the option doesn't exist)
                if (destinationWarehouseSelect.value != data.destination_warehouse_id) {
                    console.warn('Destination warehouse ID ' + data.destination_warehouse_id + ' not found in select options');
                }
            }
            
            // Open modal
            openModal('scheduleShipmentModal');
        })
        .catch(error => {
            console.error('Error loading PO details:', error);
            alert('Error loading purchase order details.');
        });
}

function confirmUpdatePOStatus(poId, status, poNumber, supplierName, totalAmount) {
    // Set the PO details in the modal
    document.getElementById('updatePOId').value = poId;
    document.getElementById('updatePOStatus').value = status;
    document.getElementById('updatePOPONumber').textContent = poNumber;
    document.getElementById('updatePOSupplier').textContent = supplierName;
    document.getElementById('updatePOTotalAmount').textContent = formatCurrency(totalAmount);
    
    // Set the status badge
    const statusBadge = document.getElementById('updatePOStatusBadge');
    const statusText = status === 'approved' ? 'Approved' : status.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase());
    statusBadge.textContent = statusText;
    statusBadge.className = 'badge badge-' + (status === 'approved' ? 'success' : 'warning');
    
    // Set the confirmation message
    const messageEl = document.getElementById('updatePOMessage');
    const warningEl = document.getElementById('updatePOWarning');
    const warningTextEl = document.getElementById('updatePOWarningText');
    const submitBtn = document.getElementById('updatePOSubmitBtn');
    
    if (status === 'approved') {
        messageEl.textContent = 'Are you sure you want to approve this purchase order?';
        messageEl.style.color = '#28a745';
        warningEl.style.display = 'block';
        warningTextEl.textContent = 'This will mark the purchase order as approved and allow it to proceed to the next stage.';
        submitBtn.className = 'btn btn-success';
        submitBtn.innerHTML = '<i class="fas fa-check"></i> Approve Purchase Order';
    } else {
        messageEl.textContent = 'Are you sure you want to update the purchase order status?';
        messageEl.style.color = '#333';
        warningEl.style.display = 'block';
        warningTextEl.textContent = 'This will change the status of the purchase order.';
        submitBtn.className = 'btn btn-primary';
        submitBtn.innerHTML = '<i class="fas fa-check"></i> Update Status';
    }
    
    // Show the confirmation modal
    openModal('updatePOStatusModal');
}

function confirmCancelPO(poId, poNumber, supplierName) {
    // Set the PO details in the modal
    document.getElementById('cancelPOId').value = poId;
    document.getElementById('cancelPOPONumber').textContent = poNumber;
    document.getElementById('cancelPOSupplier').textContent = supplierName;
    
    // Show the confirmation modal
    openModal('cancelPOModal');
}

function confirmDeletePO(poId, poNumber, supplierName, shipmentCount) {
    // Set the PO details in the modal
    document.getElementById('deletePOId').value = poId;
    document.getElementById('deletePOPONumber').textContent = poNumber;
    document.getElementById('deletePOSupplier').textContent = supplierName;
    
    // Show warning if there are shipments
    const warningEl = document.getElementById('deletePOWarning');
    if (shipmentCount > 0) {
        warningEl.style.display = 'block';
        warningEl.innerHTML = `<i class="fas fa-exclamation-triangle"></i> This purchase order has ${shipmentCount} related shipment(s). It cannot be deleted. Please cancel it instead to preserve historical data.`;
        document.getElementById('deletePOForm').style.display = 'none';
    } else {
        warningEl.style.display = 'none';
        document.getElementById('deletePOForm').style.display = 'block';
    }
    
    // Show the confirmation modal
    openModal('deletePOModal');
}

function updatePOStatus(poId, status) {
    // This function is kept for backward compatibility but now uses the modal
    confirmUpdatePOStatus(poId, status, '', '', 0);
}

function cancelPO(poId, poNumber) {
    // This function is kept for backward compatibility but now uses the modal
    confirmCancelPO(poId, poNumber, '');
}

function deletePO(poId) {
    // This function is kept for backward compatibility but now uses the modal
    confirmDeletePO(poId, '', '', 0);
}

function openDelayResponseModal(poId, poNumber, supplierName, requestedDate, delayNotes) {
    document.getElementById('delayResponsePOId').value = poId;
    document.getElementById('delayResponsePONumber').textContent = poNumber;
    document.getElementById('delayResponseSupplier').textContent = supplierName;
    document.getElementById('delayResponseRequestedDate').textContent = requestedDate ? new Date(requestedDate).toLocaleDateString() : 'N/A';
    document.getElementById('delayResponseNotes').textContent = delayNotes || 'No notes provided.';
    document.getElementById('delayResponseNotesInput').value = '';
    openModal('delayResponseModal');
}

function openDelayResponseModalFromButton(button) {
    const poId = button.getAttribute('data-po-id');
    const poNumber = button.getAttribute('data-po-number');
    const supplierName = button.getAttribute('data-supplier-name');
    const requestedDate = button.getAttribute('data-requested-date');
    const delayNotes = button.getAttribute('data-delay-notes');
    openDelayResponseModal(poId, poNumber, supplierName, requestedDate || null, delayNotes || '');
}

function submitDelayResponse(action) {
    document.getElementById('delayResponseAction').value = action;
    document.getElementById('delayResponseForm').submit();
}

// Switch between PO detail tabs
window.switchPOTab = function(poId, tab) {
    // Get all tab buttons and contents
    const detailsTab = document.getElementById('detailsTab_' + poId);
    const ratingsTab = document.getElementById('ratingsTab_' + poId);
    const detailsContent = document.getElementById('detailsTabContent_' + poId);
    const ratingsContent = document.getElementById('ratingsTabContent_' + poId);
    
    // Reset all tabs
    [detailsTab, ratingsTab].forEach(btn => {
        if (btn) {
            btn.classList.remove('active');
            btn.style.borderBottom = '3px solid transparent';
            btn.style.fontWeight = '500';
            btn.style.color = '#64748b';
        }
    });
    
    // Hide all tab contents
    [detailsContent, ratingsContent].forEach(content => {
        if (content) content.style.display = 'none';
    });
    
    // Activate selected tab
    if (tab === 'details' && detailsTab && detailsContent) {
        detailsTab.classList.add('active');
        detailsTab.style.borderBottom = '3px solid #F2ACB9';
        detailsTab.style.fontWeight = '600';
        detailsTab.style.color = '#F2ACB9';
        detailsContent.style.display = 'block';
    } else if (tab === 'ratings' && ratingsTab && ratingsContent) {
        ratingsTab.classList.add('active');
        ratingsTab.style.borderBottom = '3px solid #F2ACB9';
        ratingsTab.style.fontWeight = '600';
        ratingsTab.style.color = '#F2ACB9';
        ratingsContent.style.display = 'block';
    }
}

// Star rating functionality
let currentRating = {};
let hoveredRating = {};

function setRating(poId, rating) {
    currentRating[poId] = rating;
    document.getElementById('ratingValue_' + poId).value = rating;
    updateStarDisplay(poId);
    
    // Update rating text
    const ratingText = document.getElementById('ratingText_' + poId);
    if (ratingText) {
        const texts = {
            1: 'Poor',
            2: 'Fair',
            3: 'Good',
            4: 'Very Good',
            5: 'Excellent'
        };
        ratingText.textContent = texts[rating] || '';
    }
}

function hoverRating(poId, rating) {
    hoveredRating[poId] = rating;
    updateStarDisplay(poId);
}

function resetRating(poId) {
    hoveredRating[poId] = null;
    updateStarDisplay(poId);
}

function updateStarDisplay(poId) {
    const stars = document.querySelectorAll('#starRating_' + poId + ' .star');
    const rating = hoveredRating[poId] || currentRating[poId] || 0;
    
    stars.forEach((star, index) => {
        const starRating = index + 1;
        if (starRating <= rating) {
            star.classList.add('filled');
            star.style.color = '#ffc107';
        } else {
            star.classList.remove('filled');
            star.style.color = '#ddd';
        }
    });
}

function submitRating(poId) {
    const ratingValue = document.getElementById('ratingValue_' + poId);
    if (!ratingValue || !ratingValue.value || ratingValue.value < 1 || ratingValue.value > 5) {
        alert('Please select a rating between 1 and 5 stars.');
        return false;
    }
    return true;
}
</script>

<style>
/* Star Rating Styles */
.star-rating-input {
    display: inline-block;
    font-size: 2em;
    line-height: 1;
}

.star-rating-input .star {
    cursor: pointer;
    color: #ddd;
    transition: color 0.2s;
    user-select: none;
    margin-right: 5px;
}

.star-rating-input .star:hover,
.star-rating-input .star.filled {
    color: #ffc107;
}

.star-rating-display {
    display: inline-block;
    font-size: 1.5em;
    line-height: 1;
}

.star-rating-display .star {
    color: #ddd;
    margin-right: 3px;
}

.star-rating-display .star.filled {
    color: #ffc107;
}

/* PO Tab Styles */
.po-tab-content {
    display: block;
}

.po-tab-btn {
    transition: all 0.3s ease;
}

.po-tab-btn:hover {
    color: #F2ACB9 !important;
}
</style>

<!-- PO Details Modal -->
<div id="poDetailsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Purchase Order Details</h3>
            <button class="modal-close" onclick="closeModal('poDetailsModal')">&times;</button>
        </div>
        <div class="card-body" id="poDetailsContent">
            <p class="text-muted">Loading...</p>
        </div>
    </div>
</div>

<?php if (canCreate('logistics')): ?>
<!-- Schedule Shipment Modal -->
<div id="scheduleShipmentModal" class="modal">
    <div class="modal-content" style="max-width: 800px;">
        <div class="modal-header">
            <h3><i class="fas fa-shipping-fast"></i> Schedule Shipment</h3>
            <button class="modal-close" onclick="closeModal('scheduleShipmentModal')">&times;</button>
        </div>
        <form method="POST" action="" id="scheduleShipmentForm">
            <input type="hidden" name="po_id" id="scheduleShipmentPOId">
            <input type="hidden" name="create_shipment_from_po" value="1">
            
            <div class="card-body">
                <h4 style="margin-bottom: 20px;">Purchase Order Details (Read-Only)</h4>
                <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px;">
                    <div style="margin-bottom: 10px;">
                        <strong>PO Number:</strong> <span id="scheduleShipmentPONumber"></span>
                    </div>
                    <div style="margin-bottom: 10px;">
                        <strong>Supplier:</strong> <span id="scheduleShipmentSupplier"></span>
                    </div>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <strong>Items:</strong>
                    <div style="overflow-x: auto; margin-top: 10px;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>SKU</th>
                                    <th>Quantity</th>
                                    <th>Unit Price</th>
                                    <th>Subtotal</th>
                                </tr>
                            </thead>
                            <tbody id="scheduleShipmentItemsBody">
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <h4 style="margin-bottom: 20px; margin-top: 30px;">Shipment Details</h4>
                
                <div class="form-group">
                    <label for="destination_warehouse_id">Destination Warehouse *</label>
                    <select name="destination_warehouse_id" id="destination_warehouse_id" class="form-control" required>
                        <option value="">Select Warehouse</option>
                        <?php foreach ($warehouses as $warehouse): ?>
                            <option value="<?php echo $warehouse['id']; ?>">
                                <?php echo htmlspecialchars($warehouse['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="scheduleShipmentDate">Scheduled Date (Expected Date) *</label>
                    <input type="date" name="scheduled_date" id="scheduleShipmentDate" class="form-control" min="<?php echo date('Y-m-d'); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="transport_cost">Transport Cost</label>
                    <input type="number" name="transport_cost" id="transport_cost" class="form-control" step="0.01" min="0" value="0">
                </div>
                
                <h4 style="margin-bottom: 20px; margin-top: 30px;">Assign Driver and Vehicle</h4>
                
                <div class="form-group">
                    <label for="schedule_vehicle_id">Vehicle</label>
                    <select name="vehicle_id" id="schedule_vehicle_id" class="form-control">
                        <option value="">-- Select Vehicle --</option>
                        <?php foreach ($vehicles as $vehicle): ?>
                            <option value="<?php echo $vehicle['id']; ?>">
                                <?php echo htmlspecialchars($vehicle['vehicle_number']); ?>
                                <?php if (!empty($vehicle['vehicle_type'])): ?>
                                    (<?php echo ucfirst($vehicle['vehicle_type']); ?>)
                                <?php endif; ?>
                                <?php if ($vehicle['status'] != 'available'): ?>
                                    - <?php echo ucfirst(str_replace('_', ' ', $vehicle['status'])); ?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Select a vehicle to assign to this shipment</small>
                </div>
                
                <div class="form-group">
                    <label for="schedule_driver_id">Driver</label>
                    <select name="driver_id" id="schedule_driver_id" class="form-control">
                        <option value="">-- Select Driver --</option>
                        <?php foreach ($drivers as $driver): ?>
                            <option value="<?php echo $driver['id']; ?>">
                                <?php echo htmlspecialchars($driver['full_name']); ?>
                                (<?php echo htmlspecialchars($driver['driver_code']); ?>)
                                <?php if ($driver['status'] != 'available'): ?>
                                    - <?php echo ucfirst(str_replace('_', ' ', $driver['status'])); ?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Select a driver to assign to this shipment</small>
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 30px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('scheduleShipmentModal')">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Create Shipment
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Update PO Status Confirmation Modal -->
<div id="updatePOStatusModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3><i class="fas fa-exclamation-triangle" style="color: #ffc107;"></i> Confirm Status Update</h3>
            <button class="modal-close" onclick="closeModal('updatePOStatusModal')">&times;</button>
        </div>
        <div class="card-body">
            <p id="updatePOMessage" style="margin-bottom: 20px; font-size: 16px;"></p>
            <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px;">
                <div style="margin-bottom: 10px;">
                    <strong>PO Number:</strong> <span id="updatePOPONumber"></span>
                </div>
                <div style="margin-bottom: 10px;">
                    <strong>Supplier:</strong> <span id="updatePOSupplier"></span>
                </div>
                <div style="margin-bottom: 10px;">
                    <strong>Total Amount:</strong> <span id="updatePOTotalAmount"></span>
                </div>
                <div>
                    <strong>New Status:</strong> <span id="updatePOStatusBadge" class="badge"></span>
                </div>
            </div>
            <div id="updatePOWarning" style="padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px; margin-bottom: 20px; display: none;">
                <i class="fas fa-info-circle"></i> <span id="updatePOWarningText"></span>
            </div>
            <form method="POST" action="" id="updatePOStatusForm">
                <input type="hidden" name="po_id" id="updatePOId">
                <input type="hidden" name="status" id="updatePOStatus">
                <input type="hidden" name="update_status" value="1">
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('updatePOStatusModal')">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn" id="updatePOSubmitBtn">
                        <i class="fas fa-check"></i> Confirm
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Cancel PO Confirmation Modal -->
<div id="cancelPOModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3><i class="fas fa-exclamation-triangle" style="color: #ffc107;"></i> Confirm Cancellation</h3>
            <button class="modal-close" onclick="closeModal('cancelPOModal')">&times;</button>
        </div>
        <div class="card-body">
            <p style="margin-bottom: 20px; font-size: 16px; color: #856404;">Are you sure you want to cancel this purchase order?</p>
            <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px;">
                <div style="margin-bottom: 10px;">
                    <strong>PO Number:</strong> <span id="cancelPOPONumber"></span>
                </div>
                <div>
                    <strong>Supplier:</strong> <span id="cancelPOSupplier"></span>
                </div>
            </div>
            <div style="padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px; margin-bottom: 20px;">
                <i class="fas fa-info-circle"></i> This will mark the purchase order as cancelled and preserve historical data. The order cannot be reactivated after cancellation.
            </div>
            <form method="POST" action="" id="cancelPOForm">
                <input type="hidden" name="po_id" id="cancelPOId">
                <input type="hidden" name="status" value="cancelled">
                <input type="hidden" name="update_status" value="1">
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('cancelPOModal')">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-times-circle"></i> Cancel Purchase Order
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete PO Confirmation Modal -->
<div id="deletePOModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3><i class="fas fa-exclamation-triangle" style="color: #dc3545;"></i> Confirm Deletion</h3>
            <button class="modal-close" onclick="closeModal('deletePOModal')">&times;</button>
        </div>
        <div class="card-body">
            <p style="margin-bottom: 20px; font-size: 16px;">Are you sure you want to delete this purchase order?</p>
            <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px;">
                <div style="margin-bottom: 10px;">
                    <strong>PO Number:</strong> <span id="deletePOPONumber"></span>
                </div>
                <div>
                    <strong>Supplier:</strong> <span id="deletePOSupplier"></span>
                </div>
            </div>
            <div id="deletePOWarning" style="padding: 10px; background: #f8d7da; border-left: 4px solid #dc3545; border-radius: 4px; margin-bottom: 20px; display: none; color: #721c24;">
            </div>
            <p style="color: #dc3545; margin-bottom: 20px;">
                <i class="fas fa-exclamation-circle"></i> This action cannot be undone. Purchase orders with related shipments cannot be deleted and must be cancelled instead.
            </p>
            <form method="POST" action="" id="deletePOForm">
                <input type="hidden" name="po_id" id="deletePOId">
                <input type="hidden" name="delete_po" value="1">
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('deletePOModal')">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Delete Purchase Order
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delay Response Modal -->
<div id="delayResponseModal" class="modal">
    <div class="modal-content" style="max-width: 700px;">
        <div class="modal-header">
            <h3><i class="fas fa-clock" style="color: #ffc107;"></i> Respond to Delay Request</h3>
            <button class="modal-close" onclick="closeModal('delayResponseModal')">&times;</button>
        </div>
        <div class="card-body">
            <p style="margin-bottom: 20px; font-size: 16px;">Supplier has requested a delay for this purchase order.</p>
            <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px;">
                <div style="margin-bottom: 10px;">
                    <strong>PO Number:</strong> <span id="delayResponsePONumber"></span>
                </div>
                <div style="margin-bottom: 10px;">
                    <strong>Supplier:</strong> <span id="delayResponseSupplier"></span>
                </div>
                <div style="margin-bottom: 10px;">
                    <strong>Requested New Delivery Date:</strong> <span id="delayResponseRequestedDate"></span>
                </div>
                <div>
                    <strong>Supplier Notes:</strong>
                    <div style="margin-top: 5px; padding: 10px; background: white; border: 1px solid #ddd; border-radius: 4px;">
                        <span id="delayResponseNotes"></span>
                    </div>
                </div>
            </div>
            <form method="POST" action="" id="delayResponseForm">
                <input type="hidden" name="po_id" id="delayResponsePOId">
                <input type="hidden" name="respond_delay_request" value="1">
                <input type="hidden" name="delay_action" id="delayResponseAction">
                
                <div class="form-group">
                    <label for="delayResponseNotesInput">Your Response Notes</label>
                    <textarea name="response_notes" id="delayResponseNotesInput" class="form-control" rows="4" placeholder="Add any notes or comments for the supplier..."></textarea>
                </div>
                
                <div style="padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px; margin-bottom: 20px;">
                    <i class="fas fa-info-circle"></i> <strong>Action Required:</strong> Choose to approve or reject the delay request. If approved, the delivery date will be updated to the requested date.
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('delayResponseModal')">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="button" class="btn btn-danger" onclick="submitDelayResponse('reject')">
                        <i class="fas fa-times-circle"></i> Reject Delay
                    </button>
                    <button type="button" class="btn btn-success" onclick="submitDelayResponse('approve')">
                        <i class="fas fa-check"></i> Approve Delay
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

