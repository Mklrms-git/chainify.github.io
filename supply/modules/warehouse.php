<?php
require_once '../config/config.php';
requireModuleAccess('warehouse');

$conn = getDBConnection();

// Get messages from session (set after redirect)
$message = '';
$error = '';

if (isset($_SESSION['warehouse_message'])) {
    $message = $_SESSION['warehouse_message'];
    unset($_SESSION['warehouse_message']);
}

if (isset($_SESSION['warehouse_error'])) {
    $error = $_SESSION['warehouse_error'];
    unset($_SESSION['warehouse_error']);
}

// Also check for GET parameters (for compatibility)
if (isset($_GET['success']) && empty($message)) {
    $message = 'Operation completed successfully!';
}
if (isset($_GET['error']) && empty($error)) {
    $error = 'An error occurred. Please try again.';
}

// Create Transfer Request
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_transfer'])) {
    requireCreatePermission('warehouse');
    $transfer_number = 'TRF-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
    $source_warehouse_id = $_POST['source_warehouse_id'] ?? 0;
    $destination_warehouse_id = $_POST['destination_warehouse_id'] ?? 0;
    $requested_date = $_POST['requested_date'] ?? date('Y-m-d');
    $notes = $_POST['notes'] ?? '';
    $items = $_POST['items'] ?? [];
    
    // Validate requested date - must not be in the past
    $today = date('Y-m-d');
    if ($requested_date < $today) {
        $_SESSION['warehouse_error'] = 'Requested date cannot be in the past. Please select today or a future date.';
        closeDBConnection($conn);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
        exit();
    }
    
    if ($source_warehouse_id && $destination_warehouse_id && $source_warehouse_id != $destination_warehouse_id && !empty($items)) {
        $stmt = $conn->prepare("INSERT INTO warehouse_transfers (transfer_number, source_warehouse_id, destination_warehouse_id, requested_by, requested_date, notes) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("siiiss", $transfer_number, $source_warehouse_id, $destination_warehouse_id, $_SESSION['user_id'], $requested_date, $notes);
        
        if ($stmt->execute()) {
            $transfer_id = $stmt->insert_id;
            
            // Insert items
            $item_stmt = $conn->prepare("INSERT INTO transfer_items (transfer_id, product_id, quantity) VALUES (?, ?, ?)");
            
            foreach ($items as $item) {
                if (isset($item['product_id']) && isset($item['quantity'])) {
                    // Check if source warehouse has enough stock
                    $check_stmt = $conn->prepare("SELECT quantity FROM inventory WHERE product_id = ? AND warehouse_id = ?");
                    $check_stmt->bind_param("ii", $item['product_id'], $source_warehouse_id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    $stock = $check_result->fetch_assoc();
                    $check_stmt->close();
                    
                    if ($stock && $stock['quantity'] >= $item['quantity']) {
                        $item_stmt->bind_param("iii", $transfer_id, $item['product_id'], $item['quantity']);
                        $item_stmt->execute();
                    }
                }
            }
            
            $item_stmt->close();
            
            // Notify Admin about new transfer request
            notifyUsersByRole(
                'admin',
                'transfer_request_created',
                'New Warehouse Transfer Request',
                "A new warehouse transfer request {$transfer_number} has been created and requires your approval.",
                'warehouse_transfer',
                $transfer_id
            );
            
            // Store message in session and redirect to prevent duplicate submissions
            $_SESSION['warehouse_message'] = "Transfer request {$transfer_number} created successfully! Admin has been notified for approval.";
            $stmt->close();
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1');
            exit();
        } else {
            $_SESSION['warehouse_error'] = 'Failed to create transfer request.';
            $stmt->close();
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit();
        }
    } else {
        $_SESSION['warehouse_error'] = 'Please fill all required fields and ensure source and destination are different.';
        closeDBConnection($conn);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
        exit();
    }
}

// Delete Transfer Request
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_transfer'])) {
    $transfer_id = $_POST['transfer_id'] ?? 0;
    
    if ($transfer_id) {
        // Get transfer details for permission check
        $transfer_check = $conn->prepare("SELECT transfer_number, status FROM warehouse_transfers WHERE id = ?");
        $transfer_check->bind_param("i", $transfer_id);
        $transfer_check->execute();
        $transfer_result = $transfer_check->get_result();
        $transfer_data = $transfer_result->fetch_assoc();
        $transfer_check->close();
        
        if (!$transfer_data) {
            $_SESSION['warehouse_error'] = 'Transfer request not found.';
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit();
        }
        
        // Check if user can delete this transfer
        if (!canDelete('warehouse', ['status' => $transfer_data['status']])) {
            $_SESSION['warehouse_error'] = 'You do not have permission to delete this transfer request.';
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit();
        }
        
        // Only allow deletion of pending transfers
        if ($transfer_data['status'] !== 'pending') {
            $_SESSION['warehouse_error'] = 'Only pending transfer requests can be deleted.';
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit();
        }
        
        // Delete transfer items first (foreign key constraint)
        $delete_items = $conn->prepare("DELETE FROM transfer_items WHERE transfer_id = ?");
        $delete_items->bind_param("i", $transfer_id);
        $delete_items->execute();
        $delete_items->close();
        
        // Delete transfer request
        $delete_stmt = $conn->prepare("DELETE FROM warehouse_transfers WHERE id = ?");
        $delete_stmt->bind_param("i", $transfer_id);
        
        if ($delete_stmt->execute()) {
            $_SESSION['warehouse_message'] = "Transfer request {$transfer_data['transfer_number']} deleted successfully!";
            $delete_stmt->close();
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1');
            exit();
        } else {
            $_SESSION['warehouse_error'] = 'Failed to delete transfer request.';
            $delete_stmt->close();
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit();
        }
    } else {
        $_SESSION['warehouse_error'] = 'Please provide transfer ID.';
        closeDBConnection($conn);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
        exit();
    }
}

