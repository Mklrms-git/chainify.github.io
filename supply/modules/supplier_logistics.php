<?php
require_once '../config/config.php';

// Check if user is supplier
if (!isLoggedIn() || !isSupplier()) {
    header('Location: ' . BASE_URL . 'dashboard.php');
    exit;
}

$conn = getDBConnection();
$message = '';
$error = '';

// Get supplier ID - check if user is supplier and linked to supplier record
$supplier_id = getSupplierId();

if (!$supplier_id) {
    // Try to auto-link if there's only one supplier without a user account
    $auto_link_stmt = $conn->prepare("SELECT id FROM suppliers WHERE user_id IS NULL AND status = 'active' LIMIT 1");
    $auto_link_stmt->execute();
    $auto_link_result = $auto_link_stmt->get_result();
    $auto_link_supplier = $auto_link_result->fetch_assoc();
    $auto_link_stmt->close();
    
    if ($auto_link_supplier) {
        // Auto-link to the first available supplier
        $link_stmt = $conn->prepare("UPDATE suppliers SET user_id = ? WHERE id = ?");
        $link_stmt->bind_param("ii", $_SESSION['user_id'], $auto_link_supplier['id']);
        if ($link_stmt->execute()) {
            $supplier_id = $auto_link_supplier['id'];
            $message = 'Your account has been automatically linked to a supplier record.';
        }
        $link_stmt->close();
    }
    
    if (!$supplier_id) {
        $_SESSION['supplier_portal_error'] = 'Supplier account not linked to a supplier record. Please contact administrator to link your account through the Users module.';
        closeDBConnection($conn);
        header('Location: ' . BASE_URL . 'dashboard.php');
        exit;
    }
}

// Get messages from session first
if (isset($_SESSION['supplier_logistics_message'])) {
    $message = $_SESSION['supplier_logistics_message'];
    unset($_SESSION['supplier_logistics_message']);
}

