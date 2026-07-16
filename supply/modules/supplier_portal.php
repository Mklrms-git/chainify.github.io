<?php
require_once '../config/config.php';
requireModuleAccess('supplier_portal');

$conn = getDBConnection();
$message = '';
$error = '';

// Get messages from session first
if (isset($_SESSION['supplier_portal_message'])) {
    $message = $_SESSION['supplier_portal_message'];
    unset($_SESSION['supplier_portal_message']);
}

if (isset($_SESSION['supplier_portal_error'])) {
    $error = $_SESSION['supplier_portal_error'];
    unset($_SESSION['supplier_portal_error']);
}

// Also check for GET parameters
if (isset($_GET['success']) && empty($message)) {
    $message = 'Operation completed successfully!';
}
if (isset($_GET['error']) && empty($error)) {
    $error = 'An error occurred. Please try again.';
}

// Get supplier ID - check if user is supplier and linked to supplier record
$supplier_id = null;
if (isLoggedIn() && isSupplier()) {
    $stmt = $conn->prepare("SELECT id FROM suppliers WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $supplier = $result->fetch_assoc();
    $stmt->close();
    
    if ($supplier) {
        $supplier_id = $supplier['id'];
    }
}

if (!$supplier_id) {
    // Try to auto-link if there's only one supplier without a user account
    $auto_link_stmt = $conn->prepare("SELECT id FROM suppliers WHERE user_id IS NULL AND status = 'active' LIMIT 1");
    $auto_link_stmt->execute();
    $auto_link_result = $auto_link_stmt->get_result();
    $auto_link_supplier = $auto_link_result->fetch_assoc();
    $auto_link_stmt->close();
    
    if ($auto_link_supplier && isLoggedIn() && isSupplier()) {
        // Auto-link to the first available supplier
        $link_stmt = $conn->prepare("UPDATE suppliers SET user_id = ? WHERE id = ?");
        $link_stmt->bind_param("ii", $_SESSION['user_id'], $auto_link_supplier['id']);
        if ($link_stmt->execute()) {
            $supplier_id = $auto_link_supplier['id'];
            $_SESSION['supplier_portal_message'] = 'Your account has been automatically linked to a supplier record.';
        }
        $link_stmt->close();
    }
    
    if (!$supplier_id) {
        $_SESSION['supplier_portal_error'] = 'Supplier account not linked to a supplier record. Please contact administrator to link your account through the Users module.';
        closeDBConnection($conn);
        header('Location: ' . BASE_URL . 'dashboard.php');
        exit();
    }
}

// Handle delay request from supplier
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['request_delay'])) {
    $po_id = $_POST['po_id'] ?? 0;
    $new_delivery_date = $_POST['new_delivery_date'] ?? '';
    $delay_notes = $_POST['delay_notes'] ?? '';
    
    if ($po_id && $new_delivery_date) {
        // Verify this PO belongs to this supplier
        $check_stmt = $conn->prepare("SELECT id, po_number, supplier_id, status, delay_status, delivery_date FROM purchase_orders WHERE id = ? AND supplier_id = ?");
        $check_stmt->bind_param("ii", $po_id, $supplier_id);
        $check_stmt->execute();
        $po_result = $check_stmt->get_result();
        $po_data = $po_result->fetch_assoc();
        $check_stmt->close();
        
        if ($po_data && !in_array($po_data['status'], ['received', 'cancelled']) && ($po_data['delay_status'] ?? 'none') === 'none') {
            // Validate new date is not before original date
            if ($po_data['delivery_date'] && $new_delivery_date < $po_data['delivery_date']) {
                $_SESSION['supplier_portal_error'] = 'New delivery date cannot be earlier than the original expected date.';
                closeDBConnection($conn);
                header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
                exit();
            }
            
            // Update PO with delay request
            $update_stmt = $conn->prepare("UPDATE purchase_orders SET delay_status = 'requested', delay_requested_date = ?, delay_notes = ?, delay_requested_at = NOW() WHERE id = ?");
            $update_stmt->bind_param("ssi", $new_delivery_date, $delay_notes, $po_id);
            
            if ($update_stmt->execute()) {
                $po_number = $po_data['po_number'];
                
                // Notify procurement staff
                notifyUsersByRole(
                    'procurement_staff',
                    'delay_requested',
                    'Delay Request Received',
                    "Supplier has requested a delay for Purchase Order {$po_number}. New requested delivery date: " . date('M d, Y', strtotime($new_delivery_date)) . ". Please review and respond.",
                    'purchase_order',
                    $po_id
                );
                
                // Also notify admin
                notifyUsersByRole(
                    'admin',
                    'delay_requested',
                    'Delay Request Received',
                    "Supplier has requested a delay for Purchase Order {$po_number}. New requested delivery date: " . date('M d, Y', strtotime($new_delivery_date)) . ". Please review and respond.",
                    'purchase_order',
                    $po_id
                );
                
                $_SESSION['supplier_portal_message'] = "Delay request submitted successfully! Procurement will review your request and respond shortly.";
                $update_stmt->close();
                closeDBConnection($conn);
                header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1');
                exit();
            } else {
                $error = 'Failed to submit delay request.';
                $update_stmt->close();
            }
        } else {
            $error = 'Purchase order not found, already processed, or delay request already submitted.';
        }
    } else {
        $error = 'Please provide new delivery date.';
    }
}