// Approve Transfer Request (Admin only)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['approve_transfer'])) {
    requireApprovePermission('warehouse');
    $transfer_id = $_POST['transfer_id'] ?? 0;
    
    if ($transfer_id) {
        // Get transfer details
        $transfer_check = $conn->prepare("
            SELECT wt.*, 
                   u.full_name as requested_by_name,
                   u.id as requested_by_id
            FROM warehouse_transfers wt
            JOIN users u ON wt.requested_by = u.id
            WHERE wt.id = ? AND wt.status = 'pending'
        ");
        $transfer_check->bind_param("i", $transfer_id);
        $transfer_check->execute();
        $transfer_result = $transfer_check->get_result();
        $transfer_data = $transfer_result->fetch_assoc();
        $transfer_check->close();
        
        if (!$transfer_data) {
            $_SESSION['warehouse_error'] = 'Transfer request not found or already processed.';
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit();
        }
        
        // Update status to approved
        $update_stmt = $conn->prepare("UPDATE warehouse_transfers SET status = 'approved' WHERE id = ?");
        $update_stmt->bind_param("i", $transfer_id);
        
        if ($update_stmt->execute()) {
            $transfer_number = $transfer_data['transfer_number'];
            $requested_by_id = $transfer_data['requested_by_id'];
            
            // Notify Warehouse Officer who created the request
            createNotification(
                $requested_by_id,
                'warehouse_officer',
                'transfer_approved',
                'Transfer Request Approved',
                "Your transfer request {$transfer_number} has been approved by admin. Logistics will now book the shipment.",
                'warehouse_transfer',
                $transfer_id
            );
            
            // Notify Logistics team to book shipment
            notifyUsersByRole(
                'logistics_manager',
                'transfer_approved_for_shipment',
                'Transfer Request Approved - Book Shipment',
                "Transfer request {$transfer_number} has been approved. Please book the shipment for this transfer.",
                'warehouse_transfer',
                $transfer_id
            );
            
            $_SESSION['warehouse_message'] = "Transfer request {$transfer_number} approved successfully! Warehouse Officer and Logistics have been notified.";
            $update_stmt->close();
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1');
            exit();
        } else {
            $_SESSION['warehouse_error'] = 'Failed to approve transfer request.';
            $update_stmt->close();
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit();
        }
    } else {
        $_SESSION['warehouse_error'] = 'Please provide transfer ID.';
        closeDBConnection($conn);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
        exit();
    }
}