if (isset($_SESSION['supplier_logistics_error'])) {
    $error = $_SESSION['supplier_logistics_error'];
    unset($_SESSION['supplier_logistics_error']);
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
        // Verify PO belongs to this supplier and both admin and supplier have approved
        $check_stmt = $conn->prepare("SELECT id, po_number, supplier_id, status, admin_approved, supplier_approved FROM purchase_orders WHERE id = ? AND supplier_id = ?");
        $check_stmt->bind_param("ii", $po_id, $supplier_id);
        $check_stmt->execute();
        $po_result = $check_stmt->get_result();
        $po_data = $po_result->fetch_assoc();
        $check_stmt->close();
        
        // Only allow scheduling if both admin and supplier have approved
        if ($po_data && $po_data['status'] === 'approved' && $po_data['admin_approved'] && $po_data['supplier_approved']) {
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
                    
                    $_SESSION['supplier_logistics_message'] = "Shipment {$shipment_number} scheduled successfully! The logistics manager has been notified.";
                    $stmt->close();
                    closeDBConnection($conn);
                    header('Location: ' . $_SERVER['PHP_SELF']);
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

// Get approved purchase orders that can be scheduled (not already scheduled)
// Only show POs where both admin and supplier have approved
$purchase_orders = [];
$po_stmt = $conn->prepare("
    SELECT po.*, u.full_name as created_by_name,
           (SELECT COUNT(*) FROM shipments WHERE po_id = po.id) as shipment_count
    FROM purchase_orders po
    JOIN users u ON po.created_by = u.id
    WHERE po.supplier_id = ? AND po.status = 'approved' 
    AND po.admin_approved = TRUE AND po.supplier_approved = TRUE
    AND (SELECT COUNT(*) FROM shipments WHERE po_id = po.id) = 0
    ORDER BY po.created_at DESC
");
$po_stmt->bind_param("i", $supplier_id);
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

// Get shipments for this supplier only
$status_filter = $_GET['status'] ?? '';
$where_conditions = ["s.supplier_id = ?"];
$param_types = "i";
$params = [$supplier_id];

if ($status_filter) {
    $where_conditions[] = "s.status = ?";
    $param_types .= "s";
    $params[] = $status_filter;
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Build query with parameter binding
$query = "
    SELECT s.*, 
           po.po_number,
           w1.name as origin_warehouse,
           w2.name as destination_warehouse,
           v.vehicle_number, v.vehicle_type, v.make as vehicle_make, v.model as vehicle_model,
           d.full_name as driver_name, d.driver_code, d.phone as driver_phone,
           u.full_name as created_by_name
    FROM shipments s
    LEFT JOIN purchase_orders po ON s.po_id = po.id
    LEFT JOIN warehouses w1 ON s.origin_warehouse_id = w1.id
    LEFT JOIN warehouses w2 ON s.destination_warehouse_id = w2.id
    LEFT JOIN vehicles v ON s.vehicle_id = v.id
    LEFT JOIN drivers d ON s.driver_id = d.id
    JOIN users u ON s.created_by = u.id
    $where_clause
    ORDER BY s.created_at DESC
";

$stmt = $conn->prepare($query);
$stmt->bind_param($param_types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$shipments = [];
while ($row = $result->fetch_assoc()) {
    $shipments[] = $row;
}
$stmt->close();

// Calculate statistics for this supplier's shipments
$shipment_stats = [
    'active' => 0,
    'in_transit' => 0,
    'delivered_today' => 0,
    'delayed' => 0,
    'total' => count($shipments)
];

foreach ($shipments as $shipment) {
    if (in_array($shipment['status'], ['scheduled', 'in_transit'])) {
        $shipment_stats['active']++;
    }
    if ($shipment['status'] === 'in_transit') {
        $shipment_stats['in_transit']++;
    }
    if ($shipment['status'] === 'delivered' && $shipment['delivery_date'] && date('Y-m-d', strtotime($shipment['delivery_date'])) === date('Y-m-d')) {
        $shipment_stats['delivered_today']++;
    }
    if (in_array($shipment['status'], ['scheduled', 'in_transit']) && $shipment['scheduled_date'] && strtotime($shipment['scheduled_date']) < strtotime(date('Y-m-d'))) {
        $shipment_stats['delayed']++;
    }
}

// AJAX endpoint: Get PO details
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_po_details' && isset($_GET['po_id'])) {
    header('Content-Type: application/json');
    $po_id = intval($_GET['po_id']);
    
    // Verify PO belongs to this supplier
    $stmt = $conn->prepare("
        SELECT po.*, s.company_name as supplier_name, u.full_name as created_by_name
        FROM purchase_orders po
        JOIN suppliers s ON po.supplier_id = s.id
        JOIN users u ON po.created_by = u.id
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
    
    echo json_encode($po_data ?: ['error' => 'Purchase order not found']);
    closeDBConnection($conn);
    exit;
}

// AJAX endpoint: Get shipment details
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_shipment_details' && isset($_GET['shipment_id'])) {
    header('Content-Type: application/json');
    $shipment_id = intval($_GET['shipment_id']);
    
    // Verify shipment belongs to this supplier
    $stmt = $conn->prepare("
        SELECT s.*, 
               po.po_number,
               w1.name as origin_warehouse,
               w2.name as destination_warehouse,
               w2.latitude as destination_latitude,
               w2.longitude as destination_longitude,
               sup.company_name as supplier_name,
               v.vehicle_number, v.vehicle_type, v.make as vehicle_make, v.model as vehicle_model,
               d.full_name as driver_name, d.driver_code, d.phone as driver_phone,
               u.full_name as created_by_name
        FROM shipments s
        LEFT JOIN purchase_orders po ON s.po_id = po.id
        LEFT JOIN warehouses w1 ON s.origin_warehouse_id = w1.id
        LEFT JOIN warehouses w2 ON s.destination_warehouse_id = w2.id
        LEFT JOIN suppliers sup ON s.supplier_id = sup.id
        LEFT JOIN vehicles v ON s.vehicle_id = v.id
        LEFT JOIN drivers d ON s.driver_id = d.id
        JOIN users u ON s.created_by = u.id
        WHERE s.id = ? AND s.supplier_id = ?
    ");
    $stmt->bind_param("ii", $shipment_id, $supplier_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $shipment = $result->fetch_assoc();
    $stmt->close();
    
    if ($shipment) {
        // Get shipment items
        $stmt = $conn->prepare("
            SELECT si.*, p.name as product_name, p.sku
            FROM shipment_items si
            JOIN products p ON si.product_id = p.id
            WHERE si.shipment_id = ?
        ");
        $stmt->bind_param("i", $shipment_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
        $stmt->close();
        
        $shipment['items'] = $items;
    }
    
    echo json_encode($shipment ?: ['error' => 'Shipment not found']);
    closeDBConnection($conn);
    exit;
}

// AJAX endpoint: Get route coordinates
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_route_coordinates') {
    header('Content-Type: application/json');
    
    $type = $_GET['type'] ?? '';
    $origin_warehouse_id = $_GET['origin_warehouse_id'] ?? null;
    $dest_warehouse_id = $_GET['dest_warehouse_id'] ?? null;
    $supplier_id_param = $_GET['supplier_id'] ?? null;
    
    // Verify that the supplier_id in the request matches the logged-in supplier
    if ($supplier_id_param && intval($supplier_id_param) != $supplier_id) {
        echo json_encode(['error' => 'Unauthorized access']);
        closeDBConnection($conn);
        exit;
    }
    
    $result = ['origin' => null, 'destination' => null];
    
    // Get origin coordinates (for inbound shipments, origin is the supplier)
    if ($type == 'inbound' && $supplier_id) {
        // For inbound, origin is supplier location
        $stmt = $conn->prepare("SELECT company_name, address FROM suppliers WHERE id = ?");
        $stmt->bind_param("i", $supplier_id);
        $stmt->execute();
        $supplier = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        // For now, use a default location (can implement geocoding later)
        $result['origin'] = [
            'name' => $supplier['company_name'] ?? 'Supplier',
            'lat' => 14.5995, // Default - replace with actual geocoding
            'lng' => 120.9842
        ];
    }
    
    // Get destination coordinates
    if ($dest_warehouse_id) {
        $stmt = $conn->prepare("SELECT name, latitude, longitude FROM warehouses WHERE id = ?");
        $stmt->bind_param("i", $dest_warehouse_id);
        $stmt->execute();
        $warehouse = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($warehouse && $warehouse['latitude'] && $warehouse['longitude']) {
            $result['destination'] = [
                'name' => $warehouse['name'],
                'lat' => (float)$warehouse['latitude'],
                'lng' => (float)$warehouse['longitude']
            ];
        }
    }
    
    echo json_encode($result);
    closeDBConnection($conn);
    exit;
}

closeDBConnection($conn);

$pageTitle = 'My Shipments - Logistics';
include '../includes/header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-shipping-fast"></i> My Shipments - <?php echo htmlspecialchars($supplier_info['company_name'] ?? ''); ?></h2>
    <?php if (!empty($purchase_orders)): ?>
    <button class="btn btn-primary" onclick="openScheduleShipmentModal(0)">
        <i class="fas fa-plus"></i> Schedule New Shipment
    </button>
    <?php endif; ?>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- Shipments Dashboard -->
<div class="stats-grid" style="margin-bottom: 2rem;">
    <div class="stat-card">
        <div class="stat-icon purple">
            <i class="fas fa-shipping-fast"></i>
        </div>
        <div class="stat-info">
            <h3><?php echo $shipment_stats['active']; ?></h3>
            <p>Active Shipments</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="fas fa-truck"></i>
        </div>
        <div class="stat-info">
            <h3><?php echo $shipment_stats['in_transit']; ?></h3>
            <p>In Transit</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="fas fa-check-circle"></i>
        </div>
        <div class="stat-info">
            <h3><?php echo $shipment_stats['delivered_today']; ?></h3>
            <p>Delivered Today</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon orange">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="stat-info">
            <h3><?php echo $shipment_stats['delayed']; ?></h3>
            <p>Delayed Shipments</p>
        </div>
    </div>
</div>

<?php if (!empty($purchase_orders)): ?>
<div class="dashboard-card" style="margin-bottom: 2rem;">
    <div class="card-header">
        <h3><i class="fas fa-shopping-cart"></i> Approved Purchase Orders Ready for Shipment Scheduling</h3>
    </div>
    <div class="card-body">
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>PO Number</th>
                        <th>Order Date</th>
                        <th>Expected Date</th>
                        <th>Amount</th>
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
                            <td><?php echo htmlspecialchars($po['created_by_name']); ?></td>
                            <td>
                                <button class="btn btn-sm btn-primary" onclick="openScheduleShipmentModal(<?php echo $po['id']; ?>)">
                                    <i class="fas fa-shipping-fast"></i> Schedule Shipment
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="filters-bar">
    <div class="filter-group">
        <label>Filter by Status</label>
        <select class="form-control" onchange="window.location.href='?status=' + this.value">
            <option value="">All Statuses</option>
            <option value="scheduled" <?php echo $status_filter == 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
            <option value="in_transit" <?php echo $status_filter == 'in_transit' ? 'selected' : ''; ?>>In Transit</option>
            <option value="delivered" <?php echo $status_filter == 'delivered' ? 'selected' : ''; ?>>Delivered</option>
            <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
        </select>
    </div>
</div>

<div class="dashboard-card">
    <div class="card-header">
        <h3><i class="fas fa-list"></i> My Shipments</h3>
    </div>
    <div class="card-body">
        <?php if (empty($shipments)): ?>
            <p class="text-muted">No shipments found. Shipments you schedule will appear here.</p>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Shipment #</th>
                            <th>PO Number</th>
                            <th>Origin</th>
                            <th>Destination</th>
                            <th>Scheduled Date</th>
                            <th>Tracking #</th>
                            <th>Vehicle</th>
                            <th>Driver</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($shipments as $shipment): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($shipment['shipment_number']); ?></strong></td>
                                <td><?php echo $shipment['po_number'] ?? 'N/A'; ?></td>
                                <td><?php 
                                    // For inbound shipments, origin is the supplier
                                    if (empty($shipment['origin_warehouse'])) {
                                        echo htmlspecialchars($supplier_info['company_name'] ?? 'N/A');
                                    } else {
                                        echo $shipment['origin_warehouse'] ?? 'N/A';
                                    }
                                ?></td>
                                <td><?php echo $shipment['destination_warehouse'] ?? 'N/A'; ?></td>
                                <td><?php echo formatDate($shipment['scheduled_date']); ?></td>
                                <td><?php 
                                    $tracking = $shipment['tracking_number'] ?? null;
                                    if (empty($tracking) || $tracking === '0' || $tracking === 'N/A') {
                                        echo 'N/A';
                                    } else {
                                        echo htmlspecialchars($tracking);
                                    }
                                ?></td>
                                <td><?php 
                                    if (!empty($shipment['vehicle_number'])) {
                                        echo htmlspecialchars($shipment['vehicle_number']);
                                        if (!empty($shipment['vehicle_type'])) {
                                            echo ' (' . htmlspecialchars($shipment['vehicle_type']) . ')';
                                        }
                                    } else {
                                        echo 'N/A';
                                    }
                                ?></td>
                                <td><?php 
                                    if (!empty($shipment['driver_name'])) {
                                        echo htmlspecialchars($shipment['driver_name']);
                                    } else {
                                        echo 'N/A';
                                    }
                                ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $shipment['status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $shipment['status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-secondary" onclick="viewShipmentDetails(<?php echo $shipment['id']; ?>)">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Shipment Details Modal -->
<div id="shipmentDetailsModal" class="modal">
    <div class="modal-content" style="max-width: 900px;">
        <div class="modal-header">
            <h3><i class="fas fa-shipping-fast"></i> Shipment Details</h3>
            <button class="modal-close" onclick="closeModal('shipmentDetailsModal')">&times;</button>
        </div>
        <div class="card-body" id="shipmentDetailsContent">
            <p class="text-muted">Loading...</p>
        </div>
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
                <div id="poSelectorGroup" style="margin-bottom: 20px;">
                    <label for="po_select">Select Purchase Order *</label>
                    <select id="po_select" class="form-control" onchange="loadPODetails(this.value)" required>
                        <option value="">-- Select Purchase Order --</option>
                        <?php foreach ($purchase_orders as $po): ?>
                            <option value="<?php echo $po['id']; ?>">
                                <?php echo htmlspecialchars($po['po_number']); ?> - 
                                <?php echo formatCurrency($po['total_amount']); ?>
                                (<?php echo formatDate($po['order_date']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <h4 style="margin-bottom: 20px;">Purchase Order Details</h4>
                <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px;" id="poDetailsDisplay">
                    <p class="text-muted">Select a purchase order above to view details.</p>
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
// Handle form submission - ensure po_id is synced
document.getElementById('scheduleShipmentForm')?.addEventListener('submit', function(e) {
    const poSelect = document.getElementById('po_select');
    const poIdHidden = document.getElementById('scheduleShipmentPOId');
    if (poSelect && poIdHidden && poSelect.value) {
        poIdHidden.value = poSelect.value;
    }
    if (!poIdHidden.value || poIdHidden.value === '') {
        e.preventDefault();
        alert('Please select a purchase order to schedule a shipment.');
        return false;
    }
});
function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return String(text).replace(/[&<>"']/g, m => map[m]);
}

function viewShipmentDetails(shipmentId) {
    const modal = document.getElementById('shipmentDetailsModal');
    const content = document.getElementById('shipmentDetailsContent');
    
    content.innerHTML = '<p class="text-muted">Loading...</p>';
    openModal('shipmentDetailsModal');
    
    fetch('?ajax=get_shipment_details&shipment_id=' + shipmentId)
        .then(response => response.json())
        .then(data => {
            if (!data || data.error) {
                content.innerHTML = '<p class="text-danger">Shipment not found or you do not have access to view it.</p>';
                return;
            }
            
            let html = `
                <div class="shipment-details">
                    <!-- Tabs Navigation -->
                    <div style="border-bottom: 2px solid #ddd; margin-bottom: 1.5rem;">
                        <button class="shipment-tab-btn active" onclick="switchShipmentTab(${data.id}, 'details')" id="detailsTab_${data.id}" style="padding: 0.75rem 1.5rem; background: none; border: none; border-bottom: 3px solid #F2ACB9; cursor: pointer; font-weight: 600; color: #F2ACB9; margin-right: 0.5rem;">
                            <i class="fas fa-info-circle"></i> Shipment Details
                        </button>
                        ${(data.origin_warehouse_id || data.destination_warehouse_id || data.supplier_id) ? `
                        <button class="shipment-tab-btn" onclick="switchShipmentTab(${data.id}, 'route')" id="routeTab_${data.id}" style="padding: 0.75rem 1.5rem; background: none; border: none; border-bottom: 3px solid transparent; cursor: pointer; font-weight: 500; color: #64748b; margin-right: 0.5rem;">
                            <i class="fas fa-route"></i> Map Routes
                        </button>
                        ` : ''}
                    </div>
                    
                    <!-- Shipment Details Tab Content -->
                    <div id="detailsTabContent_${data.id}" class="shipment-tab-content">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                            <div>
                                <strong>Shipment Number:</strong><br>
                                <span class="badge badge-primary">${escapeHtml(data.shipment_number)}</span>
                            </div>
                            <div>
                                <strong>Status:</strong><br>
                                <span class="badge badge-${data.status}">${escapeHtml(data.status.replace('_', ' '))}</span>
                            </div>
                            <div>
                                <strong>Tracking Number:</strong><br>
                                ${data.tracking_number && data.tracking_number !== '0' && data.tracking_number !== 'N/A' ? escapeHtml(data.tracking_number) : 'N/A'}
                            </div>
                            ${data.type === 'inbound' ? `
                            <div>
                                <strong>PO Number:</strong><br>
                                ${escapeHtml(data.po_number || 'N/A')}
                            </div>
                            ` : ''}
                        </div>
                        
                        <div style="margin-bottom: 1.5rem;">
                            <h4>Route Information</h4>
                            <table class="data-table">
                                <tr>
                                    <th>Origin</th>
                                    <td>${(data.type === 'inbound' && !data.origin_warehouse) ? (escapeHtml(data.supplier_name || 'Supplier Location')) : (escapeHtml(data.origin_warehouse || 'N/A'))}</td>
                                </tr>
                                <tr>
                                    <th>Destination</th>
                                    <td>${escapeHtml(data.destination_warehouse || 'N/A')}</td>
                                </tr>
                                ${data.type === 'inbound' ? `
                                <tr>
                                    <th>Supplier</th>
                                    <td>${escapeHtml(data.supplier_name || 'N/A')}</td>
                                </tr>
                                ` : ''}
                            </table>
                        </div>
                        
                        ${(data.vehicle_number || data.driver_name) ? `
                        <div style="margin-bottom: 1.5rem;">
                            <h4>Transportation</h4>
                            <table class="data-table">
                                ${data.vehicle_number ? `
                                <tr>
                                    <th>Vehicle</th>
                                    <td>${escapeHtml(data.vehicle_number)} - ${escapeHtml(data.vehicle_type || '')} ${data.vehicle_make && data.vehicle_model ? '(' + escapeHtml(data.vehicle_make + ' ' + data.vehicle_model) + ')' : ''}</td>
                                </tr>
                                ` : ''}
                                ${data.driver_name ? `
                                <tr>
                                    <th>Driver</th>
                                    <td>${escapeHtml(data.driver_name)} (${escapeHtml(data.driver_code)}) ${data.driver_phone ? '- ' + escapeHtml(data.driver_phone) : ''}</td>
                                </tr>
                                ` : ''}
                            </table>
                        </div>
                        ` : ''}
                        
                        <div style="margin-bottom: 1.5rem;">
                            <h4>Schedule</h4>
                            <table class="data-table">
                                <tr>
                                    <th>Scheduled Date</th>
                                    <td>${formatDate(data.scheduled_date)}</td>
                                </tr>
                                <tr>
                                    <th>Delivery Date</th>
                                    <td>${data.delivery_date ? formatDate(data.delivery_date) : 'Not yet delivered'}</td>
                                </tr>
                            </table>
                        </div>
                        
                        ${data.transport_cost > 0 ? `
                        <div style="margin-bottom: 1.5rem;">
                            <h4>Cost</h4>
                            <p><strong>Transport Cost:</strong> ${formatCurrency(data.transport_cost)}</p>
                        </div>
                        ` : ''}
                        
                        <div style="margin-bottom: 1.5rem;">
                            <h4>Items (${data.items ? data.items.length : 0})</h4>
                            ${data.items && data.items.length > 0 ? `
                            <div style="overflow-x: auto;">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th>SKU</th>
                                            <th>Quantity</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${data.items.map(item => `
                                            <tr>
                                                <td>${escapeHtml(item.product_name)}</td>
                                                <td>${escapeHtml(item.sku)}</td>
                                                <td>${item.quantity}</td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                            ` : '<p class="text-muted">No items found.</p>'}
                        </div>
                    </div>
                    
                    ${(data.origin_warehouse_id || data.destination_warehouse_id || data.supplier_id) ? `
                    <div id="routeTabContent_${data.id}" class="shipment-tab-content" style="display: none;">
                        <div style="display: flex; justify-content: flex-end; margin-bottom: 1rem;">
                            <button type="button" class="btn btn-primary btn-sm" onclick="loadRouteInDetails(${data.id}, '${data.type}', ${data.origin_warehouse_id || 'null'}, ${data.destination_warehouse_id || 'null'}, ${data.supplier_id || 'null'})">
                                <i class="fas fa-sync-alt"></i> Refresh Route
                            </button>
                        </div>
                        <div id="shipmentRouteMap_${data.id}" style="height: 350px; width: 100%; margin-bottom: 1rem; border: 1px solid #ddd; border-radius: 4px; background: #f5f5f5;"></div>
                        <div id="routeInfo_${data.id}">
                            <div class="stats-grid" style="grid-template-columns: repeat(2, 1fr); margin-bottom: 0;">
                                <div class="stat-card">
                                    <div class="stat-icon blue">
                                        <i class="fas fa-route"></i>
                                    </div>
                                    <div class="stat-info">
                                        <h3 id="routeDistance_${data.id}">Calculating...</h3>
                                        <p>Distance</p>
                                    </div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-icon green">
                                        <i class="fas fa-clock"></i>
                                    </div>
                                    <div class="stat-info">
                                        <h3 id="routeETA_${data.id}">Calculating...</h3>
                                        <p>Estimated Time</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    ` : ''}
                </div>
            `;
            
            content.innerHTML = html;
            
            // Store shipment data for route planning
            window.currentShipmentData = data;
            
            // Don't auto-load maps - they will load when their tabs are clicked
        })
        .catch(error => {
            console.error('Error:', error);
            content.innerHTML = '<p class="text-danger">Error loading shipment details.</p>';
        });
}

// Switch between shipment detail tabs
window.switchShipmentTab = function(shipmentId, tab) {
    // Get all tab buttons and contents
    const detailsTab = document.getElementById(`detailsTab_${shipmentId}`);
    const routeTab = document.getElementById(`routeTab_${shipmentId}`);
    const detailsContent = document.getElementById(`detailsTabContent_${shipmentId}`);
    const routeContent = document.getElementById(`routeTabContent_${shipmentId}`);
    
    // Reset all tabs
    [detailsTab, routeTab].forEach(btn => {
        if (btn) {
            btn.classList.remove('active');
            btn.style.borderBottom = '3px solid transparent';
            btn.style.fontWeight = '500';
            btn.style.color = '#64748b';
        }
    });
    
    // Hide all tab contents
    [detailsContent, routeContent].forEach(content => {
        if (content) content.style.display = 'none';
    });
    
    // Activate selected tab
    if (tab === 'details' && detailsTab && detailsContent) {
        detailsTab.classList.add('active');
        detailsTab.style.borderBottom = '3px solid #F2ACB9';
        detailsTab.style.fontWeight = '600';
        detailsTab.style.color = '#F2ACB9';
        detailsContent.style.display = 'block';
    } else if (tab === 'route' && routeTab && routeContent) {
        routeTab.classList.add('active');
        routeTab.style.borderBottom = '3px solid #F2ACB9';
        routeTab.style.fontWeight = '600';
        routeTab.style.color = '#F2ACB9';
        routeContent.style.display = 'block';
        
        // Load route map if not already loaded
        setTimeout(() => {
            const shipmentData = window.currentShipmentData || {};
            const mapContainer = document.getElementById(`shipmentRouteMap_${shipmentId}`);
            if (mapContainer && (!window.mapInstances || !window.mapInstances[`shipmentRouteMap_${shipmentId}`])) {
                if (shipmentData.origin_warehouse_id || shipmentData.destination_warehouse_id || shipmentData.supplier_id) {
                    loadRouteInDetails(
                        shipmentId,
                        shipmentData.type || '',
                        shipmentData.origin_warehouse_id || null,
                        shipmentData.destination_warehouse_id || null,
                        shipmentData.supplier_id || null
                    );
                }
            } else if (window.mapInstances && window.mapInstances[`shipmentRouteMap_${shipmentId}`]) {
                // Resize existing map
                window.mapInstances[`shipmentRouteMap_${shipmentId}`].invalidateSize();
            }
        }, 100);
    }
};

// Load route in shipment details modal
function loadRouteInDetails(shipmentId, type, originWarehouseId, destWarehouseId, supplierId) {
    const mapContainer = document.getElementById(`shipmentRouteMap_${shipmentId}`);
    const routeInfo = document.getElementById(`routeInfo_${shipmentId}`);
    
    if (!mapContainer) return;
    
    // Show loading state
    if (routeInfo) {
        document.getElementById(`routeDistance_${shipmentId}`).textContent = 'Calculating...';
        document.getElementById(`routeETA_${shipmentId}`).textContent = 'Calculating...';
    }
    
    // Clear existing map instance for this container
    const containerId = `shipmentRouteMap_${shipmentId}`;
    if (window.mapInstances && window.mapInstances[containerId]) {
        window.mapInstances[containerId].remove();
        delete window.mapInstances[containerId];
    }
    
    // Initialize map - use setTimeout to ensure container is visible
    setTimeout(() => {
        initRouteMap(containerId);
        
        // Fetch and draw route
        const params = new URLSearchParams({
            ajax: 'get_route_coordinates',
            type: type,
            origin_warehouse_id: originWarehouseId || '',
            dest_warehouse_id: destWarehouseId || '',
            supplier_id: supplierId || ''
        });
        
        fetch('?' + params.toString())
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    alert('Error: ' + data.error);
                    document.getElementById(`routeDistance_${shipmentId}`).textContent = 'Error';
                    document.getElementById(`routeETA_${shipmentId}`).textContent = 'Error';
                    return;
                }
                
                // Add markers
                if (data.origin && data.origin.lat && data.origin.lng) {
                    addMarker(data.origin.lat, data.origin.lng, data.origin.name, true);
                }
                
                if (data.destination && data.destination.lat && data.destination.lng) {
                    addMarker(data.destination.lat, data.destination.lng, data.destination.name, false);
                }
                
                // Draw route if both points exist
                if (data.origin && data.origin.lat && data.destination && data.destination.lat) {
                    drawRoute(
                        data.origin.lat, data.origin.lng,
                        data.destination.lat, data.destination.lng
                    ).then(routeData => {
                        // Update route info with actual OSRM data (accurate time based on distance)
                        document.getElementById(`routeDistance_${shipmentId}`).textContent = routeData.distance.toFixed(2) + ' km';
                        document.getElementById(`routeETA_${shipmentId}`).textContent = routeData.duration + ' min';
                    }).catch(error => {
                        console.error('Error drawing route:', error);
                        document.getElementById(`routeDistance_${shipmentId}`).textContent = 'Error';
                        document.getElementById(`routeETA_${shipmentId}`).textContent = 'Error';
                    });
                } else {
                    alert('Missing coordinates. Please add latitude/longitude to warehouses.');
                    document.getElementById(`routeDistance_${shipmentId}`).textContent = 'N/A';
                    document.getElementById(`routeETA_${shipmentId}`).textContent = 'N/A';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error loading route coordinates.');
                document.getElementById(`routeDistance_${shipmentId}`).textContent = 'Error';
                document.getElementById(`routeETA_${shipmentId}`).textContent = 'Error';
            });
    }, 100);
}

function formatDate(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

function formatCurrency(amount) {
    return '₱' + parseFloat(amount).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
}

function openModal(modalId) {
    document.getElementById(modalId).style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

function openScheduleShipmentModal(poId) {
    // Reset form
    document.getElementById('scheduleShipmentForm').reset();
    document.getElementById('scheduleShipmentPOId').value = '';
    document.getElementById('poDetailsDisplay').innerHTML = '<p class="text-muted">Select a purchase order above to view details.</p>';
    
    // Ensure destination warehouse field is enabled and editable
    const destinationWarehouseSelect = document.getElementById('destination_warehouse_id');
    if (destinationWarehouseSelect) {
        destinationWarehouseSelect.disabled = false;
        destinationWarehouseSelect.readOnly = false;
        destinationWarehouseSelect.removeAttribute('disabled');
        destinationWarehouseSelect.removeAttribute('readonly');
    }
    
    // If poId is provided, set it and load details
    if (poId && poId > 0) {
        document.getElementById('po_select').value = poId;
        document.getElementById('scheduleShipmentPOId').value = poId;
        loadPODetails(poId);
    }
    
    // Set minimum date to today
    const dateInput = document.getElementById('scheduleShipmentDate');
    const today = new Date().toISOString().split('T')[0];
    dateInput.min = today;
    dateInput.value = today;
    
    openModal('scheduleShipmentModal');
}

function loadPODetails(poId) {
    if (!poId || poId === '') {
        document.getElementById('poDetailsDisplay').innerHTML = '<p class="text-muted">Select a purchase order above to view details.</p>';
        document.getElementById('scheduleShipmentPOId').value = '';
        return;
    }
    
    document.getElementById('poDetailsDisplay').innerHTML = '<p class="text-muted">Loading...</p>';
    document.getElementById('scheduleShipmentPOId').value = poId;
    
    fetch('?ajax=get_po_details&po_id=' + poId)
        .then(response => response.json())
        .then(data => {
            if (!data || !data.id || data.error) {
                document.getElementById('poDetailsDisplay').innerHTML = '<p class="text-danger">Error loading purchase order details.</p>';
                return;
            }
            
            let html = '<div style="margin-bottom: 10px;">';
            html += '<strong>PO Number:</strong> ' + data.po_number + '<br>';
            html += '<strong>Supplier:</strong> ' + (data.supplier_name || 'N/A') + '<br>';
            html += '<strong>Order Date:</strong> ' + formatDate(data.order_date) + '<br>';
            html += '<strong>Expected Date:</strong> ' + (data.delivery_date ? formatDate(data.delivery_date) : 'N/A') + '<br>';
            html += '<strong>Total Amount:</strong> ' + formatCurrency(data.total_amount) + '<br>';
            html += '<strong>Created By:</strong> ' + (data.created_by_name || 'N/A');
            html += '</div>';
            
            if (data.items && data.items.length > 0) {
                html += '<div style="margin-top: 15px;"><strong>Items:</strong></div>';
                html += '<div style="overflow-x: auto; margin-top: 10px;">';
                html += '<table class="data-table" style="font-size: 0.9em;">';
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
                
                // Set default scheduled date to expected delivery date if available and not in the past
                if (data.delivery_date) {
                    const expectedDate = new Date(data.delivery_date);
                    const today = new Date();
                    today.setHours(0, 0, 0, 0);
                    const formattedDate = expectedDate.toISOString().split('T')[0];
                    const todayFormatted = today.toISOString().split('T')[0];
                    
                    if (formattedDate >= todayFormatted) {
                        document.getElementById('scheduleShipmentDate').value = formattedDate;
                    } else {
                        document.getElementById('scheduleShipmentDate').value = todayFormatted;
                    }
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
            }
            
            document.getElementById('poDetailsDisplay').innerHTML = html;
        })
        .catch(error => {
            console.error('Error loading PO details:', error);
            document.getElementById('poDetailsDisplay').innerHTML = '<p class="text-danger">Error loading purchase order details.</p>';
        });
}

// Close modal when clicking outside of it
window.onclick = function(event) {
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        if (event.target == modal) {
            modal.style.display = 'none';
        }
    });
}
</script>

<script src="<?php echo BASE_URL; ?>assets/js/logistics_map.js"></script>

<?php include '../includes/footer.php'; ?>