// Handle supplier approval of PO
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['supplier_approve_po'])) {
    $po_id = $_POST['po_id'] ?? 0;
    
    if ($po_id) {
        // Verify this PO belongs to this supplier
        $check_stmt = $conn->prepare("SELECT id, po_number, supplier_id, status, admin_approved, supplier_approved FROM purchase_orders WHERE id = ? AND supplier_id = ?");
        $check_stmt->bind_param("ii", $po_id, $supplier_id);
        $check_stmt->execute();
        $po_result = $check_stmt->get_result();
        $po_data = $po_result->fetch_assoc();
        $check_stmt->close();
        
        // Supplier can approve if PO is pending or approved (admin already approved) and not already supplier approved
        if ($po_data && !$po_data['supplier_approved'] && ($po_data['status'] === 'pending' || $po_data['status'] === 'approved')) {
            // Mark supplier approval
            $update_stmt = $conn->prepare("UPDATE purchase_orders SET supplier_approved = TRUE, supplier_approved_by = ?, supplier_approved_at = NOW() WHERE id = ?");
            $update_stmt->bind_param("ii", $_SESSION['user_id'], $po_id);
            
            if ($update_stmt->execute()) {
                // Mark notification as read
                $notif_stmt = $conn->prepare("UPDATE po_notifications SET is_read = TRUE WHERE po_id = ? AND supplier_id = ?");
                $notif_stmt->bind_param("ii", $po_id, $supplier_id);
                $notif_stmt->execute();
                $notif_stmt->close();
                
                // Get PO details for notifications
                $po_notif_stmt = $conn->prepare("SELECT po_number, supplier_id, created_by, admin_approved, supplier_approved FROM purchase_orders WHERE id = ?");
                $po_notif_stmt->bind_param("i", $po_id);
                $po_notif_stmt->execute();
                $po_notif_result = $po_notif_stmt->get_result();
                $po_notif_data = $po_notif_result->fetch_assoc();
                $po_notif_stmt->close();
                
                if ($po_notif_data) {
                    $po_number = $po_notif_data['po_number'];
                    
                    // Check if both admin and supplier have approved
                    if ($po_notif_data['admin_approved'] && $po_notif_data['supplier_approved']) {
                        // Both approved - notify procurement and supplier
                        
                        // Notify Admin users that both parties have approved
                        notifyUsersByRole(
                            'admin',
                            'po_fully_approved',
                            'Purchase Order Fully Approved',
                            "Purchase Order {$po_number} has been fully approved by both admin and supplier. The supplier can now schedule the shipment.",
                            'purchase_order',
                            $po_id
                        );
                        
                        // Notify Procurement staff who created the PO
                        if ($po_notif_data['created_by']) {
                            $created_by = $po_notif_data['created_by'];
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
                                    "Purchase Order {$po_number} has been fully approved by both admin and supplier. The supplier can now schedule the shipment.",
                                    'purchase_order',
                                    $po_id
                                );
                            }
                        }
                        
                        // Notify all procurement staff (not just the creator)
                        notifyUsersByRole(
                            'procurement',
                            'po_supplier_approved',
                            'Purchase Order Approved by Supplier',
                            "Purchase Order {$po_number} has been approved by the supplier. The supplier can now schedule the shipment to logistics.",
                            'purchase_order',
                            $po_id
                        );
                        
                        // Notify supplier that both parties have approved
                        notifySupplier(
                            $po_notif_data['supplier_id'],
                            'po_fully_approved',
                            'Purchase Order Fully Approved',
                            "Purchase Order {$po_number} has been fully approved. You can now schedule the shipment.",
                            'purchase_order',
                            $po_id
                        );
                        
                        $_SESSION['supplier_portal_message'] = "Purchase Order {$po_data['po_number']} approved! Both admin and supplier have approved. You can now schedule the shipment to logistics.";
                    } else {
                        // Only supplier approved - notify admin
                        notifyUsersByRole(
                            'admin',
                            'po_supplier_approved',
                            'Supplier Approved Purchase Order',
                            "Purchase Order {$po_number} has been approved by the supplier. Waiting for admin approval.",
                            'purchase_order',
                            $po_id
                        );
                        
                        $_SESSION['supplier_portal_message'] = "Purchase Order {$po_data['po_number']} approved! Waiting for admin approval.";
                    }
                }
                
                $update_stmt->close();
                closeDBConnection($conn);
                header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1');
                exit();
            } else {
                $error = 'Failed to approve purchase order.';
            }
            $update_stmt->close();
        } else {
            $error = 'Purchase order not found, already processed, or cannot be approved.';
        }
    }
}