// Update Transfer Status
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_transfer_status'])) {
    $transfer_id = $_POST['transfer_id'] ?? 0;
    $status = $_POST['status'] ?? '';
    
    if ($transfer_id && $status) {
        // Get transfer details including shipment status
        $transfer_check = $conn->prepare("
            SELECT wt.status as current_status,
                   wt.source_warehouse_id,
                   wt.destination_warehouse_id,
                   (SELECT status FROM shipments WHERE transfer_id = wt.id ORDER BY id DESC LIMIT 1) as shipment_status
            FROM warehouse_transfers wt
            WHERE wt.id = ?
        ");
        $transfer_check->bind_param("i", $transfer_id);
        $transfer_check->execute();
        $transfer_result = $transfer_check->get_result();
        $transfer_data = $transfer_result->fetch_assoc();
        $transfer_check->close();
        
        if (!$transfer_data) {
            $_SESSION['warehouse_error'] = 'Transfer not found.';
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit();
        }
        
        $current_status = $transfer_data['current_status'];
        $shipment_status = $transfer_data['shipment_status'] ?? null;
        
        // Warehouse officer can only update status to 'received' when shipment is 'delivered'
        if ($status === 'received') {
            // Check if user is warehouse officer (not admin)
            $user_role = $_SESSION['role'] ?? '';
            if ($user_role !== 'warehouse_officer') {
                $_SESSION['warehouse_error'] = 'Only warehouse officers can mark transfers as received.';
                closeDBConnection($conn);
                header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
                exit();
            }
            
            // Check if shipment is delivered
            if ($shipment_status !== 'delivered') {
                $_SESSION['warehouse_error'] = 'Cannot mark as received. The shipment must be delivered first by logistics.';
                closeDBConnection($conn);
                header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
                exit();
            }
            
            // Check if already received or completed
            if ($current_status === 'received' || $current_status === 'completed') {
                $_SESSION['warehouse_error'] = 'Transfer is already marked as received or completed.';
                closeDBConnection($conn);
                header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
                exit();
            }
            
            // Update inventory only when both statuses match: warehouse marks as 'received' AND shipment is 'delivered'
            // At this point, we've already verified that shipment_status is 'delivered', so we can safely update inventory
            
            // Get transfer items for inventory update
            $transfer_items = [];
            $items_stmt = $conn->prepare("SELECT product_id, quantity FROM transfer_items WHERE transfer_id = ?");
            $items_stmt->bind_param("i", $transfer_id);
            $items_stmt->execute();
            $items_result = $items_stmt->get_result();
            while ($row = $items_result->fetch_assoc()) {
                $transfer_items[] = $row;
            }
            $items_stmt->close();
            
            // Update inventory (reduce from source, add to destination)
            foreach ($transfer_items as $item) {
                // Reduce from source warehouse
                $reduce_stmt = $conn->prepare("UPDATE inventory SET quantity = quantity - ? WHERE product_id = ? AND warehouse_id = ?");
                $reduce_stmt->bind_param("iii", $item['quantity'], $item['product_id'], $transfer_data['source_warehouse_id']);
                $reduce_stmt->execute();
                $reduce_stmt->close();
                
                // Add to destination warehouse
                $check_stmt = $conn->prepare("SELECT id FROM inventory WHERE product_id = ? AND warehouse_id = ?");
                $check_stmt->bind_param("ii", $item['product_id'], $transfer_data['destination_warehouse_id']);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $add_stmt = $conn->prepare("UPDATE inventory SET quantity = quantity + ? WHERE product_id = ? AND warehouse_id = ?");
                    $add_stmt->bind_param("iii", $item['quantity'], $item['product_id'], $transfer_data['destination_warehouse_id']);
                    $add_stmt->execute();
                    $add_stmt->close();
                } else {
                    $add_stmt = $conn->prepare("INSERT INTO inventory (product_id, warehouse_id, quantity) VALUES (?, ?, ?)");
                    $add_stmt->bind_param("iii", $item['product_id'], $transfer_data['destination_warehouse_id'], $item['quantity']);
                    $add_stmt->execute();
                    $add_stmt->close();
                }
                $check_stmt->close();
            }
            
            // Update transfer status to completed (both logistics delivered and warehouse received)
            $completed_date = date('Y-m-d');
            $update_stmt = $conn->prepare("UPDATE warehouse_transfers SET status = 'completed', completed_date = ? WHERE id = ?");
            $update_stmt->bind_param("si", $completed_date, $transfer_id);
            
            if ($update_stmt->execute()) {
                // Get transfer number for notification
                $notif_stmt = $conn->prepare("SELECT transfer_number, requested_by FROM warehouse_transfers WHERE id = ?");
                $notif_stmt->bind_param("i", $transfer_id);
                $notif_stmt->execute();
                $notif_result = $notif_stmt->get_result();
                $notif_data = $notif_result->fetch_assoc();
                $notif_stmt->close();
                
                if ($notif_data) {
                    $transfer_number = $notif_data['transfer_number'];
                    $requested_by_id = $notif_data['requested_by'];
                    
                    // Notify warehouse officer that transfer is completed
                    createNotification(
                        $requested_by_id,
                        'warehouse_officer',
                        'transfer_completed',
                        'Transfer Completed',
                        "Transfer request {$transfer_number} has been completed. All items have been transferred and inventory has been updated.",
                        'warehouse_transfer',
                        $transfer_id
                    );
                }
                
                $_SESSION['warehouse_message'] = 'Transfer marked as received and inventory updated successfully!';
                $update_stmt->close();
                closeDBConnection($conn);
                header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1');
                exit();
            } else {
                $_SESSION['warehouse_error'] = 'Failed to update transfer status.';
                $update_stmt->close();
                closeDBConnection($conn);
                header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
                exit();
            }
        } else {
            $_SESSION['warehouse_error'] = 'Invalid status update. Warehouse officers can only mark transfers as received.';
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit();
        }
    } else {
        $_SESSION['warehouse_error'] = 'Please provide transfer ID and status.';
        closeDBConnection($conn);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
        exit();
    }
}