// Handle shipment scheduling by supplier
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['schedule_shipment'])) {
    $po_id = $_POST['po_id'] ?? 0;
    $scheduled_date = $_POST['scheduled_date'] ?? '';
    $transport_cost = $_POST['transport_cost'] ?? 0;
    $destination_warehouse_id = $_POST['destination_warehouse_id'] ?? 0;
    $vehicle_id = $_POST['vehicle_id'] ?? null;
    $driver_id = $_POST['driver_id'] ?? null;
    
    // Validate scheduled date is not in the past
    if ($scheduled_date && $scheduled_date < date('Y-m-d')) {
        $error = 'Scheduled date cannot be in the past. Please select today\'s date or a future date.';
    } else {
        // Verify PO belongs to this supplier and is approved
        $check_stmt = $conn->prepare("SELECT id, po_number, supplier_id, status FROM purchase_orders WHERE id = ? AND supplier_id = ?");
        $check_stmt->bind_param("ii", $po_id, $supplier_id);
        $check_stmt->execute();
        $po_result = $check_stmt->get_result();
        $po_data = $po_result->fetch_assoc();
        $check_stmt->close();
        
        if ($po_data && $po_data['status'] === 'approved') {
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
            
            if (!empty($items) && $scheduled_date && $destination_warehouse_id) {
                // Create shipment
                $shipment_number = 'SHIP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
                $tracking_number = 'TRK-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
                $type = 'inbound';
                
                // Convert empty strings to null
                $vehicle_id = ($vehicle_id === '' || $vehicle_id === 0) ? null : (int)$vehicle_id;
                $driver_id = ($driver_id === '' || $driver_id === 0) ? null : (int)$driver_id;
                
                // Explicitly set status to 'scheduled' so it appears in Logistics module
                $shipment_status = 'scheduled';
                $stmt = $conn->prepare("INSERT INTO shipments (shipment_number, po_id, type, destination_warehouse_id, supplier_id, vehicle_id, driver_id, status, scheduled_date, tracking_number, transport_cost, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sisiiiisssdi", $shipment_number, $po_id, $type, $destination_warehouse_id, $supplier_id, $vehicle_id, $driver_id, $shipment_status, $scheduled_date, $tracking_number, $transport_cost, $_SESSION['user_id']);
                
                if ($stmt->execute()) {
                    $shipment_id = $stmt->insert_id;
                    
                    // Insert shipment items
                    $item_stmt = $conn->prepare("INSERT INTO shipment_items (shipment_id, product_id, quantity) VALUES (?, ?, ?)");
                    foreach ($items as $item) {
                        $item_stmt->bind_param("iii", $shipment_id, $item['product_id'], $item['quantity']);
                        $item_stmt->execute();
                    }
                    $item_stmt->close();
                    
                    // Update PO status to 'scheduled' when supplier schedules shipment
                    $po_update_stmt = $conn->prepare("UPDATE purchase_orders SET status = 'scheduled' WHERE id = ?");
                    $po_update_stmt->bind_param("i", $po_id);
                    $po_update_stmt->execute();
                    $po_update_stmt->close();
                    
                    // Create notification for logistics_manager
                    notifyUsersByRole(
                        'logistics_manager',
                        'shipment_scheduled',
                        'Shipment Scheduled',
                        "Shipment {$shipment_number} has been scheduled by supplier for Purchase Order {$po_data['po_number']}. Scheduled delivery date: " . date('M d, Y', strtotime($scheduled_date)),
                        'shipment',
                        $shipment_id
                    );
                    
                    // Also update old po_notifications for backward compatibility
                    $notif_message = "Shipment {$shipment_number} has been scheduled by supplier for PO {$po_data['po_number']}.";
                    $notif_stmt = $conn->prepare("INSERT INTO po_notifications (po_id, supplier_id, notification_type, message) VALUES (?, ?, 'shipment_scheduled', ?)");
                    $notif_stmt->bind_param("iis", $po_id, $supplier_id, $notif_message);
                    $notif_stmt->execute();
                    $notif_stmt->close();
                    
                    // Mark PO notification as read
                    $read_stmt = $conn->prepare("UPDATE po_notifications SET is_read = TRUE WHERE po_id = ? AND supplier_id = ?");
                    $read_stmt->bind_param("ii", $po_id, $supplier_id);
                    $read_stmt->execute();
                    $read_stmt->close();
                    
                    $_SESSION['supplier_portal_message'] = "Shipment {$shipment_number} scheduled successfully!";
                    $stmt->close();
                    closeDBConnection($conn);
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1');
                    exit();
                } else {
                    $error = 'Failed to create shipment: ' . $stmt->error;
                    $stmt->close();
                }
            } else {
                $error = 'Please fill all required fields.';
            }
        } else {
            $error = 'Purchase order not found or not ready for shipment scheduling.';
        }
    }
}

// Get supplier info
$supplier_stmt = $conn->prepare("SELECT * FROM suppliers WHERE id = ?");
$supplier_stmt->bind_param("i", $supplier_id);
$supplier_stmt->execute();
$supplier_result = $supplier_stmt->get_result();
$supplier_info = $supplier_result->fetch_assoc();
$supplier_stmt->close();

// Get purchase orders for this supplier
$status_filter = $_GET['status'] ?? '';
$purchase_orders = [];

$po_stmt = $conn->prepare("
    SELECT po.*, u.full_name as created_by_name,
           (SELECT COUNT(*) FROM shipments WHERE po_id = po.id) as shipment_count,
           (SELECT COUNT(*) FROM po_notifications WHERE po_id = po.id AND supplier_id = ? AND is_read = FALSE) as unread_notifications,
           u2.full_name as delay_responded_by_name
    FROM purchase_orders po
    JOIN users u ON po.created_by = u.id
    LEFT JOIN users u2 ON po.delay_responded_by = u2.id
    WHERE po.supplier_id = ?
    " . ($status_filter ? "AND po.status = ?" : "") . "
    ORDER BY po.created_at DESC
");

if ($status_filter) {
    $po_stmt->bind_param("iis", $supplier_id, $supplier_id, $status_filter);
} else {
    $po_stmt->bind_param("ii", $supplier_id, $supplier_id);
}

$po_stmt->execute();
$po_result = $po_stmt->get_result();
while ($row = $po_result->fetch_assoc()) {
    $purchase_orders[] = $row;
}
$po_stmt->close();

// Get warehouses
$warehouses = [];
$result = $conn->query("SELECT id, name FROM warehouses WHERE status = 'active' ORDER BY name");
while ($row = $result->fetch_assoc()) {
    $warehouses[] = $row;
}

// Get vehicles
$vehicles = [];
$result = $conn->query("SELECT id, vehicle_number, vehicle_type, status FROM vehicles ORDER BY vehicle_number");
while ($row = $result->fetch_assoc()) {
    $vehicles[] = $row;
}

// Get drivers
$drivers = [];
$result = $conn->query("SELECT id, driver_code, full_name, status FROM drivers ORDER BY full_name");
while ($row = $result->fetch_assoc()) {
    $drivers[] = $row;
}

// AJAX endpoint: Get PO details
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_po_details' && isset($_GET['po_id'])) {
    header('Content-Type: application/json');
    $po_id = intval($_GET['po_id']);
    
    // Verify PO belongs to this supplier
    $stmt = $conn->prepare("
        SELECT po.*, s.company_name as supplier_name, u.full_name as created_by_name,
               u2.full_name as delay_responded_by_name
        FROM purchase_orders po
        JOIN suppliers s ON po.supplier_id = s.id
        JOIN users u ON po.created_by = u.id
        LEFT JOIN users u2 ON po.delay_responded_by = u2.id
        WHERE po.id = ? AND po.supplier_id = ?
    ");
    $stmt->bind_param("ii", $po_id, $supplier_id);
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
    }
    
    echo json_encode($po_data);
    closeDBConnection($conn);
    exit;
}