// Get warehouses with stock levels
$warehouses = [];
$result = $conn->query("
    SELECT w.*, 
           COUNT(DISTINCT i.product_id) as product_count,
           SUM(i.quantity) as total_quantity,
           (w.utilized_capacity / w.capacity * 100) as capacity_percent
    FROM warehouses w
    LEFT JOIN inventory i ON w.id = i.warehouse_id
    GROUP BY w.id
    ORDER BY w.name
");
while ($row = $result->fetch_assoc()) {
    $warehouses[] = $row;
}

// Get transfers
$status_filter = $_GET['status'] ?? '';
$where_clause = $status_filter ? "WHERE wt.status = '$status_filter'" : "";

$transfers = [];
$result = $conn->query("
    SELECT wt.*, 
           w1.name as source_warehouse,
           w2.name as destination_warehouse,
           u.full_name as requested_by_name,
           COALESCE((
               SELECT SUM(ti.quantity * p.unit_price)
               FROM transfer_items ti
               JOIN products p ON ti.product_id = p.id
               WHERE ti.transfer_id = wt.id
           ), 0) as transfer_value,
           (SELECT status FROM shipments WHERE transfer_id = wt.id ORDER BY id DESC LIMIT 1) as shipment_status
    FROM warehouse_transfers wt
    JOIN warehouses w1 ON wt.source_warehouse_id = w1.id
    JOIN warehouses w2 ON wt.destination_warehouse_id = w2.id
    JOIN users u ON wt.requested_by = u.id
    $where_clause
    ORDER BY wt.created_at DESC
");
while ($row = $result->fetch_assoc()) {
    $transfers[] = $row;
}

// Get products (only those in inventory)
$products = [];
$result = $conn->query("SELECT DISTINCT p.id, p.name, p.sku FROM products p INNER JOIN inventory i ON p.id = i.product_id ORDER BY p.name");
while ($row = $result->fetch_assoc()) {
    $products[] = $row;
}

// AJAX endpoint: Get transfer details
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_transfer_details' && isset($_GET['transfer_id'])) {
    header('Content-Type: application/json');
    $transfer_id = intval($_GET['transfer_id']);
    
    // Get transfer details
    $stmt = $conn->prepare("
        SELECT wt.*,
               w1.name as source_warehouse,
               w2.name as destination_warehouse,
               u.full_name as requested_by_name,
               COALESCE((
                   SELECT SUM(ti.quantity * p.unit_price)
                   FROM transfer_items ti
                   JOIN products p ON ti.product_id = p.id
                   WHERE ti.transfer_id = wt.id
               ), 0) as transfer_value
        FROM warehouse_transfers wt
        JOIN warehouses w1 ON wt.source_warehouse_id = w1.id
        JOIN warehouses w2 ON wt.destination_warehouse_id = w2.id
        JOIN users u ON wt.requested_by = u.id
        WHERE wt.id = ?
    ");
    $stmt->bind_param("i", $transfer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $transfer_data = $result->fetch_assoc();
    $stmt->close();
    
    if ($transfer_data) {
        // Get transfer items
        $stmt = $conn->prepare("
            SELECT ti.*, p.name as product_name, p.sku, p.unit_price
            FROM transfer_items ti
            JOIN products p ON ti.product_id = p.id
            WHERE ti.transfer_id = ?
            ORDER BY ti.id
        ");
        $stmt->bind_param("i", $transfer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
        $stmt->close();
        
        $transfer_data['items'] = $items;
    }
    
    echo json_encode($transfer_data);
    closeDBConnection($conn);
    exit;
}

// AJAX endpoint: Get products with availability for a warehouse
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_warehouse_products' && isset($_GET['warehouse_id'])) {
    header('Content-Type: application/json');
    $warehouse_id = intval($_GET['warehouse_id']);
    
    $products = [];
    $stmt = $conn->prepare("
        SELECT p.id, p.name, p.sku, COALESCE(i.quantity, 0) as available_quantity
        FROM inventory i
        INNER JOIN products p ON i.product_id = p.id
        WHERE i.warehouse_id = ? AND i.quantity > 0
        ORDER BY p.name
    ");
    $stmt->bind_param("i", $warehouse_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
    $stmt->close();
    
    echo json_encode($products);
    closeDBConnection($conn);
    exit;
}

closeDBConnection($conn);

$pageTitle = 'Inventory Distribution & Warehouse Coordination';
include '../includes/header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-building"></i> Inventory Distribution & Warehouse Coordination</h2>
    <?php if (canCreate('warehouse')): ?>
    <button class="btn btn-primary" onclick="openModal('createTransferModal')">
        <i class="fas fa-exchange-alt"></i> Create Transfer Request
    </button>
    <?php endif; ?>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="stats-grid">
    <?php foreach ($warehouses as $warehouse): ?>
        <div class="stat-card">
            <div class="stat-icon blue">
                <i class="fas fa-warehouse"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo htmlspecialchars($warehouse['name']); ?></h3>
                <p><?php echo $warehouse['product_count']; ?> Products | <?php echo number_format($warehouse['total_quantity'] ?? 0); ?> Units</p>
                <p class="text-muted">Capacity: <?php echo number_format($warehouse['capacity_percent'], 1); ?>%</p>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="filters-bar">
    <div class="filter-group">
        <label>Filter by Status</label>
        <select class="form-control" onchange="window.location.href='?status=' + this.value">
            <option value="">All Statuses</option>
            <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
            <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
            <option value="in_transit" <?php echo $status_filter == 'in_transit' ? 'selected' : ''; ?>>In Transit</option>
            <option value="delivered" <?php echo $status_filter == 'delivered' ? 'selected' : ''; ?>>Delivered</option>
            <option value="received" <?php echo $status_filter == 'received' ? 'selected' : ''; ?>>Received</option>
            <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
        </select>
    </div>
</div>

<div class="dashboard-card">
    <div class="card-header">
        <h3><i class="fas fa-list"></i> Warehouse Transfers</h3>
    </div>
    <div class="card-body">
        <?php if (empty($transfers)): ?>
            <p class="text-muted">No transfer requests found.</p>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Transfer #</th>
                            <th>Source Warehouse</th>
                            <th>Destination Warehouse</th>
                            <th>Requested Date</th>
                            <th>Status</th>
                            <th>Requested By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transfers as $transfer): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($transfer['transfer_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars($transfer['source_warehouse']); ?></td>
                                <td><?php echo htmlspecialchars($transfer['destination_warehouse']); ?></td>
                                <td><?php echo formatDate($transfer['requested_date']); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $transfer['status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $transfer['status'])); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($transfer['requested_by_name']); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-secondary" onclick="viewTransferDetails(<?php echo $transfer['id']; ?>)">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <?php 
                                    // Transfer value is already calculated in the query
                                    $transfer_value = floatval($transfer['transfer_value'] ?? 0);
                                    
                                    // Admin approval button for pending transfers
                                    if ($transfer['status'] == 'pending' && isAdmin()): 
                                    ?>
                                        <button class="btn btn-sm btn-success" onclick="confirmApproveTransfer(<?php echo $transfer['id']; ?>, '<?php echo htmlspecialchars($transfer['transfer_number'], ENT_QUOTES); ?>')">
                                            <i class="fas fa-check-circle"></i> Approve
                                        </button>
                                    <?php endif; ?>
                                    <?php 
                                    // Warehouse officer can mark as received when shipment is delivered
                                    $shipment_status = $transfer['shipment_status'] ?? null;
                                    $user_role = $_SESSION['role'] ?? '';
                                    if ($transfer['status'] == 'delivered' && $shipment_status == 'delivered' && $user_role === 'warehouse_officer'): 
                                    ?>
                                        <button class="btn btn-sm btn-success" onclick="confirmUpdateTransferStatus(<?php echo $transfer['id']; ?>, 'received', '<?php echo htmlspecialchars($transfer['transfer_number'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($transfer['source_warehouse'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($transfer['destination_warehouse'], ENT_QUOTES); ?>')">
                                            <i class="fas fa-check-circle"></i> Mark as Received
                                        </button>
                                    <?php endif; ?>
                                    <?php 
                                    // Show delete button for pending transfers if user has permission
                                    if ($transfer['status'] == 'pending' && canDelete('warehouse', ['status' => $transfer['status']])): 
                                    ?>
                                        <button class="btn btn-sm btn-danger" onclick="confirmDeleteTransfer(<?php echo $transfer['id']; ?>, '<?php echo htmlspecialchars($transfer['transfer_number'], ENT_QUOTES); ?>')">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Create Transfer Modal -->
<div id="createTransferModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Create Transfer Request</h3>
            <button class="modal-close" onclick="closeModal('createTransferModal')">&times;</button>
        </div>
        <form method="POST" action="" id="transferForm">
            <div class="form-group">
                <label for="source_warehouse_id">Source Warehouse *</label>
                <select name="source_warehouse_id" id="source_warehouse_id" class="form-control" required onchange="loadWarehouseStock()">
                    <option value="">Select Warehouse</option>
                    <?php foreach ($warehouses as $warehouse): ?>
                        <option value="<?php echo $warehouse['id']; ?>">
                            <?php echo htmlspecialchars($warehouse['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
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
                <label for="requested_date">Requested Date *</label>
                <input type="date" name="requested_date" id="requested_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" min="<?php echo date('Y-m-d'); ?>" required>
                <small class="text-muted">Past dates are not allowed</small>
            </div>
            
            <div class="form-group">
                <label>Items *</label>
                <small class="text-muted" style="display: block; margin-bottom: 10px;">Select source warehouse first to see available products and quantities</small>
                <div id="transferItems">
                    <div class="transfer-item-row">
                        <select name="items[0][product_id]" id="product_0" class="form-control transfer-product-select" required onchange="updateQuantityMax(this)">
                            <option value="">Select Product</option>
                            <?php foreach ($products as $product): ?>
                                <option value="<?php echo $product['id']; ?>" data-available="0">
                                    <?php echo htmlspecialchars($product['name'] . ' (' . $product['sku'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" name="items[0][quantity]" id="quantity_0" placeholder="Qty" class="form-control transfer-quantity-input" min="1" max="0" required>
                        <button type="button" class="btn btn-danger btn-sm" onclick="removeTransferItem(this)"><i class="fas fa-times"></i></button>
                    </div>
                </div>
                <button type="button" class="btn btn-secondary btn-sm mt-1" onclick="addTransferItem()">
                    <i class="fas fa-plus"></i> Add Item
                </button>
            </div>
            
            <div class="form-group">
                <label for="notes">Notes</label>
                <textarea name="notes" id="notes" class="form-control" rows="3"></textarea>
            </div>
            
            <div class="form-group">
                <button type="submit" name="create_transfer" class="btn btn-primary btn-block">
                    <i class="fas fa-save"></i> Create Transfer Request
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Transfer Details Modal -->
<div id="transferDetailsModal" class="modal">
    <div class="modal-content" style="max-width: 800px;">
        <div class="modal-header">
            <h3>Transfer Request Details</h3>
            <button class="modal-close" onclick="closeModal('transferDetailsModal')">&times;</button>
        </div>
        <div class="card-body" id="transferDetailsContent">
            <p class="text-muted">Loading...</p>
        </div>
    </div>
</div>

<!-- Update Transfer Status Confirmation Modal -->
<div id="updateTransferStatusModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3><i class="fas fa-exclamation-triangle" style="color: #ffc107;"></i> Confirm Status Update</h3>
            <button class="modal-close" onclick="closeModal('updateTransferStatusModal')">&times;</button>
        </div>
        <div class="card-body">
            <p id="updateStatusMessage" style="margin-bottom: 20px; font-size: 16px;"></p>
            <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px;">
                <div style="margin-bottom: 10px;">
                    <strong>Transfer Number:</strong> <span id="updateTransferNumber"></span>
                </div>
                <div style="margin-bottom: 10px;">
                    <strong>Source Warehouse:</strong> <span id="updateSourceWarehouse"></span>
                </div>
                <div style="margin-bottom: 10px;">
                    <strong>Destination Warehouse:</strong> <span id="updateDestinationWarehouse"></span>
                </div>
                <div>
                    <strong>New Status:</strong> <span id="updateNewStatus" class="badge"></span>
                </div>
            </div>
            <div id="updateStatusWarning" style="padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px; margin-bottom: 20px; display: none;">
                <i class="fas fa-info-circle"></i> <span id="updateStatusWarningText"></span>
            </div>
            <form method="POST" action="" id="updateTransferStatusForm">
                <input type="hidden" name="transfer_id" id="updateTransferId">
                <input type="hidden" name="status" id="updateStatusValue">
                <input type="hidden" name="update_transfer_status" value="1">
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('updateTransferStatusModal')">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn" id="updateStatusSubmitBtn">
                        <i class="fas fa-check"></i> Confirm
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Approve Transfer Confirmation Modal -->
<div id="approveTransferModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3><i class="fas fa-check-circle" style="color: #28a745;"></i> Approve Transfer Request</h3>
            <button class="modal-close" onclick="closeModal('approveTransferModal')">&times;</button>
        </div>
        <div class="card-body">
            <p style="margin-bottom: 20px; font-size: 16px;">Are you sure you want to approve this transfer request?</p>
            <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px;">
                <div style="margin-bottom: 10px;">
                    <strong>Transfer Number:</strong> <span id="approveTransferNumber"></span>
                </div>
            </div>
            <div style="padding: 10px; background: #d4edda; border-left: 4px solid #28a745; border-radius: 4px; margin-bottom: 20px;">
                <i class="fas fa-info-circle"></i> Once approved, Warehouse Officer and Logistics will be notified. Logistics can then book the shipment.
            </div>
            <form method="POST" action="" id="approveTransferForm">
                <input type="hidden" name="transfer_id" id="approveTransferId">
                <input type="hidden" name="approve_transfer" value="1">
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('approveTransferModal')">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check-circle"></i> Approve
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteTransferModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3><i class="fas fa-exclamation-triangle" style="color: #dc3545;"></i> Confirm Deletion</h3>
            <button class="modal-close" onclick="closeModal('deleteTransferModal')">&times;</button>
        </div>
        <div class="card-body">
            <p style="margin-bottom: 20px;">Are you sure you want to delete this transfer request?</p>
            <p style="margin-bottom: 20px; padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px;">
                <strong>Transfer Number:</strong> <span id="deleteTransferNumber"></span>
            </p>
            <p style="color: #dc3545; margin-bottom: 20px;">
                <i class="fas fa-exclamation-circle"></i> This action cannot be undone. Only pending transfers can be deleted.
            </p>
            <form method="POST" action="" id="deleteTransferForm">
                <input type="hidden" name="transfer_id" id="deleteTransferId">
                <input type="hidden" name="delete_transfer" value="1">
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('deleteTransferModal')">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let transferItemCount = 1;
const canViewFinancial = <?php echo canViewFinancialData() ? 'true' : 'false'; ?>;

function addTransferItem() {
    const container = document.getElementById('transferItems');
    const newRow = document.createElement('div');
    newRow.className = 'transfer-item-row';
    newRow.innerHTML = `
        <select name="items[${transferItemCount}][product_id]" class="form-control transfer-product-select" required onchange="updateQuantityMax(this)">
            <option value="">Select Product</option>
        </select>
        <input type="number" name="items[${transferItemCount}][quantity]" placeholder="Qty" class="form-control transfer-quantity-input" min="1" required>
        <button type="button" class="btn btn-danger btn-sm" onclick="removeTransferItem(this)"><i class="fas fa-times"></i></button>
    `;
    container.appendChild(newRow);
    
    // Update product options for the new select
    const newSelect = newRow.querySelector('.transfer-product-select');
    updateProductSelects();
    
    transferItemCount++;
}

function removeTransferItem(btn) {
    btn.closest('.transfer-item-row').remove();
}

// Store products with availability for current warehouse
let warehouseProducts = [];

function loadWarehouseStock() {
    const sourceWarehouseId = document.getElementById('source_warehouse_id').value;
    const transferItems = document.getElementById('transferItems');
    
    if (!sourceWarehouseId) {
        // Reset products if no warehouse selected
        warehouseProducts = [];
        updateProductSelects();
        return;
    }
    
    // Show loading state
    const productSelects = transferItems.querySelectorAll('.transfer-product-select');
    productSelects.forEach(select => {
        select.disabled = true;
        const loadingOption = select.querySelector('option[value=""]');
        if (loadingOption) {
            loadingOption.textContent = 'Loading products...';
        }
    });
    
    // Fetch products with availability from selected warehouse
    fetch('?ajax=get_warehouse_products&warehouse_id=' + sourceWarehouseId)
        .then(response => response.json())
        .then(products => {
            warehouseProducts = products || [];
            updateProductSelects();
            
            // Enable product selects
            productSelects.forEach(select => {
                select.disabled = false;
                const loadingOption = select.querySelector('option[value=""]');
                if (loadingOption) {
                    loadingOption.textContent = 'Select Product';
                }
            });
        })
        .catch(error => {
            console.error('Error loading warehouse products:', error);
            alert('Error loading products. Please try again.');
            productSelects.forEach(select => {
                select.disabled = false;
                const loadingOption = select.querySelector('option[value=""]');
                if (loadingOption) {
                    loadingOption.textContent = 'Select Product';
                }
            });
        });
}

function updateProductSelects() {
    const productSelects = document.querySelectorAll('.transfer-product-select');
    productSelects.forEach(select => {
        const currentValue = select.value;
        const availableQty = parseInt(select.options[select.selectedIndex]?.getAttribute('data-available') || 0);
        
        // Clear all options except the first one
        while (select.options.length > 1) {
            select.remove(1);
        }
        
        // Add products with availability
        warehouseProducts.forEach(product => {
            const option = document.createElement('option');
            option.value = product.id;
            option.textContent = product.name + ' (' + product.sku + ')';
            option.setAttribute('data-available', product.available_quantity);
            select.appendChild(option);
        });
        
        // Restore previous selection if still available
        if (currentValue) {
            select.value = currentValue;
            updateQuantityMax(select);
        } else {
            // Update max for first product if one is selected
            if (select.value) {
                updateQuantityMax(select);
            }
        }
    });
}

function updateQuantityMax(selectElement) {
    const row = selectElement.closest('.transfer-item-row');
    const quantityInput = row.querySelector('.transfer-quantity-input');
    
    if (!quantityInput) return;
    
    const selectedOption = selectElement.options[selectElement.selectedIndex];
    const availableQty = parseInt(selectedOption?.getAttribute('data-available') || 0);
    
    if (selectElement.value && selectedOption) {
        // Show available quantity in placeholder
        quantityInput.placeholder = 'Qty (Available: ' + availableQty + ')';
        
        if (availableQty > 0) {
            quantityInput.max = availableQty;
            quantityInput.setAttribute('max', availableQty);
        } else {
            quantityInput.max = '';
            quantityInput.removeAttribute('max');
        }
    } else {
        // No product selected
        quantityInput.max = '';
        quantityInput.removeAttribute('max');
        quantityInput.placeholder = 'Qty';
    }
    
    // Clear quantity if it exceeds available
    if (quantityInput.value && availableQty > 0 && parseInt(quantityInput.value) > availableQty) {
        quantityInput.value = '';
    }
}

function viewTransferDetails(transferId) {
    // Show loading state
    document.getElementById('transferDetailsContent').innerHTML = '<p class="text-muted">Loading...</p>';
    openModal('transferDetailsModal');
    
    // Fetch transfer details
    fetch('?ajax=get_transfer_details&transfer_id=' + transferId)
        .then(response => response.json())
        .then(data => {
            if (!data || !data.id) {
                document.getElementById('transferDetailsContent').innerHTML = '<p class="text-danger">Transfer request not found.</p>';
                return;
            }
            
            let html = '<div style="margin-bottom: 20px;">';
            html += '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">';
            html += '<div><strong>Transfer Number:</strong><br>' + data.transfer_number + '</div>';
            html += '<div><strong>Status:</strong><br><span class="badge badge-' + data.status + '">' + data.status.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase()) + '</span></div>';
            html += '</div>';
            
            html += '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">';
            html += '<div><strong>Source Warehouse:</strong><br>' + data.source_warehouse + '</div>';
            html += '<div><strong>Destination Warehouse:</strong><br>' + data.destination_warehouse + '</div>';
            html += '</div>';
            
            html += '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">';
            html += '<div><strong>Requested Date:</strong><br>' + new Date(data.requested_date).toLocaleDateString() + '</div>';
            if (data.completed_date) {
                html += '<div><strong>Received Date:</strong><br>' + new Date(data.completed_date).toLocaleDateString() + '</div>';
            } else {
                html += '<div><strong>Received Date:</strong><br><span class="text-muted">Not received</span></div>';
            }
            html += '</div>';
            
            html += '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">';
            html += '<div><strong>Requested By:</strong><br>' + data.requested_by_name + '</div>';
            if (canViewFinancial) {
                html += '<div><strong>Transfer Value:</strong><br><strong style="font-size: 1.2em; color: #28a745;">' + formatCurrency(data.transfer_value || 0) + '</strong></div>';
            }
            html += '</div>';
            
            if (data.notes) {
                html += '<div style="margin-bottom: 15px;">';
                html += '<strong>Notes:</strong><br><div style="padding: 10px; background: #f5f5f5; border-radius: 4px; margin-top: 5px;">' + data.notes + '</div>';
                html += '</div>';
            }
            
            html += '</div>';
            
            if (data.items && data.items.length > 0) {
                html += '<div style="margin-top: 20px;"><strong>Transfer Items:</strong></div>';
                html += '<div style="overflow-x: auto; margin-top: 10px;">';
                html += '<table class="data-table">';
                html += '<thead><tr><th>Product</th><th>SKU</th><th>Quantity</th>';
                if (canViewFinancial) {
                    html += '<th>Unit Price</th><th>Subtotal</th>';
                }
                html += '</tr></thead>';
                html += '<tbody>';
                let totalValue = 0;
                data.items.forEach(item => {
                    const subtotal = item.quantity * (item.unit_price || 0);
                    totalValue += subtotal;
                    html += '<tr>';
                    html += '<td>' + item.product_name + '</td>';
                    html += '<td>' + item.sku + '</td>';
                    html += '<td>' + item.quantity + '</td>';
                    if (canViewFinancial) {
                        html += '<td>' + formatCurrency(item.unit_price || 0) + '</td>';
                        html += '<td>' + formatCurrency(subtotal) + '</td>';
                    }
                    html += '</tr>';
                });
                html += '</tbody>';
                if (canViewFinancial) {
                    html += '<tfoot><tr><td colspan="3" style="text-align: right;"><strong>Total Value:</strong></td><td colspan="2"><strong>' + formatCurrency(totalValue) + '</strong></td></tr></tfoot>';
                }
                html += '</table>';
                html += '</div>';
            } else {
                html += '<p class="text-muted">No items found.</p>';
            }
            
            document.getElementById('transferDetailsContent').innerHTML = html;
        })
        .catch(error => {
            console.error('Error loading transfer details:', error);
            document.getElementById('transferDetailsContent').innerHTML = '<p class="text-danger">Error loading transfer details.</p>';
        });
}

function formatCurrency(amount) {
    return '₱' + parseFloat(amount).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
}

function confirmUpdateTransferStatus(transferId, status, transferNumber, sourceWarehouse, destinationWarehouse) {
    // Set the transfer details in the modal
    document.getElementById('updateTransferId').value = transferId;
    document.getElementById('updateStatusValue').value = status;
    document.getElementById('updateTransferNumber').textContent = transferNumber;
    document.getElementById('updateSourceWarehouse').textContent = sourceWarehouse;
    document.getElementById('updateDestinationWarehouse').textContent = destinationWarehouse;
    
    // Set the status badge
    const statusBadge = document.getElementById('updateNewStatus');
    let statusText = 'Received';
    if (status === 'in_transit') {
        statusText = 'In Transit';
    } else if (status === 'completed') {
        statusText = 'Completed';
    }
    statusBadge.textContent = statusText;
    statusBadge.className = 'badge badge-' + (status === 'received' ? 'success' : (status === 'in_transit' ? 'warning' : 'success'));
    
    // Set the confirmation message based on status
    const messageEl = document.getElementById('updateStatusMessage');
    const warningEl = document.getElementById('updateStatusWarning');
    const warningTextEl = document.getElementById('updateStatusWarningText');
    const submitBtn = document.getElementById('updateStatusSubmitBtn');
    
    if (status === 'received') {
        messageEl.textContent = 'Are you sure you want to mark this transfer as received?';
        messageEl.style.color = '#28a745';
        warningEl.style.display = 'block';
        warningTextEl.textContent = 'This will mark the transfer as received and update the inventory: items will be removed from the source warehouse and added to the destination warehouse. This action cannot be undone.';
        submitBtn.className = 'btn btn-success';
        submitBtn.innerHTML = '<i class="fas fa-check-circle"></i> Mark as Received';
    } else if (status === 'in_transit') {
        messageEl.textContent = 'Are you sure you want to start this transfer?';
        messageEl.style.color = '#ffc107';
        warningEl.style.display = 'block';
        warningTextEl.textContent = 'This will mark the transfer as "In Transit". The items will remain in the source warehouse until the transfer is completed.';
        submitBtn.className = 'btn btn-warning';
        submitBtn.innerHTML = '<i class="fas fa-truck"></i> Start Transfer';
    } else if (status === 'completed') {
        messageEl.textContent = 'Are you sure you want to complete this transfer?';
        messageEl.style.color = '#28a745';
        warningEl.style.display = 'block';
        warningTextEl.textContent = 'This will update the inventory: items will be removed from the source warehouse and added to the destination warehouse. This action cannot be undone.';
        submitBtn.className = 'btn btn-success';
        submitBtn.innerHTML = '<i class="fas fa-check"></i> Complete Transfer';
    } else {
        messageEl.textContent = 'Are you sure you want to update the transfer status?';
        messageEl.style.color = '#333';
        warningEl.style.display = 'none';
        submitBtn.className = 'btn btn-primary';
        submitBtn.innerHTML = '<i class="fas fa-check"></i> Confirm';
    }
    
    // Show the confirmation modal
    openModal('updateTransferStatusModal');
}

function updateTransferStatus(transferId, status) {
    // This function is kept for backward compatibility but now uses the modal
    confirmUpdateTransferStatus(transferId, status, '', '', '');
}

function confirmApproveTransfer(transferId, transferNumber) {
    // Set the transfer ID and number in the modal
    document.getElementById('approveTransferId').value = transferId;
    document.getElementById('approveTransferNumber').textContent = transferNumber;
    
    // Show the confirmation modal
    openModal('approveTransferModal');
}

function confirmDeleteTransfer(transferId, transferNumber) {
    // Set the transfer ID and number in the modal
    document.getElementById('deleteTransferId').value = transferId;
    document.getElementById('deleteTransferNumber').textContent = transferNumber;
    
    // Show the confirmation modal
    openModal('deleteTransferModal');
}
</script>

<style>
.transfer-item-row {
    display: grid;
    grid-template-columns: 2fr 1fr auto;
    gap: 10px;
    margin-bottom: 10px;
    align-items: center;
}

.transfer-quantity-input {
    min-width: 120px;
}
</style>

<?php include '../includes/footer.php'; ?>