closeDBConnection($conn);

$pageTitle = 'Supplier Portal';
include '../includes/header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-store"></i> Supplier Portal - <?php echo htmlspecialchars($supplier_info['company_name'] ?? ''); ?></h2>
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
                                    <div style="display: flex; flex-wrap: wrap; gap: 5px;">
                                        <button class="btn btn-sm btn-secondary" style="white-space: nowrap;" onclick="viewPODetails(<?php echo $po['id']; ?>)">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <?php if (($po['status'] === 'pending' || $po['status'] === 'approved') && empty($po['supplier_approved'])): ?>
                                            <button class="btn btn-sm btn-success" style="white-space: nowrap;" onclick="approvePO(<?php echo $po['id']; ?>, '<?php echo htmlspecialchars($po['po_number'], ENT_QUOTES); ?>')">
                                                <i class="fas fa-check"></i> Accept
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php 
                                    $delay_status = $po['delay_status'] ?? 'none';
                                    if ($delay_status === 'requested'): 
                                    ?>
                                        <span class="badge badge-warning" title="Delay request pending procurement response">
                                            Delay Requested
                                        </span>
                                    <?php elseif ($delay_status === 'approved'): ?>
                                        <span class="badge badge-success" title="Delay request approved">
                                            Delay Approved
                                        </span>
                                    <?php elseif ($delay_status === 'rejected'): ?>
                                        <span class="badge badge-danger" title="Delay request rejected">
                                            Delay Rejected
                                        </span>
                                    <?php endif; 
                                    // Check if delayed but not requested
                                    if ($delay_status === 'none' && $po['delivery_date'] && !in_array($po['status'], ['received', 'cancelled', 'scheduled'])):
                                        $expected_date = strtotime($po['delivery_date']);
                                        $today = strtotime(date('Y-m-d'));
                                        if ($today > $expected_date):
                                    ?>
                                        <span class="badge badge-danger" title="Past expected date - you can request a delay">
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

<!-- Confirm Accept Order Modal -->
<div id="confirmAcceptModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3><i class="fas fa-exclamation-triangle" style="color: #ffc107;"></i> Confirm Accept Order</h3>
            <button class="modal-close" onclick="closeModal('confirmAcceptModal')">&times;</button>
        </div>
        <div class="card-body">
            <p style="margin-bottom: 20px; font-size: 16px;">Are you sure you want to accept the order?</p>
            <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px;">
                <div style="margin-bottom: 10px;">
                    <strong>PO Number:</strong> <span id="confirmAcceptPONumber"></span>
                </div>
            </div>
            <div style="padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px; margin-bottom: 20px;">
                <i class="fas fa-info-circle"></i> By accepting this order, you confirm that you can fulfill the purchase order requirements.
            </div>
            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('confirmAcceptModal')">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" class="btn btn-success" onclick="confirmAcceptOrder()">
                    <i class="fas fa-check"></i> Yes, Accept Order
                </button>
            </div>
            <input type="hidden" id="confirmAcceptPOId" value="">
        </div>
    </div>
</div>

<!-- Delay Request Modal -->
<div id="delayRequestModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3><i class="fas fa-clock" style="color: #ffc107;"></i> Request Delivery Delay</h3>
            <button class="modal-close" onclick="closeModal('delayRequestModal')">&times;</button>
        </div>
        <form method="POST" action="" id="delayRequestForm">
            <input type="hidden" name="po_id" id="delayRequestPOId">
            <input type="hidden" name="request_delay" value="1">
            
            <div class="card-body">
                <p style="margin-bottom: 20px; font-size: 16px;">You are requesting a delay for this purchase order.</p>
                <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px;">
                    <div style="margin-bottom: 10px;">
                        <strong>PO Number:</strong> <span id="delayRequestPONumber"></span>
                    </div>
                    <div>
                        <strong>Original Expected Date:</strong> <span id="delayRequestOriginalDate"></span>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="new_delivery_date">New Delivery Date *</label>
                    <input type="date" name="new_delivery_date" id="new_delivery_date" class="form-control" min="<?php echo date('Y-m-d'); ?>" required>
                    <small class="text-muted">Please select a new delivery date</small>
                </div>
                
                <div class="form-group">
                    <label for="delay_notes">Reason for Delay *</label>
                    <textarea name="delay_notes" id="delay_notes" class="form-control" rows="4" required placeholder="Please explain the reason for the delay..."></textarea>
                    <small class="text-muted">Procurement staff will review your request</small>
                </div>
                
                <div style="padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px; margin-bottom: 20px;">
                    <i class="fas fa-info-circle"></i> Your delay request will be sent to procurement staff for review. They will respond with approval or rejection.
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('delayRequestModal')">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-paper-plane"></i> Submit Delay Request
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Schedule Shipment Modal -->
<div id="scheduleShipmentModal" class="modal">
    <div class="modal-content" style="max-width: 800px;">
        <div class="modal-header">
            <h3><i class="fas fa-shipping-fast"></i> Schedule Shipment</h3>
            <button class="modal-close" onclick="closeModal('scheduleShipmentModal')">&times;</button>
        </div>
        <form method="POST" action="" id="scheduleShipmentForm">
            <input type="hidden" name="po_id" id="scheduleShipmentPOId">
            <input type="hidden" name="schedule_shipment" value="1">
            
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
                    <label for="scheduleShipmentDate">Scheduled Date *</label>
                    <input type="date" name="scheduled_date" id="scheduleShipmentDate" class="form-control" min="<?php echo date('Y-m-d'); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="transport_cost">Transport Cost</label>
                    <input type="number" name="transport_cost" id="transport_cost" class="form-control" step="0.01" min="0" value="0">
                </div>
                
                <h4 style="margin-bottom: 20px; margin-top: 30px;">Assign Driver and Vehicle (Optional)</h4>
                
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
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="schedule_driver_id">Driver</label>
                    <select name="driver_id" id="schedule_driver_id" class="form-control">
                        <option value="">-- Select Driver --</option>
                        <?php foreach ($drivers as $driver): ?>
                            <option value="<?php echo $driver['id']; ?>">
                                <?php echo htmlspecialchars($driver['full_name']); ?>
                                (<?php echo htmlspecialchars($driver['driver_code']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 30px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('scheduleShipmentModal')">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Schedule Shipment
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function viewPODetails(poId) {
    document.getElementById('poDetailsContent').innerHTML = '<p class="text-muted">Loading...</p>';
    openModal('poDetailsModal');
    
    fetch('?ajax=get_po_details&po_id=' + poId)
        .then(response => response.json())
        .then(data => {
            if (!data || !data.id) {
                document.getElementById('poDetailsContent').innerHTML = '<p class="text-danger">Purchase order not found.</p>';
                return;
            }
            
            let html = '<div style="margin-bottom: 20px;">';
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
                    html += '<span class="badge badge-warning">Delay Requested - Pending Response</span>';
                } else if (data.delay_status === 'approved') {
                    html += '<span class="badge badge-success">Delay Approved</span>';
                } else if (data.delay_status === 'rejected') {
                    html += '<span class="badge badge-danger">Delay Rejected</span>';
                }
                html += '</p>';
                
                if (data.delay_requested_date) {
                    html += '<p><strong>Requested New Delivery Date:</strong> ' + new Date(data.delay_requested_date).toLocaleDateString() + '</p>';
                }
                if (data.delay_notes) {
                    html += '<p><strong>Your Notes:</strong> ' + data.delay_notes.replace(/\n/g, '<br>') + '</p>';
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
            }
            
            // Add action buttons section
            html += '<div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">';
            html += '<div style="display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap;">';
            
            // Add Accept button if status is 'pending' or 'approved' (admin approved) and supplier hasn't approved yet
            if ((data.status === 'pending' || data.status === 'approved') && !data.supplier_approved) {
                html += '<button class="btn btn-success" onclick="closeModal(\'poDetailsModal\'); approvePO(' + data.id + ', \'' + data.po_number + '\');">';
                html += '<i class="fas fa-check"></i> Accept Order';
                html += '</button>';
            }
            
            // Add Request Delay button if delayed and no delay request yet
            if (data.delivery_date && (data.delay_status === 'none' || !data.delay_status)) {
                const expectedDate = new Date(data.delivery_date);
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                if (expectedDate < today && !['received', 'cancelled', 'scheduled'].includes(data.status)) {
                    html += '<button class="btn btn-warning" onclick="openDelayRequestModal(' + data.id + ', \'' + data.po_number + '\', \'' + data.delivery_date + '\');">';
                    html += '<i class="fas fa-clock"></i> Request Delay';
                    html += '</button>';
                }
            }
            
            // Add Schedule Shipment button if status is 'approved' and both admin and supplier approved
            if (data.status === 'approved' && data.admin_approved && data.supplier_approved) {
                html += '<button class="btn btn-primary" onclick="openScheduleShipmentModal(' + data.id + ');">';
                html += '<i class="fas fa-shipping-fast"></i> Schedule Shipment';
                html += '</button>';
            }
            
            html += '</div>';
            html += '</div>';
            
            document.getElementById('poDetailsContent').innerHTML = html;
        })
        .catch(error => {
            console.error('Error loading PO details:', error);
            document.getElementById('poDetailsContent').innerHTML = '<p class="text-danger">Error loading purchase order details.</p>';
        });
}

function approvePO(poId, poNumber) {
    // Set the PO details in the confirmation modal
    document.getElementById('confirmAcceptPOId').value = poId;
    document.getElementById('confirmAcceptPONumber').textContent = poNumber;
    
    // Show the confirmation modal
    openModal('confirmAcceptModal');
}

function confirmAcceptOrder() {
    const poId = document.getElementById('confirmAcceptPOId').value;
    const poNumber = document.getElementById('confirmAcceptPONumber').textContent;
    
    // Create and submit form
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '';
    
    const poIdInput = document.createElement('input');
    poIdInput.type = 'hidden';
    poIdInput.name = 'po_id';
    poIdInput.value = poId;
    form.appendChild(poIdInput);
    
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'supplier_approve_po';
    actionInput.value = '1';
    form.appendChild(actionInput);
    
    document.body.appendChild(form);
    form.submit();
}

function openScheduleShipmentModal(poId) {
    document.getElementById('scheduleShipmentPOId').value = poId;
    
    // Ensure destination warehouse field is enabled and editable
    const destinationWarehouseSelect = document.getElementById('destination_warehouse_id');
    if (destinationWarehouseSelect) {
        destinationWarehouseSelect.disabled = false;
        destinationWarehouseSelect.readOnly = false;
        destinationWarehouseSelect.removeAttribute('disabled');
        destinationWarehouseSelect.removeAttribute('readonly');
    }
    
    fetch('?ajax=get_po_details&po_id=' + poId)
        .then(response => response.json())
        .then(data => {
            if (!data || !data.id) {
                alert('Error loading purchase order details.');
                return;
            }
            
            document.getElementById('scheduleShipmentPONumber').textContent = data.po_number;
            document.getElementById('scheduleShipmentSupplier').textContent = data.supplier_name;
            
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
            
            const dateInput = document.getElementById('scheduleShipmentDate');
            const today = new Date().toISOString().split('T')[0];
            dateInput.min = today;
            
            if (data.delivery_date) {
                const expectedDate = new Date(data.delivery_date);
                const formattedDate = expectedDate.toISOString().split('T')[0];
                if (formattedDate >= today) {
                    dateInput.value = formattedDate;
                } else {
                    dateInput.value = today;
                }
            } else {
                dateInput.value = today;
            }
            
            // Auto-populate destination warehouse if available (from automatic low stock orders)
            const destinationWarehouseSelect = document.getElementById('destination_warehouse_id');
            if (data.destination_warehouse_id && destinationWarehouseSelect) {
                // Ensure the field is enabled and editable
                destinationWarehouseSelect.disabled = false;
                destinationWarehouseSelect.readOnly = false;
                destinationWarehouseSelect.removeAttribute('disabled');
                destinationWarehouseSelect.removeAttribute('readonly');
                
                // Set the value
                destinationWarehouseSelect.value = data.destination_warehouse_id;
                
                // Verify the value was set
                if (destinationWarehouseSelect.value != data.destination_warehouse_id) {
                    console.warn('Destination warehouse ID ' + data.destination_warehouse_id + ' not found in select options');
                }
            }
            
            openModal('scheduleShipmentModal');
        })
        .catch(error => {
            console.error('Error loading PO details:', error);
            alert('Error loading purchase order details.');
        });
}

function formatCurrency(amount) {
    return '₱' + parseFloat(amount).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
}

function openDelayRequestModal(poId, poNumber, originalDate) {
    document.getElementById('delayRequestPOId').value = poId;
    document.getElementById('delayRequestPONumber').textContent = poNumber;
    document.getElementById('delayRequestOriginalDate').textContent = originalDate ? new Date(originalDate).toLocaleDateString() : 'N/A';
    
    // Set min date to today
    const dateInput = document.getElementById('new_delivery_date');
    const today = new Date().toISOString().split('T')[0];
    dateInput.min = today;
    
    // Set default to today if original date is in the past, otherwise set to original date + 7 days
    if (originalDate) {
        const origDate = new Date(originalDate);
        const formattedOrig = origDate.toISOString().split('T')[0];
        if (formattedOrig >= today) {
            // Original date is in future, suggest original date + 7 days
            const suggestedDate = new Date(origDate);
            suggestedDate.setDate(suggestedDate.getDate() + 7);
            dateInput.value = suggestedDate.toISOString().split('T')[0];
        } else {
            // Original date is in past, use today
            dateInput.value = today;
        }
    } else {
        dateInput.value = today;
    }
    
    document.getElementById('delay_notes').value = '';
    openModal('delayRequestModal');
}
</script>

<?php include '../includes/footer.php'; ?>

