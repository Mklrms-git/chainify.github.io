<?php
// Start output buffering IMMEDIATELY to catch any output
ob_start();

// Register shutdown function FIRST to catch fatal errors before anything else
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        // Check if this is an AJAX request
        if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
            @ob_clean();
            if (!headers_sent()) {
                @header('Content-Type: application/json');
                http_response_code(500);
            }
            $error_msg = isset($error['message']) ? htmlspecialchars($error['message'], ENT_QUOTES, 'UTF-8') : 'Unknown fatal error';
            $error_json = json_encode(['success' => false, 'error' => 'Fatal error: ' . $error_msg, 'file' => $error['file'], 'line' => $error['line']]);
            echo $error_json;
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            exit;
        }
    }
});

// Handle AJAX requests FIRST, before loading config (which might output)
// Check for AJAX request to update shipment status to in_transit
$is_ajax_in_transit = ($_SERVER['REQUEST_METHOD'] == 'POST' 
    && isset($_POST['ajax']) && $_POST['ajax'] == '1' 
    && isset($_POST['status']) && $_POST['status'] == 'in_transit' 
    && isset($_POST['shipment_id']));

// Check for AJAX request to update location
$is_ajax_update_location = ($_SERVER['REQUEST_METHOD'] == 'POST' 
    && isset($_POST['ajax']) && $_POST['ajax'] == 'update_location');

// Debug: Log if AJAX condition is being checked (only if POST and has ajax param)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax'])) {
    if (!$is_ajax_in_transit && !$is_ajax_update_location) {
        // If ajax param exists but condition doesn't match, send debug info
        @ob_clean();
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code(400);
        }
        $debug_data = [
            'success' => false,
            'error' => 'AJAX condition not met',
            'debug' => [
                'method' => $_SERVER['REQUEST_METHOD'],
                'ajax' => $_POST['ajax'] ?? 'not set',
                'status' => $_POST['status'] ?? 'not set',
                'shipment_id' => $_POST['shipment_id'] ?? 'not set',
                'all_post_keys' => array_keys($_POST)
            ]
        ];
        echo json_encode($debug_data);
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        exit;
    }
}

if ($is_ajax_in_transit) {
    // Enable error reporting for debugging
    error_reporting(E_ALL);
    ini_set('display_errors', 0); // Don't display, but log
    
    // Flag to ensure we exit after handling AJAX
    $ajax_handled = true;
    
    // Function to send JSON error response
    function sendAjaxError($message, $code = 500) {
        $debug_info = [];
        if (isset($GLOBALS['ajax_debug'])) {
            $debug_info = $GLOBALS['ajax_debug'];
        }
        
        @ob_clean(); // Suppress errors if no buffer
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code($code);
        }
        $error_response = ['success' => false, 'error' => $message];
        if (!empty($debug_info)) {
            $error_response['debug'] = $debug_info;
        }
        $error_json = json_encode($error_response);
        echo $error_json;
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        exit;
    }
    
    // Store original error handler
    $original_error_handler = set_error_handler(function($errno, $errstr, $errfile, $errline) {
        // Only handle errors that should be shown
        if (!(error_reporting() & $errno)) {
            return false; // Let PHP handle it
        }
        // Prevent infinite loops - don't handle errors in error handler
        static $handling_error = false;
        if ($handling_error) {
            return false;
        }
        $handling_error = true;
        
        // Inline error response to avoid scope issues
        @ob_clean(); // Suppress errors if no buffer
        if (!headers_sent()) {
            @header('Content-Type: application/json');
            http_response_code(500);
        }
        $error_json = json_encode(['success' => false, 'error' => 'Server error: ' . htmlspecialchars($errstr, ENT_QUOTES, 'UTF-8')]);
        echo $error_json;
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        exit;
    }, E_ALL & ~E_NOTICE); // Handle all errors except notices
    
    // Note: Shutdown function already registered at top of file for early fatal error catching
    
    // Wrap everything in try-catch to catch any exceptions
    try {
        // Store debug info
        $GLOBALS['ajax_debug'] = ['step' => 'starting', 'post' => array_keys($_POST)];
        
        // Now require config (redirects disabled for AJAX in config.php)
        $GLOBALS['ajax_debug']['step'] = 'loading_config';
        require_once '../config/config.php';
        $GLOBALS['ajax_debug']['step'] = 'config_loaded';
        
        // Check authentication and permissions
        if (!isLoggedIn()) {
            sendAjaxError('Not authenticated', 401);
        }
        
        if (!canAccessModule('logistics')) {
            sendAjaxError('Access denied', 403);
        }
        
        if (!canEdit('logistics') && !isAdmin()) {
            sendAjaxError('Permission denied', 403);
        }
        
        $conn = getDBConnection();
        $shipment_id = $_POST['shipment_id'] ?? 0;
        $status = $_POST['status'] ?? '';
        $transit_date = $_POST['transit_date'] ?? null;
        $transit_location_name = trim($_POST['transit_location_name'] ?? '');
        
        // Validate input
        if (!$shipment_id || $status !== 'in_transit') {
            closeDBConnection($conn);
            sendAjaxError('Invalid request', 400);
        }
        
        if (empty($transit_date) || empty($transit_location_name)) {
            closeDBConnection($conn);
            sendAjaxError('Date and location name are required', 400);
        }
        
        // Get shipment details
        $shipment_stmt = $conn->prepare("SELECT shipment_number, type, origin_warehouse_id, destination_warehouse_id, po_id, status as current_status, scheduled_date FROM shipments WHERE id = ?");
        $shipment_stmt->bind_param("i", $shipment_id);
        $shipment_stmt->execute();
        $shipment_result = $shipment_stmt->get_result();
        $shipment_data = $shipment_result->fetch_assoc();
        $shipment_stmt->close();
        
        if (!$shipment_data) {
            closeDBConnection($conn);
            sendAjaxError('Shipment not found', 404);
        }
        
        // Check permissions
        $can_update = false;
        if (canEdit('logistics') || isAdmin()) {
            $can_update = true;
        }
        
        if (!$can_update) {
            closeDBConnection($conn);
            sendAjaxError('You do not have permission to update this shipment status', 403);
        }
        
        // Check if scheduled date has passed
        if ($shipment_data['scheduled_date'] && strtotime($shipment_data['scheduled_date']) < strtotime(date('Y-m-d'))) {
            closeDBConnection($conn);
            sendAjaxError('Cannot update shipment status. The scheduled date (' . $shipment_data['scheduled_date'] . ') has already passed', 400);
        }
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
        // Auto-generate tracking number if missing
        if ($shipment_data['current_status'] != 'in_transit') {
            $check_tracking_stmt = $conn->prepare("SELECT tracking_number FROM shipments WHERE id = ?");
            $check_tracking_stmt->bind_param("i", $shipment_id);
            $check_tracking_stmt->execute();
            $tracking_result = $check_tracking_stmt->get_result();
            $tracking_data = $tracking_result->fetch_assoc();
            $check_tracking_stmt->close();
            
            if (empty($tracking_data['tracking_number']) || 
                $tracking_data['tracking_number'] === '0' || 
                $tracking_data['tracking_number'] === 'N/A') {
                // Generate tracking number
                $prefix = 'TRK';
                $dateStr = date('Ymd');
                $randomStr = strtoupper(substr(uniqid(), -6));
                $new_tracking_number = $prefix . '-' . $dateStr . '-' . $randomStr;
                $update_tracking_stmt = $conn->prepare("UPDATE shipments SET tracking_number = ? WHERE id = ?");
                $update_tracking_stmt->bind_param("si", $new_tracking_number, $shipment_id);
                $update_tracking_stmt->execute();
                $update_tracking_stmt->close();
            }
        }
        
        // Auto-set time to current time
        $current_time = date('H:i');
        $last_location_update = $transit_date . ' ' . $current_time . ':00';
        
        // Update shipment status
        if ($shipment_data['current_status'] != 'in_transit') {
            $stmt = $conn->prepare("UPDATE shipments SET status = ?, last_location_update = ? WHERE id = ?");
            $stmt->bind_param("ssi", $status, $last_location_update, $shipment_id);
        } else {
            $stmt = $conn->prepare("UPDATE shipments SET last_location_update = ? WHERE id = ?");
            $stmt->bind_param("si", $last_location_update, $shipment_id);
        }
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to update shipment status: ' . $stmt->error);
        }
        $stmt->close();
        
        // Insert location entry
        // Use placeholder coordinates (0,0) since we only have location_name, not GPS coordinates
        $placeholder_lat = 0.0;
        $placeholder_lng = 0.0;
        
        $location_stmt = $conn->prepare("INSERT INTO shipment_locations (shipment_id, latitude, longitude, location_name, recorded_at) VALUES (?, ?, ?, ?, ?)");
        if (!$location_stmt) {
            throw new Exception('Failed to prepare location insert: ' . $conn->error);
        }
        
        $location_stmt->bind_param("iddss", $shipment_id, $placeholder_lat, $placeholder_lng, $transit_location_name, $last_location_update);
        
        if (!$location_stmt->execute()) {
            throw new Exception('Failed to save location entry: ' . $location_stmt->error);
        }
        $location_stmt->close();
        
        // If status is changing to 'in_transit' for the first time, update PO and send notifications
        if ($status == 'in_transit' && $shipment_data['current_status'] != 'in_transit' && $shipment_data['po_id']) {
            // Update PO status to 'in_transit' if not already received/cancelled
            $po_update_stmt = $conn->prepare("UPDATE purchase_orders SET status = 'in_transit' WHERE id = ? AND status != 'received' AND status != 'cancelled'");
            $po_update_stmt->bind_param("i", $shipment_data['po_id']);
            $po_update_stmt->execute();
            $po_update_stmt->close();
            
            // Get PO details for notifications
            $po_notif_stmt = $conn->prepare("SELECT po_number, supplier_id, created_by FROM purchase_orders WHERE id = ?");
            $po_notif_stmt->bind_param("i", $shipment_data['po_id']);
            $po_notif_stmt->execute();
            $po_notif_result = $po_notif_stmt->get_result();
            $po_notif_data = $po_notif_result->fetch_assoc();
            $po_notif_stmt->close();
            
            if ($po_notif_data) {
                $po_number = $po_notif_data['po_number'];
                $shipment_number = $shipment_data['shipment_number'] ?? 'N/A';
                
                // Notify Admin users (wrap in try-catch so notifications don't break the update)
                try {
                    notifyUsersByRole(
                        'admin',
                        'shipment_in_transit',
                        'Shipment In Transit',
                        "Shipment {$shipment_number} for Purchase Order {$po_number} is now IN TRANSIT. Location: {$transit_location_name}. Date: " . date('M d, Y', strtotime($transit_date)),
                        'shipment',
                        $shipment_id
                    );
                } catch (Exception $e) {
                    // Log error but don't fail the update
                    error_log('Failed to notify admin users: ' . $e->getMessage());
                }
                
                // Notify Supplier (wrap in try-catch so notifications don't break the update)
                if ($po_notif_data['supplier_id']) {
                    try {
                        notifySupplier(
                            $po_notif_data['supplier_id'],
                            'shipment_in_transit',
                            'Shipment In Transit',
                            "Shipment {$shipment_number} for Purchase Order {$po_number} is now IN TRANSIT. Location: {$transit_location_name}. Date: " . date('M d, Y', strtotime($transit_date)),
                            'shipment',
                            $shipment_id
                        );
                    } catch (Exception $e) {
                        // Log error but don't fail the update
                        error_log('Failed to notify supplier: ' . $e->getMessage());
                    }
                }
                
                // Notify Procurement staff who created the PO (wrap in try-catch so notifications don't break the update)
                if ($po_notif_data['created_by']) {
                    try {
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
                                'shipment_in_transit',
                                'Shipment In Transit',
                                "Shipment {$shipment_number} for Purchase Order {$po_number} is now IN TRANSIT. Location: {$transit_location_name}. Date: " . date('M d, Y', strtotime($transit_date)),
                                'shipment',
                                $shipment_id
                            );
                        }
                    } catch (Exception $e) {
                        // Log error but don't fail the update
                        error_log('Failed to notify procurement staff: ' . $e->getMessage());
                    }
                }
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        // Return JSON response
        $GLOBALS['ajax_debug']['step'] = 'sending_response';
        @ob_clean(); // Suppress errors if no buffer
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode([
            'success' => true,
            'message' => 'Location updated successfully!',
            'location_name' => $transit_location_name,
            'date' => $transit_date,
            'time' => $current_time
        ]);
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        if (isset($conn)) {
            closeDBConnection($conn);
        }
        if (isset($original_error_handler) && $original_error_handler !== null) {
            restore_error_handler();
        }
        // Clear debug info
        unset($GLOBALS['ajax_debug']);
        exit; // Ensure we exit
        
        } catch (Exception $e) {
            // Rollback on error
            if (isset($conn)) {
                @$conn->rollback();
                closeDBConnection($conn);
            }
            if (isset($original_error_handler) && $original_error_handler !== null) {
                restore_error_handler();
            }
            sendAjaxError($e->getMessage());
        } catch (Throwable $e) {
            // Catch any other throwable (including fatal errors)
            if (isset($conn)) {
                @$conn->rollback();
                closeDBConnection($conn);
            }
            if (isset($original_error_handler) && $original_error_handler !== null) {
                restore_error_handler();
            }
            sendAjaxError('Server error: ' . $e->getMessage());
        }
    } catch (Exception $e) {
        // Catch any exceptions during initialization
        if (isset($original_error_handler) && $original_error_handler !== null) {
            restore_error_handler();
        }
        sendAjaxError('Initialization error: ' . $e->getMessage());
    } catch (Throwable $e) {
        // Catch any other throwable
        if (isset($original_error_handler) && $original_error_handler !== null) {
            restore_error_handler();
        }
        sendAjaxError('Server error: ' . $e->getMessage());
    }
    
    // Safety check: If AJAX was handled, ensure we don't continue
    if (isset($ajax_handled) && $ajax_handled) {
        // This should never be reached if exit works properly
        @ob_clean();
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code(500);
        }
        echo json_encode(['success' => false, 'error' => 'AJAX handler failed to exit properly', 'debug' => 'Reached safety exit']);
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        die(); // Use die() instead of exit() for extra safety
    }
}

// Additional safety: If this is an AJAX request but we got here, something is wrong
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    @ob_clean();
    if (!headers_sent()) {
        header('Content-Type: application/json');
        http_response_code(500);
    }
    echo json_encode([
        'success' => false, 
        'error' => 'AJAX request detected but handler did not process it',
        'debug' => [
            'status' => $_POST['status'] ?? 'not set',
            'shipment_id' => $_POST['shipment_id'] ?? 'not set',
            'all_post' => array_keys($_POST)
        ]
    ]);
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    die();
}

// Continue with normal page load (clear buffer first)
ob_end_clean();
require_once '../config/config.php';
requireModuleAccess('logistics');

$conn = getDBConnection();
$message = '';
$error = '';

/**
 * Generate a tracking number
 * 
 * @return string Generated tracking number in format TRK-YYYYMMDD-XXXXXX
 */
function generateTrackingNumber() {
    $prefix = 'TRK';
    $dateStr = date('Ymd');
    $randomStr = strtoupper(substr(uniqid(), -6));
    return $prefix . '-' . $dateStr . '-' . $randomStr;
}

/**
 * Update inventory when shipment status changes to 'delivered'
 * 
 * For inbound shipments: Adds inventory to the destination warehouse
 * 
 * @param mysqli $conn Database connection
 * @param int $shipment_id The shipment ID
 * @param array $shipment_data Shipment data containing type, origin_warehouse_id, destination_warehouse_id, po_id
 * @return bool True on success, false on failure
 * @throws Exception If inventory update fails
 */
function updateInventoryOnShipmentDelivery($conn, $shipment_id, $shipment_data) {
    // Check if shipment is linked to a PO - if so, verify both statuses match before updating inventory
    if (!empty($shipment_data['po_id'])) {
        // Get PO status
        $po_status_stmt = $conn->prepare("SELECT status FROM purchase_orders WHERE id = ?");
        $po_status_stmt->bind_param("i", $shipment_data['po_id']);
        $po_status_stmt->execute();
        $po_status_result = $po_status_stmt->get_result();
        $po_status_data = $po_status_result->fetch_assoc();
        $po_status_stmt->close();
        
        // Get shipment status
        $shipment_status_stmt = $conn->prepare("SELECT status FROM shipments WHERE id = ?");
        $shipment_status_stmt->bind_param("i", $shipment_id);
        $shipment_status_stmt->execute();
        $shipment_status_result = $shipment_status_stmt->get_result();
        $shipment_status_data = $shipment_status_result->fetch_assoc();
        $shipment_status_stmt->close();
        
        // Only update inventory if BOTH statuses match: shipment='delivered' AND PO='received'
        if (!$po_status_data || $po_status_data['status'] !== 'received') {
            throw new Exception('Inventory will not be updated. Purchase Order status must be "received" and shipment status must be "delivered" to update inventory.');
        }
        
        if (!$shipment_status_data || $shipment_status_data['status'] !== 'delivered') {
            throw new Exception('Inventory will not be updated. Shipment status must be "delivered" and Purchase Order status must be "received" to update inventory.');
        }
    }
    
    // Determine which items to use: Purchase Order items if shipment is linked to a PO, otherwise shipment items
    $items_result = null;
    $items_stmt = null;
    
    if (!empty($shipment_data['po_id'])) {
        // Get items from purchase order
        $items_stmt = $conn->prepare("SELECT product_id, quantity FROM purchase_order_items WHERE po_id = ?");
        if (!$items_stmt) {
            throw new Exception('Failed to prepare purchase order items query: ' . $conn->error);
        }
        $items_stmt->bind_param("i", $shipment_data['po_id']);
        if (!$items_stmt->execute()) {
            $items_stmt->close();
            throw new Exception('Failed to execute purchase order items query: ' . $items_stmt->error);
        }
        $items_result = $items_stmt->get_result();
    } else {
        // Get items from shipment
        $items_stmt = $conn->prepare("SELECT product_id, quantity FROM shipment_items WHERE shipment_id = ?");
        if (!$items_stmt) {
            throw new Exception('Failed to prepare shipment items query: ' . $conn->error);
        }
        $items_stmt->bind_param("i", $shipment_id);
        if (!$items_stmt->execute()) {
            $items_stmt->close();
            throw new Exception('Failed to execute shipment items query: ' . $items_stmt->error);
        }
        $items_result = $items_stmt->get_result();
    }
    
    // Check if any items were found
    if (!$items_result || $items_result->num_rows == 0) {
        $items_stmt->close();
        $source = !empty($shipment_data['po_id']) ? 'purchase order' : 'shipment';
        throw new Exception('No items found for ' . $source . ' (ID: ' . (!empty($shipment_data['po_id']) ? $shipment_data['po_id'] : $shipment_id) . ')');
    }
    
    // Only inbound shipments are supported
    // Inbound: Add to destination warehouse
    $warehouse_id = $shipment_data['destination_warehouse_id'];
    $movement_type = 'in';
    
    if (!$warehouse_id) {
        $items_stmt->close();
        throw new Exception('Destination warehouse ID is missing for inbound shipment');
    }
    
    $items_processed = 0;
    while ($item = $items_result->fetch_assoc()) {
        $items_processed++;
        $product_id = $item['product_id'];
        $quantity = $item['quantity'];
        
        // Add to inventory (inbound shipments only)
        $check_stmt = $conn->prepare("SELECT id, quantity FROM inventory WHERE product_id = ? AND warehouse_id = ?");
        $check_stmt->bind_param("ii", $product_id, $warehouse_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $existing = $check_result->fetch_assoc();
        $check_stmt->close();
        
        if ($existing) {
            $update_stmt = $conn->prepare("UPDATE inventory SET quantity = quantity + ? WHERE product_id = ? AND warehouse_id = ?");
            $update_stmt->bind_param("iii", $quantity, $product_id, $warehouse_id);
            if (!$update_stmt->execute()) {
                $update_stmt->close();
                $items_stmt->close();
                throw new Exception('Failed to update inventory for product ID: ' . $product_id);
            }
            $update_stmt->close();
        } else {
            $insert_stmt = $conn->prepare("INSERT INTO inventory (product_id, warehouse_id, quantity) VALUES (?, ?, ?)");
            $insert_stmt->bind_param("iii", $product_id, $warehouse_id, $quantity);
            if (!$insert_stmt->execute()) {
                $insert_stmt->close();
                $items_stmt->close();
                throw new Exception('Failed to insert inventory for product ID: ' . $product_id);
            }
            $insert_stmt->close();
        }
        
        // Create stock movement record
        $movement_notes = "Shipment delivery - Inbound";
        if (!empty($shipment_data['po_id'])) {
            $movement_notes .= " (from Purchase Order)";
        }
        $movement_stmt = $conn->prepare("INSERT INTO stock_movements (product_id, warehouse_id, movement_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?, ?, ?, ?, 'shipment', ?, ?, ?)");
        $movement_stmt->bind_param("iisissi", $product_id, $warehouse_id, $movement_type, $quantity, $shipment_id, $movement_notes, $_SESSION['user_id']);
        if (!$movement_stmt->execute()) {
            $movement_stmt->close();
            $items_stmt->close();
            throw new Exception('Failed to record stock movement for product ID: ' . $product_id);
        }
        $movement_stmt->close();
    }
    
    $items_stmt->close();
    
    // Verify that items were actually processed
    if ($items_processed == 0) {
        throw new Exception('No items were processed for inventory update');
    }
    
    return true;
}

// Create Shipment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_shipment'])) {
    requireCreatePermission('logistics');
    $shipment_number = 'SHIP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
    $po_id = $_POST['po_id'] ?? null;
    $transfer_id = $_POST['transfer_id'] ?? null;
    $destination_warehouse_id = $_POST['destination_warehouse_id'] ?? null;
    $supplier_id = $_POST['supplier_id'] ?? null;
    $scheduled_date = $_POST['scheduled_date'] ?? '';
    $tracking_number = $_POST['tracking_number'] ?? '';
    $transport_cost = $_POST['transport_cost'] ?? 0;
    $vehicle_id = $_POST['vehicle_id'] ?? null;
    $driver_id = $_POST['driver_id'] ?? null;
    $items = $_POST['items'] ?? [];
    
    // Origin warehouse is no longer set from form, always null for new shipments
    $origin_warehouse_id = null;
    
    // Determine shipment type and populate data based on source
    $type = 'inbound';
    if ($transfer_id && $transfer_id !== '' && $transfer_id !== '0') {
        // Creating shipment from transfer request
        $type = 'transfer';
        
        // Get transfer details
        $transfer_stmt = $conn->prepare("
            SELECT wt.*, w1.name as source_warehouse_name, w2.name as dest_warehouse_name
            FROM warehouse_transfers wt
            JOIN warehouses w1 ON wt.source_warehouse_id = w1.id
            JOIN warehouses w2 ON wt.destination_warehouse_id = w2.id
            WHERE wt.id = ? AND wt.status = 'approved'
        ");
        $transfer_stmt->bind_param("i", $transfer_id);
        $transfer_stmt->execute();
        $transfer_result = $transfer_stmt->get_result();
        $transfer_data = $transfer_result->fetch_assoc();
        $transfer_stmt->close();
        
        if (!$transfer_data) {
            $error = 'Transfer request not found or not approved.';
        } else {
            // Populate shipment data from transfer
            $origin_warehouse_id = $transfer_data['source_warehouse_id'];
            $destination_warehouse_id = $transfer_data['destination_warehouse_id'];
            
            // Get transfer items
            $transfer_items_stmt = $conn->prepare("SELECT product_id, quantity FROM transfer_items WHERE transfer_id = ?");
            $transfer_items_stmt->bind_param("i", $transfer_id);
            $transfer_items_stmt->execute();
            $transfer_items_result = $transfer_items_stmt->get_result();
            $items = [];
            while ($row = $transfer_items_result->fetch_assoc()) {
                $items[] = $row;
            }
            $transfer_items_stmt->close();
            
            // Use requested_date as scheduled_date if not provided
            if (empty($scheduled_date)) {
                $scheduled_date = $transfer_data['requested_date'];
            }
        }
    }
    
    // Convert empty strings to null first to check properly
    $temp_po_id = ($po_id === '' || $po_id === 0) ? null : (int)$po_id;
    $temp_transfer_id = ($transfer_id === '' || $transfer_id === 0) ? null : (int)$transfer_id;
    $temp_destination_warehouse_id = ($destination_warehouse_id === '' || $destination_warehouse_id === 0) ? null : (int)$destination_warehouse_id;
    $temp_supplier_id = ($supplier_id === '' || $supplier_id === 0) ? null : (int)$supplier_id;
    
    // Validate scheduled date is not in the past
    if (empty($error) && $scheduled_date && $scheduled_date < date('Y-m-d')) {
        $error = 'Scheduled date cannot be in the past. Please select today\'s date or a future date.';
    } elseif (empty($error) && $scheduled_date && !empty($items)) {
        // Convert empty strings to null for nullable fields
        $po_id = $temp_po_id;
        $transfer_id = $temp_transfer_id;
        $destination_warehouse_id = $temp_destination_warehouse_id;
        $supplier_id = $temp_supplier_id;
        $vehicle_id = ($vehicle_id === '' || $vehicle_id === 0) ? null : (int)$vehicle_id;
        $driver_id = ($driver_id === '' || $driver_id === 0) ? null : (int)$driver_id;
        $origin_warehouse_id = ($origin_warehouse_id === '' || $origin_warehouse_id === 0) ? null : (int)$origin_warehouse_id;
        
        // Auto-generate tracking number if missing or invalid
        if (empty($tracking_number) || $tracking_number === '0' || $tracking_number === 'N/A') {
            $tracking_number = generateTrackingNumber();
        }
        
        // Cast transport_cost to float for bind_param type 'd'
        $transport_cost = (float)($transport_cost ?? 0);
        
        // transfer_id is already cast to int or null on line 659, use it directly
        // $transfer_id_for_insert = $transfer_id; // Already set on line 659
        
        // Prepare INSERT statement - make sure column count matches placeholder count
        // Columns: shipment_number, po_id, transfer_id, type, origin_warehouse_id, destination_warehouse_id, 
        //          supplier_id, vehicle_id, driver_id, scheduled_date, tracking_number, transport_cost, created_by
        // Total: 13 columns, 13 placeholders
        // Type string breakdown: s(shipment_number), i(po_id), i(transfer_id), s(type), i(origin_warehouse_id), 
        //                         i(destination_warehouse_id), i(supplier_id), i(vehicle_id), i(driver_id), 
        //                         s(scheduled_date), s(tracking_number), d(transport_cost), i(created_by)
        // Type string: "siisiiiissdi" = 13 characters
        
        // mysqli bind_param with type 'i' cannot bind NULL values directly
        // Solution: Modify SQL to handle NULLs by using NULLIF or CAST with CASE
        // Modify the SQL to convert empty/zero integers to NULL for nullable columns
        $stmt = $conn->prepare("INSERT INTO shipments (shipment_number, po_id, transfer_id, type, origin_warehouse_id, destination_warehouse_id, supplier_id, vehicle_id, driver_id, scheduled_date, tracking_number, transport_cost, created_by) VALUES (?, NULLIF(?, 0), NULLIF(?, 0), ?, NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, 0), ?, ?, ?, ?)");
        if (!$stmt) {
            die("Prepare failed: " . $conn->error);
        }
        
        // Ensure created_by is an integer
        $created_by = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        
        // Prepare variables for binding - convert NULL to 0, let SQL NULLIF convert back to NULL
        // This workaround allows us to bind with type 'i' while still storing NULL in the database
        $bind_po_id = ($po_id === null) ? 0 : (int)$po_id;
        $bind_transfer_id = ($transfer_id === null) ? 0 : (int)$transfer_id;
        $bind_origin_warehouse_id = ($origin_warehouse_id === null) ? 0 : (int)$origin_warehouse_id;
        $bind_destination_warehouse_id = ($destination_warehouse_id === null) ? 0 : (int)$destination_warehouse_id;
        $bind_supplier_id = ($supplier_id === null) ? 0 : (int)$supplier_id;
        $bind_vehicle_id = ($vehicle_id === null) ? 0 : (int)$vehicle_id;
        $bind_driver_id = ($driver_id === null) ? 0 : (int)$driver_id;
        
        // Bind parameters - now all integers can be bound with type 'i'
        // NULLIF in SQL will convert 0 back to NULL for nullable columns
        $stmt->bind_param("siisiiiissdii", 
            $shipment_number, 
            $bind_po_id, 
            $bind_transfer_id, 
            $type, 
            $bind_origin_warehouse_id, 
            $bind_destination_warehouse_id, 
            $bind_supplier_id, 
            $bind_vehicle_id, 
            $bind_driver_id, 
            $scheduled_date, 
            $tracking_number, 
            $transport_cost, 
            $created_by);
        
        if ($stmt->execute()) {
            $shipment_id = $stmt->insert_id;
            
            // Insert items
            $item_stmt = $conn->prepare("INSERT INTO shipment_items (shipment_id, product_id, quantity) VALUES (?, ?, ?)");
            
            foreach ($items as $item) {
                if (isset($item['product_id']) && isset($item['quantity'])) {
                    $item_stmt->bind_param("iii", $shipment_id, $item['product_id'], $item['quantity']);
                    $item_stmt->execute();
                }
            }
            
            $item_stmt->close();
            
            // Update transfer status to in_transit when shipment is created
            if ($transfer_id) {
                $update_transfer_stmt = $conn->prepare("UPDATE warehouse_transfers SET status = 'in_transit' WHERE id = ?");
                $update_transfer_stmt->bind_param("i", $transfer_id);
                $update_transfer_stmt->execute();
                $update_transfer_stmt->close();
                
                // Notify Warehouse Officer who created the transfer that shipment has been booked
                if (isset($transfer_data['requested_by'])) {
                    createNotification(
                        $transfer_data['requested_by'],
                        'warehouse_officer',
                        'transfer_shipment_booked',
                        'Shipment Booked for Transfer',
                        "A shipment has been booked for transfer request {$transfer_data['transfer_number']}. Shipment #: {$shipment_number}",
                        'warehouse_transfer',
                        $transfer_id
                    );
                }
            }
            
            $message = "Shipment {$shipment_number} created successfully!";
        } else {
            $error = 'Failed to create shipment: ' . $stmt->error;
        }
        $stmt->close();
    } elseif (empty($error)) {
        $error = 'Please fill all required fields.';
    }
}

// Update Shipment Status (non-AJAX requests only - AJAX is handled at top of file)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_shipment_status'])) {
    // Skip if this is an AJAX request (already handled at top of file)
    if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
        // AJAX request already handled, skip this
        exit;
    }
    
    // Check permissions: Warehouse Officer can approve deliveries, Logistics Manager can edit all
    $shipment_id = $_POST['shipment_id'] ?? 0;
    $status = $_POST['status'] ?? '';
    $delivery_date = $_POST['delivery_date'] ?? null;
    $transit_date = $_POST['transit_date'] ?? null;
    $transit_time = $_POST['transit_time'] ?? null;
    $transit_location_name = trim($_POST['transit_location_name'] ?? '');
    
    if ($shipment_id && $status) {
        // Get shipment details first to check permissions and scheduled date
        $shipment_stmt = $conn->prepare("SELECT shipment_number, type, origin_warehouse_id, destination_warehouse_id, po_id, transfer_id, status as current_status, scheduled_date FROM shipments WHERE id = ?");
        $shipment_stmt->bind_param("i", $shipment_id);
        $shipment_stmt->execute();
        $shipment_result = $shipment_stmt->get_result();
        $shipment_data = $shipment_result->fetch_assoc();
        $shipment_stmt->close();
        
        // Check if scheduled date has passed
        if ($shipment_data && $shipment_data['scheduled_date'] && strtotime($shipment_data['scheduled_date']) < strtotime(date('Y-m-d'))) {
            $error = 'Cannot update shipment status. The scheduled date (' . $shipment_data['scheduled_date'] . ') has already passed.';
        }
        
        if (!$shipment_data) {
            $error = 'Shipment not found.';
        } else {
            // Check if user has permission to update this shipment
            $can_update = false;
            
            // Warehouse Officer can approve deliveries (mark as delivered) for inbound shipments
            if ($status == 'delivered' && canApproveDelivery(['shipment_type' => $shipment_data['type']])) {
                $can_update = true;
            }
            // Logistics Manager can edit all shipment statuses
            elseif (canEdit('logistics')) {
                $can_update = true;
            }
            // Admin can do everything
            elseif (isAdmin()) {
                $can_update = true;
            }
            
            if (!$can_update) {
                $error = 'You do not have permission to update this shipment status.';
            } elseif ($status == 'delivered' && empty($delivery_date)) {
                // Validate delivery date for delivered status
                $error = 'Delivery date is required when status is "delivered".';
            } elseif ($status == 'delivered' && !empty($delivery_date) && $delivery_date < date('Y-m-d')) {
                // Validate that delivery date is not in the past
                $error = 'Delivery date cannot be in the past. Please select today\'s date or a future date.';
            } else {
                // Start transaction
                $conn->begin_transaction();
                
                try {
                    // Auto-generate tracking number if missing when status changes to 'in_transit'
                    if ($status == 'in_transit') {
                    $check_tracking_stmt = $conn->prepare("SELECT tracking_number FROM shipments WHERE id = ?");
                    $check_tracking_stmt->bind_param("i", $shipment_id);
                    $check_tracking_stmt->execute();
                    $tracking_result = $check_tracking_stmt->get_result();
                    $tracking_data = $tracking_result->fetch_assoc();
                    $check_tracking_stmt->close();
                    
                    // Generate tracking number if missing, null, "0", or "N/A"
                    if (empty($tracking_data['tracking_number']) || 
                        $tracking_data['tracking_number'] === '0' || 
                        $tracking_data['tracking_number'] === 'N/A') {
                        $new_tracking_number = generateTrackingNumber();
                        $update_tracking_stmt = $conn->prepare("UPDATE shipments SET tracking_number = ? WHERE id = ?");
                        $update_tracking_stmt->bind_param("si", $new_tracking_number, $shipment_id);
                        $update_tracking_stmt->execute();
                        $update_tracking_stmt->close();
                    }
                    }
                    
                    // Update shipment status
                    if ($status == 'delivered') {
                        $stmt = $conn->prepare("UPDATE shipments SET status = ?, delivery_date = ? WHERE id = ?");
                        $stmt->bind_param("ssi", $status, $delivery_date, $shipment_id);
                        if (!$stmt->execute()) {
                            throw new Exception('Failed to update shipment status.');
                        }
                        $stmt->close();
                    } elseif ($status == 'in_transit') {
                        // Use current date/time if not provided
                        $transit_date = $transit_date ?: date('Y-m-d');
                        $current_time = date('H:i');
                        $last_location_update = $transit_date . ' ' . $current_time . ':00';
                        
                        // Update shipment status (only if not already in_transit)
                        if ($shipment_data['current_status'] != 'in_transit') {
                            $stmt = $conn->prepare("UPDATE shipments SET status = ?, last_location_update = ? WHERE id = ?");
                            $stmt->bind_param("ssi", $status, $last_location_update, $shipment_id);
                            if (!$stmt->execute()) {
                                throw new Exception('Failed to update shipment status.');
                            }
                            $stmt->close();
                        }
                        
                        // Insert location entry only if location name is provided
                        if (!empty($transit_location_name)) {
                            // Use placeholder coordinates (0,0) since we only have location_name, not GPS coordinates
                            $placeholder_lat = 0.0;
                            $placeholder_lng = 0.0;
                            
                            $location_stmt = $conn->prepare("INSERT INTO shipment_locations (shipment_id, latitude, longitude, location_name, recorded_at) VALUES (?, ?, ?, ?, ?)");
                            $recorded_at = $last_location_update;
                            
                            if (!$location_stmt) {
                                throw new Exception('Failed to prepare location insert statement: ' . $conn->error);
                            }
                            
                            $location_stmt->bind_param("iddss", $shipment_id, $placeholder_lat, $placeholder_lng, $transit_location_name, $recorded_at);
                            
                            if (!$location_stmt->execute()) {
                                $error_msg = $location_stmt->error ?: $conn->error;
                                $location_stmt->close();
                                throw new Exception('Failed to save location entry: ' . $error_msg . '. Please ensure the location_name column exists in the shipment_locations table.');
                            }
                            $location_stmt->close();
                        }
                        
                        // If status is changing to 'in_transit' for the first time, update PO/Transfer and send notifications
                        if ($shipment_data['current_status'] != 'in_transit') {
                            // Handle PO-related shipments
                            if ($shipment_data['po_id']) {
                                // Update PO status to 'in_transit' if not already received/cancelled
                                $po_update_stmt = $conn->prepare("UPDATE purchase_orders SET status = 'in_transit' WHERE id = ? AND status != 'received' AND status != 'cancelled'");
                                $po_update_stmt->bind_param("i", $shipment_data['po_id']);
                                $po_update_stmt->execute();
                                $po_update_stmt->close();
                                
                                // Get PO details for notifications
                                $po_notif_stmt = $conn->prepare("SELECT po_number, supplier_id, created_by FROM purchase_orders WHERE id = ?");
                                $po_notif_stmt->bind_param("i", $shipment_data['po_id']);
                                $po_notif_stmt->execute();
                                $po_notif_result = $po_notif_stmt->get_result();
                                $po_notif_data = $po_notif_result->fetch_assoc();
                                $po_notif_stmt->close();
                                
                                if ($po_notif_data) {
                                    $po_number = $po_notif_data['po_number'];
                                    $shipment_number = $shipment_data['shipment_number'] ?? 'N/A';
                                    
                                    // Notify Admin users
                                    $location_text = !empty($transit_location_name) ? "Location: {$transit_location_name}. " : "";
                                    notifyUsersByRole(
                                        'admin',
                                        'shipment_in_transit',
                                        'Shipment In Transit',
                                        "Shipment {$shipment_number} for Purchase Order {$po_number} is now IN TRANSIT. {$location_text}Date: " . date('M d, Y', strtotime($transit_date)),
                                        'shipment',
                                        $shipment_id
                                    );
                                    
                                    // Notify Supplier
                                    if ($po_notif_data['supplier_id']) {
                                        notifySupplier(
                                            $po_notif_data['supplier_id'],
                                            'shipment_in_transit',
                                            'Shipment In Transit',
                                            "Shipment {$shipment_number} for Purchase Order {$po_number} is now IN TRANSIT. {$location_text}Date: " . date('M d, Y', strtotime($transit_date)),
                                            'shipment',
                                            $shipment_id
                                        );
                                    }
                                    
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
                                                'shipment_in_transit',
                                                'Shipment In Transit',
                                                "Shipment {$shipment_number} for Purchase Order {$po_number} is now IN TRANSIT. {$location_text}Date: " . date('M d, Y', strtotime($transit_date)),
                                                'shipment',
                                                $shipment_id
                                            );
                                        }
                                    }
                                }
                            }
                            
                            // Handle transfer-related shipments for in_transit status
                            if ($shipment_data['transfer_id']) {
                                // Get transfer details for notifications
                                $transfer_notif_stmt = $conn->prepare("
                                    SELECT wt.transfer_number, wt.requested_by
                                    FROM warehouse_transfers wt
                                    WHERE wt.id = ?
                                ");
                                $transfer_notif_stmt->bind_param("i", $shipment_data['transfer_id']);
                                $transfer_notif_stmt->execute();
                                $transfer_notif_result = $transfer_notif_stmt->get_result();
                                $transfer_notif_data = $transfer_notif_result->fetch_assoc();
                                $transfer_notif_stmt->close();
                                
                                if ($transfer_notif_data) {
                                    $transfer_number = $transfer_notif_data['transfer_number'];
                                    $shipment_number = $shipment_data['shipment_number'] ?? 'N/A';
                                    $requested_by_id = $transfer_notif_data['requested_by'];
                                    $location_text = !empty($transit_location_name) ? "Location: {$transit_location_name}. " : "";
                                    
                                    // Notify Warehouse Officer who created the transfer that shipment is in transit
                                    createNotification(
                                        $requested_by_id,
                                        'warehouse_officer',
                                        'transfer_shipment_in_transit',
                                        'Transfer Shipment In Transit',
                                        "Shipment {$shipment_number} for transfer request {$transfer_number} is now IN TRANSIT. {$location_text}Date: " . date('M d, Y', strtotime($transit_date)),
                                        'warehouse_transfer',
                                        $shipment_data['transfer_id']
                                    );
                                }
                            }
                        }
                    } else {
                        $stmt = $conn->prepare("UPDATE shipments SET status = ? WHERE id = ?");
                        $stmt->bind_param("si", $status, $shipment_id);
                        if (!$stmt->execute()) {
                            throw new Exception('Failed to update shipment status.');
                        }
                        $stmt->close();
                    }
                    
                    // Only continue with inventory updates if not in_transit
                    if ($status != 'in_transit') {
                    // If status changed to 'delivered', update inventory
                    // Inbound shipments: Add inventory to destination warehouse
                    if ($status == 'delivered' && $shipment_data['current_status'] != 'delivered') {
                        // Handle transfer-related shipments first (before general inventory update)
                        if ($shipment_data['transfer_id']) {
                            // Get transfer details for notifications
                            $transfer_notif_stmt = $conn->prepare("
                                SELECT wt.transfer_number, wt.requested_by, wt.status as transfer_status
                                FROM warehouse_transfers wt
                                WHERE wt.id = ?
                            ");
                            $transfer_notif_stmt->bind_param("i", $shipment_data['transfer_id']);
                            $transfer_notif_stmt->execute();
                            $transfer_notif_result = $transfer_notif_stmt->get_result();
                            $transfer_notif_data = $transfer_notif_result->fetch_assoc();
                            $transfer_notif_stmt->close();
                            
                            if ($transfer_notif_data) {
                                $transfer_number = $transfer_notif_data['transfer_number'];
                                $shipment_number = $shipment_data['shipment_number'] ?? 'N/A';
                                $requested_by_id = $transfer_notif_data['requested_by'];
                                
                                // Notify Warehouse Officer who created the transfer about delivery status
                                createNotification(
                                    $requested_by_id,
                                    'warehouse_officer',
                                    'transfer_shipment_delivered',
                                    'Transfer Shipment Delivered',
                                    "Shipment {$shipment_number} for transfer request {$transfer_number} has been delivered. Delivery date: " . date('M d, Y', strtotime($delivery_date)),
                                    'warehouse_transfer',
                                    $shipment_data['transfer_id']
                                );
                                
                                // Update transfer status based on current status
                                $transfer_status = $transfer_notif_data['transfer_status'];
                                
                                // Only update transfer status to 'delivered' when shipment is delivered
                                // Inventory will only update when warehouse marks as 'received' AND shipment is 'delivered'
                                if ($transfer_status !== 'completed' && $transfer_status !== 'received') {
                                    // Set transfer status to 'delivered' when shipment is delivered
                                    $update_transfer_stmt = $conn->prepare("UPDATE warehouse_transfers SET status = 'delivered' WHERE id = ?");
                                    $update_transfer_stmt->bind_param("i", $shipment_data['transfer_id']);
                                    $update_transfer_stmt->execute();
                                    $update_transfer_stmt->close();
                                }
                                // Note: If transfer is already 'received', inventory update will happen when warehouse marks as 'received' and shipment is 'delivered'
                            }
                        } else {
                            // For non-transfer shipments (inbound PO shipments)
                            // Only update inventory if PO is already marked as 'received' by procurement
                            // Otherwise, inventory will be updated when procurement marks PO as 'received'
                            if ($shipment_data['po_id']) {
                                // Check if PO is already 'received'
                                $po_check_stmt = $conn->prepare("SELECT status FROM purchase_orders WHERE id = ?");
                                $po_check_stmt->bind_param("i", $shipment_data['po_id']);
                                $po_check_stmt->execute();
                                $po_check_result = $po_check_stmt->get_result();
                                $po_check_data = $po_check_result->fetch_assoc();
                                $po_check_stmt->close();
                                
                                // Only update inventory if PO status is already 'received'
                                if ($po_check_data && $po_check_data['status'] === 'received') {
                                    try {
                                        updateInventoryOnShipmentDelivery($conn, $shipment_id, $shipment_data);
                                    } catch (Exception $e) {
                                        // Log error but don't fail the shipment status update
                                        error_log("Inventory update failed: " . $e->getMessage());
                                    }
                                }
                                // If PO is not 'received' yet, inventory will be updated when procurement marks it as 'received'
                            } else {
                                // For shipments without PO, update inventory immediately (legacy behavior)
                                try {
                                    updateInventoryOnShipmentDelivery($conn, $shipment_id, $shipment_data);
                                } catch (Exception $e) {
                                    error_log("Inventory update failed: " . $e->getMessage());
                                }
                            }
                        }
                        
                        // Notify procurement staff when shipment is delivered (but don't auto-update PO status)
                        if ($shipment_data['po_id']) {
                            // Check if all active shipments (non-cancelled) for this PO are delivered
                            // Exclude cancelled shipments from the count
                            $po_shipments_stmt = $conn->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered_count FROM shipments WHERE po_id = ? AND status != 'cancelled'");
                            $po_shipments_stmt->bind_param("i", $shipment_data['po_id']);
                            $po_shipments_stmt->execute();
                            $po_shipments_result = $po_shipments_stmt->get_result();
                            $po_shipments_data = $po_shipments_result->fetch_assoc();
                            $po_shipments_stmt->close();
                            
                            // Get PO details for notifications
                            $po_notif_stmt = $conn->prepare("SELECT po_number, supplier_id, created_by, status as po_status FROM purchase_orders WHERE id = ?");
                            $po_notif_stmt->bind_param("i", $shipment_data['po_id']);
                            $po_notif_stmt->execute();
                            $po_notif_result = $po_notif_stmt->get_result();
                            $po_notif_data = $po_notif_result->fetch_assoc();
                            $po_notif_stmt->close();
                            
                            if ($po_notif_data) {
                                $po_number = $po_notif_data['po_number'];
                                $shipment_number = $shipment_data['shipment_number'] ?? 'N/A';
                                
                                // Check if all shipments are delivered
                                $all_delivered = ($po_shipments_data && $po_shipments_data['total'] > 0 && 
                                    $po_shipments_data['delivered_count'] == $po_shipments_data['total']);
                                
                                // Notify procurement staff who created the PO
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
                                        if ($all_delivered) {
                                            // All shipments delivered - notify procurement to mark as received
                                            createNotification(
                                                $created_by,
                                                $user_data['role'],
                                                'all_shipments_delivered',
                                                'All Shipments Delivered - Ready to Mark as Received',
                                                "All shipments for Purchase Order {$po_number} have been delivered. Please mark the PO as 'Received' to update inventory.",
                                                'purchase_order',
                                                $shipment_data['po_id']
                                            );
                                        } else {
                                            // Shipment delivered but not all shipments yet
                                            createNotification(
                                                $created_by,
                                                $user_data['role'],
                                                'shipment_delivered',
                                                'Shipment Delivered',
                                                "Shipment {$shipment_number} for Purchase Order {$po_number} has been delivered.",
                                                'shipment',
                                                $shipment_id
                                            );
                                        }
                                    }
                                }
                                
                                // Also notify all procurement staff (not just creator) if all shipments are delivered
                                if ($all_delivered) {
                                    notifyUsersByRole(
                                        'procurement_staff',
                                        'all_shipments_delivered',
                                        'All Shipments Delivered - Ready to Mark as Received',
                                        "All shipments for Purchase Order {$po_number} have been delivered. Please mark the PO as 'Received' to update inventory.",
                                        'purchase_order',
                                        $shipment_data['po_id']
                                    );
                                }
                            }
                        }
                    }
                }
                
                // Commit transaction
                $conn->commit();
                $message = 'Shipment status updated successfully!';
                
            } catch (Exception $e) {
                // Rollback on error
                $conn->rollback();
                $error = 'Failed to update status: ' . $e->getMessage();
            }
            }
        }
    } else {
        $error = 'Please provide shipment ID and status.';
    }
}

// Assign Driver and Vehicle to Shipment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assign_driver_vehicle'])) {
    requireEditPermission('logistics');
    $shipment_id = $_POST['shipment_id'] ?? 0;
    $vehicle_id = $_POST['vehicle_id'] ?? null;
    $driver_id = $_POST['driver_id'] ?? null;
    
    if ($shipment_id) {
        // Convert empty strings to null for nullable fields
        $vehicle_id = ($vehicle_id === '' || $vehicle_id === 0) ? null : (int)$vehicle_id;
        $driver_id = ($driver_id === '' || $driver_id === 0) ? null : (int)$driver_id;
        
        // Verify shipment exists
        $check_stmt = $conn->prepare("SELECT id FROM shipments WHERE id = ?");
        $check_stmt->bind_param("i", $shipment_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        
        if ($result->num_rows == 0) {
            $error = 'Shipment not found.';
        } else {
            // Update vehicle and driver assignment
            $stmt = $conn->prepare("UPDATE shipments SET vehicle_id = ?, driver_id = ? WHERE id = ?");
            $stmt->bind_param("iii", $vehicle_id, $driver_id, $shipment_id);
            
            if ($stmt->execute()) {
                $message = 'Driver and vehicle assigned successfully!';
            } else {
                $error = 'Failed to assign driver and vehicle: ' . $stmt->error;
            }
            $stmt->close();
        }
        $check_stmt->close();
    } else {
        $error = 'Please provide shipment ID.';
    }
}

// Create/Update Vehicle
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_vehicle'])) {
    requireCreatePermission('logistics');
    $vehicle_id = $_POST['vehicle_id'] ?? 0;
    $vehicle_number = $_POST['vehicle_number'] ?? '';
    $vehicle_type = $_POST['vehicle_type'] ?? 'truck';
    $make = $_POST['make'] ?? '';
    $model = $_POST['model'] ?? '';
    $year = $_POST['year'] ?? null;
    $license_plate = $_POST['license_plate'] ?? '';
    $capacity_kg = $_POST['capacity_kg'] ?? null;
    $capacity_volume = $_POST['capacity_volume'] ?? null;
    $fuel_type = $_POST['fuel_type'] ?? 'diesel';
    $status = $_POST['status'] ?? 'available';
    $current_location = $_POST['current_location'] ?? '';
    $last_maintenance_date = $_POST['last_maintenance_date'] ?? null;
    $next_maintenance_date = $_POST['next_maintenance_date'] ?? null;
    $notes = $_POST['notes'] ?? '';
    
    if ($vehicle_number) {
        if ($vehicle_id > 0) {
            // Update
            $stmt = $conn->prepare("UPDATE vehicles SET vehicle_number=?, vehicle_type=?, make=?, model=?, year=?, license_plate=?, capacity_kg=?, capacity_volume=?, fuel_type=?, status=?, current_location=?, last_maintenance_date=?, next_maintenance_date=?, notes=? WHERE id=?");
            $stmt->bind_param("ssssisddssssssi", $vehicle_number, $vehicle_type, $make, $model, $year, $license_plate, $capacity_kg, $capacity_volume, $fuel_type, $status, $current_location, $last_maintenance_date, $next_maintenance_date, $notes, $vehicle_id);
        } else {
            // Insert
            $stmt = $conn->prepare("INSERT INTO vehicles (vehicle_number, vehicle_type, make, model, year, license_plate, capacity_kg, capacity_volume, fuel_type, status, current_location, last_maintenance_date, next_maintenance_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssisddssssss", $vehicle_number, $vehicle_type, $make, $model, $year, $license_plate, $capacity_kg, $capacity_volume, $fuel_type, $status, $current_location, $last_maintenance_date, $next_maintenance_date, $notes);
        }
        
        if ($stmt->execute()) {
            $message = $vehicle_id > 0 ? 'Vehicle updated successfully!' : 'Vehicle added successfully!';
        } else {
            $error = 'Failed to save vehicle: ' . $stmt->error;
        }
        $stmt->close();
    } else {
        $error = 'Vehicle number is required.';
    }
}

// Delete Vehicle
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_vehicle'])) {
    requireDeletePermission('logistics');
    $vehicle_id = intval($_POST['vehicle_id'] ?? 0);
    
    if ($vehicle_id > 0) {
        // Check if vehicle is assigned to any shipments
        $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM shipments WHERE vehicle_id = ?");
        $check_stmt->bind_param("i", $vehicle_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        $data = $result->fetch_assoc();
        $check_stmt->close();
        
        if ($data['count'] > 0) {
            $error = 'Cannot delete vehicle that is assigned to shipments.';
        } else {
            $stmt = $conn->prepare("DELETE FROM vehicles WHERE id = ?");
            $stmt->bind_param("i", $vehicle_id);
            if ($stmt->execute()) {
                $message = 'Vehicle deleted successfully!';
            } else {
                $error = 'Failed to delete vehicle: ' . $stmt->error;
            }
            $stmt->close();
        }
    }
}

// Create/Update Driver
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_driver'])) {
    requireCreatePermission('logistics');
    $driver_id = $_POST['driver_id'] ?? 0;
    $driver_code = $_POST['driver_code'] ?? '';
    $full_name = $_POST['full_name'] ?? '';
    $license_number = $_POST['license_number'] ?? '';
    $license_type = $_POST['license_type'] ?? 'B';
    $license_expiry = $_POST['license_expiry'] ?? null;
    $phone = $_POST['phone'] ?? '';
    $email = $_POST['email'] ?? '';
    $address = $_POST['address'] ?? '';
    $status = $_POST['status'] ?? 'available';
    $hire_date = $_POST['hire_date'] ?? null;
    $notes = $_POST['notes'] ?? '';
    
    if ($driver_code && $full_name) {
        if ($driver_id > 0) {
            // Update
            $stmt = $conn->prepare("UPDATE drivers SET driver_code=?, full_name=?, license_number=?, license_type=?, license_expiry=?, phone=?, email=?, address=?, status=?, hire_date=?, notes=? WHERE id=?");
            $stmt->bind_param("sssssssssssi", $driver_code, $full_name, $license_number, $license_type, $license_expiry, $phone, $email, $address, $status, $hire_date, $notes, $driver_id);
        } else {
            // Insert
            $stmt = $conn->prepare("INSERT INTO drivers (driver_code, full_name, license_number, license_type, license_expiry, phone, email, address, status, hire_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssssssss", $driver_code, $full_name, $license_number, $license_type, $license_expiry, $phone, $email, $address, $status, $hire_date, $notes);
        }
        
        if ($stmt->execute()) {
            $message = $driver_id > 0 ? 'Driver updated successfully!' : 'Driver added successfully!';
        } else {
            $error = 'Failed to save driver: ' . $stmt->error;
        }
        $stmt->close();
    } else {
        $error = 'Driver code and full name are required.';
    }
}

// Delete Driver
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_driver'])) {
    requireDeletePermission('logistics');
    $driver_id = intval($_POST['driver_id'] ?? 0);
    
    if ($driver_id > 0) {
        // Check if driver is assigned to any shipments
        $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM shipments WHERE driver_id = ?");
        $check_stmt->bind_param("i", $driver_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        $data = $result->fetch_assoc();
        $check_stmt->close();
        
        if ($data['count'] > 0) {
            $error = 'Cannot delete driver that is assigned to shipments.';
        } else {
            $stmt = $conn->prepare("DELETE FROM drivers WHERE id = ?");
            $stmt->bind_param("i", $driver_id);
            if ($stmt->execute()) {
                $message = 'Driver deleted successfully!';
            } else {
                $error = 'Failed to delete driver: ' . $stmt->error;
            }
            $stmt->close();
        }
    }
}

// Get shipments
$status_filter = $_GET['status'] ?? '';
$where_conditions = [];
if ($status_filter) $where_conditions[] = "s.status = '$status_filter'";
$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

$shipments = [];
$po_shipments = [];
$transfer_shipments = [];
$result = $conn->query("
    SELECT s.*, 
           s.vehicle_id, s.driver_id,
           s.scheduled_date,
           s.delivery_date,
           s.tracking_number,
           po.po_number,
           po.delivery_date as po_expected_date,
           wt.transfer_number,
           wt.requested_date as transfer_expected_date,
           COALESCE(po.delivery_date, wt.requested_date) as expected_date,
           s1.name as origin_warehouse,
           s2.name as destination_warehouse,
           sup.company_name as supplier_name,
           v.vehicle_number, v.vehicle_type, v.make as vehicle_make, v.model as vehicle_model,
           d.full_name as driver_name, d.driver_code, d.phone as driver_phone,
           u.full_name as created_by_name,
           u.role as created_by_role
    FROM shipments s
    LEFT JOIN purchase_orders po ON s.po_id = po.id
    LEFT JOIN warehouse_transfers wt ON s.transfer_id = wt.id
    LEFT JOIN warehouses s1 ON s.origin_warehouse_id = s1.id
    LEFT JOIN warehouses s2 ON s.destination_warehouse_id = s2.id
    LEFT JOIN suppliers sup ON s.supplier_id = sup.id
    LEFT JOIN vehicles v ON s.vehicle_id = v.id
    LEFT JOIN drivers d ON s.driver_id = d.id
    JOIN users u ON s.created_by = u.id
    $where_clause
    ORDER BY s.created_at DESC
");
while ($row = $result->fetch_assoc()) {
    $shipments[] = $row;
    // Separate shipments by type
    if ($row['type'] === 'inbound') {
        $po_shipments[] = $row;
    } elseif ($row['type'] === 'transfer') {
        $transfer_shipments[] = $row;
    }
}

// Count supplier-scheduled shipments that are new (created in last 24 hours)
$new_supplier_shipments = 0;
foreach ($shipments as $shipment) {
    if ($shipment['created_by_role'] === 'supplier' && 
        $shipment['status'] === 'scheduled' &&
        strtotime($shipment['created_at']) > (time() - 86400)) {
        $new_supplier_shipments++;
    }
}

// Get purchase orders (approved and in_transit ones that can still have shipments created)
$purchase_orders = [];
$result = $conn->query("SELECT id, po_number FROM purchase_orders WHERE status IN ('approved', 'in_transit') ORDER BY created_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $purchase_orders[] = $row;
    }
} else {
    // Log error if query fails
    error_log("Failed to fetch purchase orders: " . $conn->error);
}

// Get approved transfer requests for shipment booking
$approved_transfers = [];
$transfer_result = $conn->query("
    SELECT wt.id, wt.transfer_number, wt.source_warehouse_id, wt.destination_warehouse_id, 
           w1.name as source_warehouse, w2.name as destination_warehouse, wt.requested_date
    FROM warehouse_transfers wt
    JOIN warehouses w1 ON wt.source_warehouse_id = w1.id
    JOIN warehouses w2 ON wt.destination_warehouse_id = w2.id
    WHERE wt.status = 'approved' 
    AND NOT EXISTS (SELECT 1 FROM shipments WHERE transfer_id = wt.id)
    ORDER BY wt.created_at DESC
");
if ($transfer_result) {
    while ($row = $transfer_result->fetch_assoc()) {
        $approved_transfers[] = $row;
    }
} else {
    error_log("Failed to fetch approved transfers: " . $conn->error);
}

// Get warehouses
$warehouses = [];
$result = $conn->query("SELECT id, name FROM warehouses WHERE status = 'active' ORDER BY name");
while ($row = $result->fetch_assoc()) {
    $warehouses[] = $row;
}

// Get suppliers
$suppliers = [];
$result = $conn->query("SELECT id, company_name FROM suppliers WHERE status = 'active' ORDER BY company_name");
while ($row = $result->fetch_assoc()) {
    $suppliers[] = $row;
}

// Get products with supplier information (only those in inventory)
$products = [];
$result = $conn->query("
    SELECT DISTINCT p.id, p.name, p.sku, 
           COALESCE(s.id, 0) as supplier_id, 
           COALESCE(s.company_name, 'No Supplier') as supplier_name,
           sp.supplier_sku, sp.is_primary
    FROM products p 
    INNER JOIN inventory i ON p.id = i.product_id
    LEFT JOIN supplier_products sp ON p.id = sp.product_id AND sp.status = 'active'
    LEFT JOIN suppliers s ON sp.supplier_id = s.id AND s.status = 'active'
    ORDER BY COALESCE(s.company_name, 'No Supplier'), sp.is_primary DESC, p.name
");
while ($row = $result->fetch_assoc()) {
    $products[] = $row;
}

// Get vehicles
$vehicles = [];
$result = $conn->query("SELECT * FROM vehicles ORDER BY vehicle_number");
while ($row = $result->fetch_assoc()) {
    $vehicles[] = $row;
}

// Get drivers
$drivers = [];
$result = $conn->query("SELECT * FROM drivers ORDER BY full_name");
while ($row = $result->fetch_assoc()) {
    $drivers[] = $row;
}


// AJAX endpoint: Get PO details for shipment
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_po_shipment_data' && isset($_GET['po_id'])) {
    header('Content-Type: application/json');
    $po_id = intval($_GET['po_id']);
    
    // Get PO details with supplier
    $stmt = $conn->prepare("
        SELECT po.*, s.id as supplier_id, s.company_name as supplier_name
        FROM purchase_orders po
        JOIN suppliers s ON po.supplier_id = s.id
        WHERE po.id = ?
    ");
    $stmt->bind_param("i", $po_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $po_data = $result->fetch_assoc();
    $stmt->close();
    
    if ($po_data) {
        // Get PO items
        $stmt = $conn->prepare("
            SELECT poi.product_id, poi.quantity, p.name, p.sku
            FROM purchase_order_items poi
            JOIN products p ON poi.product_id = p.id
            WHERE poi.po_id = ?
            ORDER BY poi.id
        ");
        $stmt->bind_param("i", $po_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = [
                'product_id' => $row['product_id'],
                'quantity' => $row['quantity'],
                'name' => $row['name'],
                'sku' => $row['sku']
            ];
        }
        $stmt->close();
        
        $po_data['items'] = $items;
    }
    
    echo json_encode($po_data);
    closeDBConnection($conn);
    exit;
}

// AJAX endpoint: Get transfer data for shipment creation
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_transfer_shipment_data' && isset($_GET['transfer_id'])) {
    header('Content-Type: application/json');
    $transfer_id = intval($_GET['transfer_id']);
    
    // Get transfer details
    $stmt = $conn->prepare("
        SELECT wt.*, w1.name as source_warehouse, w2.name as destination_warehouse
        FROM warehouse_transfers wt
        JOIN warehouses w1 ON wt.source_warehouse_id = w1.id
        JOIN warehouses w2 ON wt.destination_warehouse_id = w2.id
        WHERE wt.id = ? AND wt.status = 'approved'
    ");
    $stmt->bind_param("i", $transfer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $transfer_data = $result->fetch_assoc();
    $stmt->close();
    
    if ($transfer_data) {
        // Get transfer items
        $stmt = $conn->prepare("
            SELECT ti.product_id, ti.quantity, p.name as product_name, p.sku
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
            $items[] = [
                'product_id' => $row['product_id'],
                'quantity' => $row['quantity'],
                'product_name' => $row['product_name'],
                'sku' => $row['sku']
            ];
        }
        $stmt->close();
        
        $transfer_data['items'] = $items;
    }
    
    echo json_encode($transfer_data);
    closeDBConnection($conn);
    exit;
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

// AJAX endpoint: Get shipment details
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_shipment_details' && isset($_GET['shipment_id'])) {
    header('Content-Type: application/json');
    $shipment_id = intval($_GET['shipment_id']);
    
    $stmt = $conn->prepare("
        SELECT s.*, 
               po.po_number,
               po.delivery_date as po_expected_date,
               wt.requested_date as transfer_expected_date,
               COALESCE(po.delivery_date, wt.requested_date) as expected_date,
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
        LEFT JOIN warehouse_transfers wt ON s.transfer_id = wt.id
        LEFT JOIN warehouses w1 ON s.origin_warehouse_id = w1.id
        LEFT JOIN warehouses w2 ON s.destination_warehouse_id = w2.id
        LEFT JOIN suppliers sup ON s.supplier_id = sup.id
        LEFT JOIN vehicles v ON s.vehicle_id = v.id
        LEFT JOIN drivers d ON s.driver_id = d.id
        JOIN users u ON s.created_by = u.id
        WHERE s.id = ?
    ");
    $stmt->bind_param("i", $shipment_id);
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
    $supplier_id = $_GET['supplier_id'] ?? null;
    
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

// AJAX endpoint: Get current GPS location for shipment
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_shipment_gps') {
    header('Content-Type: application/json');
    $shipment_id = intval($_GET['shipment_id'] ?? 0);
    
    $stmt = $conn->prepare("
        SELECT 
            current_latitude,
            current_longitude,
            estimated_arrival,
            last_location_update,
            (SELECT speed_kmh FROM shipment_locations 
             WHERE shipment_id = ? 
             ORDER BY recorded_at DESC LIMIT 1) as current_speed,
            (SELECT heading_degrees FROM shipment_locations 
             WHERE shipment_id = ? 
             ORDER BY recorded_at DESC LIMIT 1) as current_heading
        FROM shipments
        WHERE id = ? AND status = 'in_transit'
    ");
    $stmt->bind_param("iii", $shipment_id, $shipment_id, $shipment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $gps_data = $result->fetch_assoc();
    $stmt->close();
    
    if ($gps_data) {
        echo json_encode($gps_data);
    } else {
        echo json_encode(['error' => 'No GPS data available']);
    }
    closeDBConnection($conn);
    exit;
}

// AJAX endpoint: Get location history
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_location_history') {
    header('Content-Type: application/json');
    $shipment_id = intval($_GET['shipment_id'] ?? 0);
    $limit = intval($_GET['limit'] ?? 50);
    
    $stmt = $conn->prepare("
        SELECT latitude, longitude, location_name, speed_kmh, heading_degrees, recorded_at
        FROM shipment_locations
        WHERE shipment_id = ?
        ORDER BY recorded_at ASC
        LIMIT ?
    ");
    $stmt->bind_param("ii", $shipment_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $history = [];
    while ($row = $result->fetch_assoc()) {
        $history[] = $row;
    }
    $stmt->close();
    
    echo json_encode(['history' => $history]);
    closeDBConnection($conn);
    exit;
}

// AJAX endpoint: Update current location
if (isset($_POST['ajax']) && $_POST['ajax'] === 'update_location') {
    header('Content-Type: application/json');
    
    // Check authentication
    if (!isLoggedIn()) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    
    // Only logistics_manager and admin can update location
    if (!canEdit('logistics')) {
        echo json_encode(['success' => false, 'error' => 'Permission denied. Only Logistics can update location.']);
        exit;
    }
    
    $shipment_id = intval($_POST['shipment_id'] ?? 0);
    $location_date = $_POST['location_date'] ?? '';
    $location_time = $_POST['location_time'] ?? '';
    $location_name = trim($_POST['location_name'] ?? '');
    
    if (!$shipment_id || empty($location_date) || empty($location_time) || empty($location_name)) {
        echo json_encode(['success' => false, 'error' => 'All fields are required']);
        exit;
    }
    
    // Verify shipment exists and is in_transit
    $stmt = $conn->prepare("SELECT id, status FROM shipments WHERE id = ?");
    $stmt->bind_param("i", $shipment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $shipment = $result->fetch_assoc();
    $stmt->close();
    
    if (!$shipment) {
        echo json_encode(['success' => false, 'error' => 'Shipment not found']);
        exit;
    }
    
    if ($shipment['status'] !== 'in_transit') {
        echo json_encode(['success' => false, 'error' => 'Shipment is not in transit']);
        exit;
    }
    
    // Combine date and time
    $recorded_at = $location_date . ' ' . $location_time . ':00';
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Insert location entry
        // Use placeholder coordinates (0,0) since we only have location_name, not GPS coordinates
        // Latitude and longitude are required by the schema but we don't have GPS data
        $placeholder_lat = 0.0;
        $placeholder_lng = 0.0;
        
        $location_stmt = $conn->prepare("INSERT INTO shipment_locations (shipment_id, latitude, longitude, location_name, recorded_at) VALUES (?, ?, ?, ?, ?)");
        if (!$location_stmt) {
            throw new Exception('Failed to prepare location insert: ' . $conn->error);
        }
        
        $location_stmt->bind_param("iddss", $shipment_id, $placeholder_lat, $placeholder_lng, $location_name, $recorded_at);
        
        if (!$location_stmt->execute()) {
            throw new Exception('Failed to save location entry: ' . $location_stmt->error);
        }
        $location_stmt->close();
        
        // Update last_location_update in shipments table
        $update_stmt = $conn->prepare("UPDATE shipments SET last_location_update = ? WHERE id = ?");
        $update_stmt->bind_param("si", $recorded_at, $shipment_id);
        $update_stmt->execute();
        $update_stmt->close();
        
        $conn->commit();
        
        echo json_encode(['success' => true, 'message' => 'Location updated successfully']);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    
    closeDBConnection($conn);
    exit;
}

// Save Route
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_route'])) {
    header('Content-Type: application/json');
    
    $shipment_id = $_POST['shipment_id'] ?? 0;
    $distance_km = $_POST['distance_km'] ?? 0;
    $eta_minutes = $_POST['eta_minutes'] ?? 0;
    
    if ($shipment_id && $distance_km > 0) {
        // Update shipment with route data
        $stmt = $conn->prepare("UPDATE shipments SET distance_km = ?, estimated_time_minutes = ? WHERE id = ?");
        $stmt->bind_param("dii", $distance_km, $eta_minutes, $shipment_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
        }
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid data']);
    }
    
    closeDBConnection($conn);
    exit;
}

// Get dashboard statistics for shipments
$shipment_stats = [];

// Active shipments (scheduled + in_transit)
$result = $conn->query("SELECT COUNT(*) as total FROM shipments WHERE status IN ('scheduled', 'in_transit')");
$shipment_stats['active'] = $result->fetch_assoc()['total'];

// In transit count
$result = $conn->query("SELECT COUNT(*) as total FROM shipments WHERE status = 'in_transit'");
$shipment_stats['in_transit'] = $result->fetch_assoc()['total'];

// Delivered today
$result = $conn->query("SELECT COUNT(*) as total FROM shipments WHERE status = 'delivered' AND DATE(delivery_date) = CURDATE()");
$shipment_stats['delivered_today'] = $result->fetch_assoc()['total'];

// Delayed shipments (scheduled date passed but not delivered)
$result = $conn->query("SELECT COUNT(*) as total FROM shipments WHERE status IN ('scheduled', 'in_transit') AND scheduled_date < CURDATE()");
$shipment_stats['delayed'] = $result->fetch_assoc()['total'];

// Status breakdown
$result = $conn->query("SELECT status, COUNT(*) as count FROM shipments GROUP BY status");
$status_breakdown = [];
while ($row = $result->fetch_assoc()) {
    $status_breakdown[$row['status']] = $row['count'];
}

closeDBConnection($conn);

$pageTitle = 'Logistics & Transportation Management';
include '../includes/header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-shipping-fast"></i> Logistics & Transportation Management</h2>
    <?php if (canCreate('logistics')): ?>
    <button class="btn btn-primary" onclick="openCreateShipmentModal()" id="scheduleShipmentBtn" style="display: none;">
        <i class="fas fa-plus"></i> Schedule Shipment
    </button>
    <?php endif; ?>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if ($new_supplier_shipments > 0): ?>
    <div class="alert alert-info">
        <i class="fas fa-info-circle"></i> 
        <strong>New Supplier-Scheduled Shipments:</strong> 
        <?php echo $new_supplier_shipments; ?> new shipment(s) have been scheduled by suppliers and are ready for logistics processing.
    </div>
<?php endif; ?>

<!-- Tabs Navigation -->
<div class="tabs-container" style="margin-bottom: 2rem; border-bottom: 2px solid #e2e8f0;">
    <button class="tab-btn active" onclick="switchTab('shipments')" id="shipmentsTab" style="padding: 0.75rem 1.5rem; background: none; border: none; border-bottom: 3px solid #F2ACB9; cursor: pointer; font-weight: 600; color: #F2ACB9; margin-right: 0.5rem;">
        <i class="fas fa-shipping-fast"></i> Shipments
    </button>
    <button class="tab-btn" onclick="switchTab('drivers')" id="driversTab" style="padding: 0.75rem 1.5rem; background: none; border: none; border-bottom: 3px solid transparent; cursor: pointer; font-weight: 500; color: #64748b; margin-right: 0.5rem;">
        <i class="fas fa-user-tie"></i> Drivers
    </button>
    <button class="tab-btn" onclick="switchTab('vehicles')" id="vehiclesTab" style="padding: 0.75rem 1.5rem; background: none; border: none; border-bottom: 3px solid transparent; cursor: pointer; font-weight: 500; color: #64748b;">
        <i class="fas fa-truck"></i> Vehicles
    </button>
</div>

<!-- Shipments Tab Content -->
<div id="shipmentsTabContent" class="tab-content">
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

<div class="filters-bar">
    <div class="filter-group">
        <label>Filter by Status</label>
        <select class="form-control" onchange="window.location.href='?status=' + this.value">
            <option value="">All Statuses</option>
            <option value="scheduled" <?php echo $status_filter == 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
            <option value="in_transit" <?php echo $status_filter == 'in_transit' ? 'selected' : ''; ?>>In Transit</option>
            <option value="delivered" <?php echo $status_filter == 'delivered' ? 'selected' : ''; ?>>Delivered</option>
        </select>
    </div>
</div>

<div class="dashboard-card">
    <div class="card-header">
        <h3><i class="fas fa-list"></i> Shipments</h3>
    </div>
    <div class="card-body">
        <!-- Sub-tabs for Purchase Order and Warehouse Transfer -->
        <div style="border-bottom: 2px solid #e5e7eb; margin-bottom: 1.5rem;">
            <button class="shipment-type-tab-btn active" onclick="switchShipmentType('purchase_order')" id="poShipmentTab" style="padding: 0.75rem 1.5rem; background: none; border: none; border-bottom: 3px solid #F2ACB9; cursor: pointer; font-weight: 600; color: #F2ACB9; margin-right: 0.5rem;">
                <i class="fas fa-shopping-cart"></i> Purchase Order
            </button>
            <?php if ($_SESSION['role'] !== 'procurement_staff'): ?>
            <button class="shipment-type-tab-btn" onclick="switchShipmentType('warehouse_transfer')" id="transferShipmentTab" style="padding: 0.75rem 1.5rem; background: none; border: none; border-bottom: 3px solid transparent; cursor: pointer; font-weight: 500; color: #64748b;">
                <i class="fas fa-exchange-alt"></i> Warehouse Transfer
            </button>
            <?php endif; ?>
        </div>

        <!-- Purchase Order Shipments Tab Content -->
        <div id="poShipmentTabContent" class="shipment-type-content">
            <?php if (empty($po_shipments)): ?>
                <p class="text-muted">No purchase order shipments found.</p>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Shipment #</th>
                                <th>PO Number</th>
                                <th>Origin</th>
                                <th>Destination</th>
                                <th>Supplier</th>
                                <th>Expected Date</th>
                                <th>Scheduled Date</th>
                                <th>Delivery Date</th>
                                <th>Tracking #</th>
                                <th>Vehicle</th>
                                <th>Driver</th>
                                <?php if (canViewFinancialData()): ?>
                                <th>Cost</th>
                                <?php endif; ?>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($po_shipments as $shipment): ?>
                                <tr <?php echo ($shipment['created_by_role'] === 'supplier') ? 'style="background-color: #f0f8ff;"' : ''; ?>>
                                    <td>
                                        <strong><?php echo htmlspecialchars($shipment['shipment_number']); ?></strong>
                                        <?php if ($shipment['created_by_role'] === 'supplier'): ?>
                                            <span class="badge badge-info" title="Scheduled by Supplier">
                                                <i class="fas fa-truck"></i> Supplier Scheduled
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $shipment['po_number'] ?? 'N/A'; ?></td>
                                    <td><?php 
                                        // For inbound shipments, origin is the supplier
                                        if (empty($shipment['origin_warehouse'])) {
                                            echo $shipment['supplier_name'] ?? 'N/A';
                                        } else {
                                            echo $shipment['origin_warehouse'] ?? 'N/A';
                                        }
                                    ?></td>
                                    <td><?php echo $shipment['destination_warehouse'] ?? 'N/A'; ?></td>
                                    <td><?php echo $shipment['supplier_name'] ?? 'N/A'; ?></td>
                                    <td><?php echo $shipment['expected_date'] ? formatDate($shipment['expected_date']) : 'N/A'; ?></td>
                                    <td><?php echo formatDate($shipment['scheduled_date']); ?></td>
                                    <td><?php echo $shipment['delivery_date'] ? formatDate($shipment['delivery_date']) : 'N/A'; ?></td>
                                    <td><?php 
                                        $tracking = $shipment['tracking_number'] ?? null;
                                        // Handle "0" as missing tracking number
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
                                    <?php if (canViewFinancialData()): ?>
                                    <td><?php echo formatCurrency($shipment['transport_cost']); ?></td>
                                    <?php endif; ?>
                                    <td>
                                        <span class="badge badge-<?php echo $shipment['status']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $shipment['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-secondary" onclick="viewShipmentDetails(<?php echo $shipment['id']; ?>)">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <?php 
                                        // Warehouse Officer can approve deliveries (mark as delivered) for inbound shipments
                                        // Logistics Manager can update all shipment statuses
                                        $can_update_shipment = false;
                                        $scheduled_date_passed = false;
                                        
                                        // Check if scheduled date has passed
                                        if ($shipment['scheduled_date']) {
                                            $scheduled = strtotime($shipment['scheduled_date']);
                                            $today = strtotime(date('Y-m-d'));
                                            $scheduled_date_passed = $scheduled < $today;
                                        }
                                        
                                        if ($shipment['status'] != 'delivered' && !$scheduled_date_passed) {
                                            if (canEdit('logistics')) {
                                                $can_update_shipment = true;
                                            } elseif ($shipment['type'] == 'inbound' && canApproveDelivery(['shipment_type' => 'inbound'])) {
                                                $can_update_shipment = true;
                                            }
                                        }
                                        if ($can_update_shipment): 
                                        ?>
                                            <button class="btn btn-sm btn-success" onclick="updateShipmentStatus(<?php echo $shipment['id']; ?>, '<?php echo $shipment['status']; ?>', '<?php echo $shipment['scheduled_date']; ?>')">
                                                <i class="fas fa-edit"></i> <?php echo ($shipment['type'] == 'inbound' && !canEdit('logistics')) ? 'Approve Delivery' : 'Update'; ?>
                                            </button>
                                        <?php elseif ($scheduled_date_passed): ?>
                                            <button class="btn btn-sm btn-secondary" disabled title="Scheduled date (<?php echo $shipment['scheduled_date']; ?>) has passed">
                                                <i class="fas fa-ban"></i> Update Disabled
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

        <?php if ($_SESSION['role'] !== 'procurement_staff'): ?>
        <!-- Warehouse Transfer Shipments Tab Content -->
        <div id="transferShipmentTabContent" class="shipment-type-content" style="display: none;">
            <?php if (empty($transfer_shipments)): ?>
                <p class="text-muted">No warehouse transfer shipments found.</p>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Shipment #</th>
                                <th>Transfer Number</th>
                                <th>Origin</th>
                                <th>Destination</th>
                                <th>Expected Date</th>
                                <th>Scheduled Date</th>
                                <th>Delivery Date</th>
                                <th>Tracking #</th>
                                <th>Vehicle</th>
                                <th>Driver</th>
                                <?php if (canViewFinancialData()): ?>
                                <th>Cost</th>
                                <?php endif; ?>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transfer_shipments as $shipment): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($shipment['shipment_number']); ?></strong>
                                    </td>
                                    <td><?php echo $shipment['transfer_number'] ?? 'N/A'; ?></td>
                                    <td><?php echo $shipment['origin_warehouse'] ?? 'N/A'; ?></td>
                                    <td><?php echo $shipment['destination_warehouse'] ?? 'N/A'; ?></td>
                                    <td><?php echo $shipment['expected_date'] ? formatDate($shipment['expected_date']) : 'N/A'; ?></td>
                                    <td><?php echo formatDate($shipment['scheduled_date']); ?></td>
                                    <td><?php echo $shipment['delivery_date'] ? formatDate($shipment['delivery_date']) : 'N/A'; ?></td>
                                    <td><?php 
                                        $tracking = $shipment['tracking_number'] ?? null;
                                        // Handle "0" as missing tracking number
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
                                    <?php if (canViewFinancialData()): ?>
                                    <td><?php echo formatCurrency($shipment['transport_cost']); ?></td>
                                    <?php endif; ?>
                                    <td>
                                        <span class="badge badge-<?php echo $shipment['status']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $shipment['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-secondary" onclick="viewShipmentDetails(<?php echo $shipment['id']; ?>)">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <?php 
                                        // Logistics Manager can update all shipment statuses
                                        $can_update_shipment = false;
                                        $scheduled_date_passed = false;
                                        
                                        // Check if scheduled date has passed
                                        if ($shipment['scheduled_date']) {
                                            $scheduled = strtotime($shipment['scheduled_date']);
                                            $today = strtotime(date('Y-m-d'));
                                            $scheduled_date_passed = $scheduled < $today;
                                        }
                                        
                                        if ($shipment['status'] != 'delivered' && !$scheduled_date_passed) {
                                            if (canEdit('logistics')) {
                                                $can_update_shipment = true;
                                            }
                                        }
                                        if ($can_update_shipment): 
                                        ?>
                                            <button class="btn btn-sm btn-success" onclick="updateShipmentStatus(<?php echo $shipment['id']; ?>, '<?php echo $shipment['status']; ?>', '<?php echo $shipment['scheduled_date']; ?>')">
                                                <i class="fas fa-edit"></i> Update
                                            </button>
                                        <?php elseif ($scheduled_date_passed): ?>
                                            <button class="btn btn-sm btn-secondary" disabled title="Scheduled date (<?php echo $shipment['scheduled_date']; ?>) has passed">
                                                <i class="fas fa-ban"></i> Update Disabled
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
        <?php endif; ?>
    </div>
</div>
</div>

<!-- Drivers Tab Content -->
<div id="driversTabContent" class="tab-content" style="display: none;">
    <?php if (canCreate('logistics')): ?>
    <div style="margin-bottom: 1.5rem;">
        <button class="btn btn-primary" onclick="openDriverModal()">
            <i class="fas fa-plus"></i> Add Driver
        </button>
    </div>
    <?php endif; ?>
    
    <div class="dashboard-card">
        <div class="card-header">
            <h3><i class="fas fa-user-tie"></i> Drivers</h3>
        </div>
        <div class="card-body">
            <?php if (empty($drivers)): ?>
                <p class="text-muted">No drivers found.</p>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Driver Code</th>
                                <th>Full Name</th>
                                <th>License Number</th>
                                <th>License Type</th>
                                <th>Phone</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($drivers as $driver): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($driver['driver_code']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($driver['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($driver['license_number'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($driver['license_type']); ?></td>
                                    <td><?php echo htmlspecialchars($driver['phone'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($driver['email'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo str_replace('_', '-', $driver['status']); ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $driver['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (canEdit('logistics')): ?>
                                        <button class="btn btn-sm btn-secondary" onclick="editDriver(<?php echo $driver['id']; ?>)">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <?php endif; ?>
                                        <?php if (canDelete('logistics')): ?>
                                        <button class="btn btn-sm btn-danger" onclick="deleteDriver(<?php echo $driver['id']; ?>)">
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
</div>

<!-- Vehicles Tab Content -->
<div id="vehiclesTabContent" class="tab-content" style="display: none;">
    <?php if (canCreate('logistics')): ?>
    <div style="margin-bottom: 1.5rem;">
        <button class="btn btn-primary" onclick="openVehicleModal()">
            <i class="fas fa-plus"></i> Add Vehicle
        </button>
    </div>
    <?php endif; ?>
    
    <div class="dashboard-card">
        <div class="card-header">
            <h3><i class="fas fa-truck"></i> Vehicles</h3>
        </div>
        <div class="card-body">
            <?php if (empty($vehicles)): ?>
                <p class="text-muted">No vehicles found.</p>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Vehicle Number</th>
                                <th>Type</th>
                                <th>Make/Model</th>
                                <th>Year</th>
                                <th>License Plate</th>
                                <th>Capacity</th>
                                <th>Fuel Type</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vehicles as $vehicle): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($vehicle['vehicle_number']); ?></strong></td>
                                    <td><?php echo ucfirst($vehicle['vehicle_type']); ?></td>
                                    <td><?php echo htmlspecialchars(($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars($vehicle['year'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($vehicle['license_plate'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php 
                                        $capacity = [];
                                        if ($vehicle['capacity_kg']) $capacity[] = $vehicle['capacity_kg'] . ' kg';
                                        if ($vehicle['capacity_volume']) $capacity[] = $vehicle['capacity_volume'] . ' m³';
                                        echo !empty($capacity) ? implode(' / ', $capacity) : 'N/A';
                                        ?>
                                    </td>
                                    <td><?php echo ucfirst($vehicle['fuel_type']); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo str_replace('_', '-', $vehicle['status']); ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $vehicle['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (canEdit('logistics')): ?>
                                        <button class="btn btn-sm btn-secondary" onclick="editVehicle(<?php echo $vehicle['id']; ?>)">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <?php endif; ?>
                                        <?php if (canDelete('logistics')): ?>
                                        <button class="btn btn-sm btn-danger" onclick="deleteVehicle(<?php echo $vehicle['id']; ?>)">
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
</div>

<!-- Create Shipment Modal -->
<?php if (canCreate('logistics')): ?>
<div id="createShipmentModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Schedule Shipment</h3>
            <button class="modal-close" onclick="closeModal('createShipmentModal')">&times;</button>
        </div>
        <form method="POST" action="" id="shipmentForm">
            <div class="form-group">
                <label for="source_type">Shipment Type *</label>
                <select name="source_type" id="source_type" class="form-control" required onchange="toggleSourceType(this.value)">
                    <option value="po" selected>Purchase Order</option>
                    <option value="transfer">Warehouse Transfer</option>
                </select>
            </div>
            
            <div class="form-group" id="po_source_group">
                <label for="po_id">Purchase Order (Optional)</label>
                <select name="po_id" id="po_id" class="form-control" onchange="loadPOData(this.value)">
                    <option value="">Select PO</option>
                    <?php foreach ($purchase_orders as $po): ?>
                        <option value="<?php echo $po['id']; ?>"><?php echo htmlspecialchars($po['po_number']); ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Select a PO to auto-populate supplier, items, and quantities</small>
            </div>
            
            <div class="form-group" id="transfer_source_group" style="display: none;">
                <label for="transfer_id">Warehouse Transfer Request *</label>
                <select name="transfer_id" id="transfer_id" class="form-control" onchange="loadTransferData(this.value)">
                    <option value="">Select Transfer Request</option>
                    <?php foreach ($approved_transfers as $transfer): ?>
                        <option value="<?php echo $transfer['id']; ?>" 
                                data-source="<?php echo $transfer['source_warehouse_id']; ?>"
                                data-dest="<?php echo $transfer['destination_warehouse_id']; ?>"
                                data-date="<?php echo $transfer['requested_date']; ?>">
                            <?php echo htmlspecialchars($transfer['transfer_number'] . ' - ' . $transfer['source_warehouse'] . ' → ' . $transfer['destination_warehouse']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Select an approved transfer request to auto-populate warehouses and items</small>
            </div>
            
            <div class="form-group" id="destination_warehouse_group">
                <label for="destination_warehouse_id">Destination Warehouse *</label>
                <select name="destination_warehouse_id" id="destination_warehouse_id" class="form-control" required>
                    <option value="">Select Warehouse</option>
                    <?php foreach ($warehouses as $warehouse): ?>
                        <option value="<?php echo $warehouse['id']; ?>"><?php echo htmlspecialchars($warehouse['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group" id="supplier_group">
                <label for="supplier_id">Supplier (Optional)</label>
                <select name="supplier_id" id="supplier_id" class="form-control" onchange="loadSupplierProducts(this.value)">
                    <option value="">Select Supplier</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?php echo $supplier['id']; ?>"><?php echo htmlspecialchars($supplier['company_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="scheduled_date">Scheduled Date *</label>
                <input type="date" name="scheduled_date" id="scheduled_date" class="form-control" min="<?php echo date('Y-m-d'); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="tracking_number">Tracking Number</label>
                <input type="text" name="tracking_number" id="tracking_number" class="form-control" readonly>
                <small class="text-muted">Tracking number is automatically generated</small>
            </div>
            
            <div class="form-group">
                <label for="vehicle_id">Vehicle</label>
                <select name="vehicle_id" id="vehicle_id" class="form-control">
                    <option value="">Select Vehicle</option>
                    <?php foreach ($vehicles as $vehicle): ?>
                        <option value="<?php echo $vehicle['id']; ?>" <?php echo $vehicle['status'] != 'available' ? 'disabled' : ''; ?>>
                            <?php echo htmlspecialchars($vehicle['vehicle_number'] . ' - ' . ucfirst($vehicle['vehicle_type']) . ($vehicle['status'] != 'available' ? ' (' . ucfirst(str_replace('_', ' ', $vehicle['status'])) . ')' : '')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Select a vehicle for this shipment</small>
            </div>
            
            <div class="form-group">
                <label for="driver_id">Driver</label>
                <select name="driver_id" id="driver_id" class="form-control">
                    <option value="">Select Driver</option>
                    <?php foreach ($drivers as $driver): ?>
                        <option value="<?php echo $driver['id']; ?>" <?php echo $driver['status'] != 'available' ? 'disabled' : ''; ?>>
                            <?php echo htmlspecialchars($driver['full_name'] . ' (' . $driver['driver_code'] . ')' . ($driver['status'] != 'available' ? ' - ' . ucfirst(str_replace('_', ' ', $driver['status'])) : '')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Select a driver for this shipment</small>
            </div>
            
            <div class="form-group">
                <label for="transport_cost">Transport Cost</label>
                <input type="number" name="transport_cost" id="transport_cost" class="form-control" step="0.01" min="0" value="0">
            </div>
            
            <div class="form-group">
                <label>Items *</label>
                <div id="shipmentItems">
                    <div class="shipment-item-row">
                        <select name="items[0][product_id]" class="form-control" required>
                            <option value="">Select Product</option>
                        </select>
                        <input type="number" name="items[0][quantity]" placeholder="Qty" class="form-control" min="1" required>
                        <button type="button" class="btn btn-danger btn-sm" onclick="removeShipmentItem(this)"><i class="fas fa-times"></i></button>
                    </div>
                </div>
                <button type="button" class="btn btn-secondary btn-sm mt-1" onclick="addShipmentItem()">
                    <i class="fas fa-plus"></i> Add Item
                </button>
            </div>
            
            <div class="form-group">
                <button type="submit" name="create_shipment" class="btn btn-primary btn-block">
                    <i class="fas fa-save"></i> Create Shipment
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Update Status Modal -->
<?php if (canEdit('logistics')): ?>
<div id="updateStatusModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Update Shipment Status</h3>
            <button class="modal-close" onclick="closeModal('updateStatusModal')">&times;</button>
        </div>
        <form method="POST" action="" id="statusForm">
            <input type="hidden" name="shipment_id" id="status_shipment_id">
            
            <div class="form-group">
                <label for="status">Status *</label>
                <select name="status" id="status" class="form-control" required onchange="toggleDeliveryDate()">
                    <option value="scheduled">Scheduled</option>
                    <option value="in_transit">In Transit</option>
                    <option value="delivered">Delivered</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
            
            <div class="form-group" id="deliveryDateGroup" style="display:none;">
                <label for="delivery_date">Delivery Date *</label>
                <input type="date" name="delivery_date" id="delivery_date" class="form-control" min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>">
            </div>
            
            <div class="form-group">
                <button type="submit" name="update_shipment_status" class="btn btn-primary btn-block" id="updateStatusBtn">
                    <i class="fas fa-save"></i> Update Status
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Assign Driver and Vehicle Modal -->
<?php if (canEdit('logistics')): ?>
<div id="assignDriverVehicleModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-user-tie"></i> Assign Driver and Vehicle</h3>
            <button class="modal-close" onclick="closeModal('assignDriverVehicleModal')">&times;</button>
        </div>
        <form method="POST" action="" id="assignDriverVehicleForm">
            <input type="hidden" name="shipment_id" id="assign_shipment_id">
            
            <div class="form-group">
                <label for="assign_vehicle_id">Vehicle</label>
                <select name="vehicle_id" id="assign_vehicle_id" class="form-control">
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
                <label for="assign_driver_id">Driver</label>
                <select name="driver_id" id="assign_driver_id" class="form-control">
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
            
            <div class="form-group">
                <button type="submit" name="assign_driver_vehicle" class="btn btn-primary btn-block">
                    <i class="fas fa-save"></i> Assign Driver and Vehicle
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Shipment Details Modal -->
<div id="shipmentDetailsModal" class="modal">
    <div class="modal-content" style="max-width: 1000px;">
        <div class="modal-header">
            <h3><i class="fas fa-info-circle"></i> Shipment Details</h3>
            <button class="modal-close" onclick="closeModal('shipmentDetailsModal')">&times;</button>
        </div>
        <div class="card-body" id="shipmentDetailsContent">
            <p class="text-muted">Loading...</p>
        </div>
    </div>
</div>

<!-- Driver Modal -->
<?php if (canCreate('logistics')): ?>
<div id="driverModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="driverModalTitle">Add Driver</h3>
            <button class="modal-close" onclick="closeModal('driverModal')">&times;</button>
        </div>
        <form method="POST" action="" id="driverForm">
            <input type="hidden" name="driver_id" id="driver_id">
            
            <div class="form-group">
                <label for="driver_code">Driver Code *</label>
                <input type="text" name="driver_code" id="driver_code" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label for="full_name">Full Name *</label>
                <input type="text" name="full_name" id="full_name" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label for="license_number">License Number</label>
                <input type="text" name="license_number" id="license_number" class="form-control">
            </div>
            
            <div class="form-group">
                <label for="license_type">License Type *</label>
                <select name="license_type" id="license_type" class="form-control" required>
                    <option value="A">A</option>
                    <option value="B" selected>B</option>
                    <option value="C">C</option>
                    <option value="D">D</option>
                    <option value="E">E</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="license_expiry">License Expiry</label>
                <input type="date" name="license_expiry" id="license_expiry" class="form-control">
            </div>
            
            <div class="form-group">
                <label for="phone">Phone</label>
                <input type="text" name="phone" id="phone" class="form-control">
            </div>
            
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" name="email" id="email" class="form-control">
            </div>
            
            <div class="form-group">
                <label for="address">Address</label>
                <textarea name="address" id="address" class="form-control" rows="3"></textarea>
            </div>
            
            <div class="form-group">
                <label for="driver_status">Status *</label>
                <select name="status" id="driver_status" class="form-control" required>
                    <option value="available" selected>Available</option>
                    <option value="on_duty">On Duty</option>
                    <option value="off_duty">Off Duty</option>
                    <option value="sick_leave">Sick Leave</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="hire_date">Hire Date</label>
                <input type="date" name="hire_date" id="hire_date" class="form-control">
            </div>
            
            <div class="form-group">
                <label for="driver_notes">Notes</label>
                <textarea name="notes" id="driver_notes" class="form-control" rows="3"></textarea>
            </div>
            
            <div class="form-group">
                <button type="submit" name="save_driver" class="btn btn-primary btn-block">
                    <i class="fas fa-save"></i> Save Driver
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Vehicle Modal -->
<?php if (canCreate('logistics')): ?>
<div id="vehicleModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="vehicleModalTitle">Add Vehicle</h3>
            <button class="modal-close" onclick="closeModal('vehicleModal')">&times;</button>
        </div>
        <form method="POST" action="" id="vehicleForm">
            <input type="hidden" name="vehicle_id" id="vehicle_id">
            
            <div class="form-group">
                <label for="vehicle_number">Vehicle Number *</label>
                <input type="text" name="vehicle_number" id="vehicle_number" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label for="vehicle_type">Vehicle Type *</label>
                <select name="vehicle_type" id="vehicle_type" class="form-control" required>
                    <option value="truck" selected>Truck</option>
                    <option value="van">Van</option>
                    <option value="container">Container</option>
                    <option value="trailer">Trailer</option>
                    <option value="other">Other</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="make">Make</label>
                <input type="text" name="make" id="make" class="form-control">
            </div>
            
            <div class="form-group">
                <label for="model">Model</label>
                <input type="text" name="model" id="model" class="form-control">
            </div>
            
            <div class="form-group">
                <label for="year">Year</label>
                <input type="number" name="year" id="year" class="form-control" min="1900" max="<?php echo date('Y') + 1; ?>">
            </div>
            
            <div class="form-group">
                <label for="license_plate">License Plate</label>
                <input type="text" name="license_plate" id="license_plate" class="form-control">
            </div>
            
            <div class="form-group">
                <label for="capacity_kg">Capacity (kg)</label>
                <input type="number" name="capacity_kg" id="capacity_kg" class="form-control" step="0.01" min="0">
            </div>
            
            <div class="form-group">
                <label for="capacity_volume">Capacity (m³)</label>
                <input type="number" name="capacity_volume" id="capacity_volume" class="form-control" step="0.01" min="0">
            </div>
            
            <div class="form-group">
                <label for="fuel_type">Fuel Type *</label>
                <select name="fuel_type" id="fuel_type" class="form-control" required>
                    <option value="diesel" selected>Diesel</option>
                    <option value="gasoline">Gasoline</option>
                    <option value="electric">Electric</option>
                    <option value="hybrid">Hybrid</option>
                    <option value="other">Other</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="vehicle_status">Status *</label>
                <select name="status" id="vehicle_status" class="form-control" required>
                    <option value="available" selected>Available</option>
                    <option value="in_use">In Use</option>
                    <option value="maintenance">Maintenance</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="current_location">Current Location</label>
                <input type="text" name="current_location" id="current_location" class="form-control">
            </div>
            
            <div class="form-group">
                <label for="last_maintenance_date">Last Maintenance Date</label>
                <input type="date" name="last_maintenance_date" id="last_maintenance_date" class="form-control">
            </div>
            
            <div class="form-group">
                <label for="next_maintenance_date">Next Maintenance Date</label>
                <input type="date" name="next_maintenance_date" id="next_maintenance_date" class="form-control">
            </div>
            
            <div class="form-group">
                <label for="vehicle_notes">Notes</label>
                <textarea name="notes" id="vehicle_notes" class="form-control" rows="3"></textarea>
            </div>
            
            <div class="form-group">
                <button type="submit" name="save_vehicle" class="btn btn-primary btn-block">
                    <i class="fas fa-save"></i> Save Vehicle
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Route Planning Modal -->
<div id="routePlanningModal" class="modal">
    <div class="modal-content" style="max-width: 900px;">
        <div class="modal-header">
            <h3><i class="fas fa-route"></i> Plan Shipping Route</h3>
            <button class="modal-close" onclick="closeModal('routePlanningModal')">&times;</button>
        </div>
        <div class="card-body">
            <div id="routeMap" style="height: 400px; width: 100%; margin-bottom: 1rem; border: 1px solid #ddd; border-radius: 4px;"></div>
            
            <div id="routeInfo" style="display: none;">
                <div class="stats-grid" style="grid-template-columns: repeat(3, 1fr); margin-bottom: 1rem;">
                    <div class="stat-card">
                        <div class="stat-icon blue">
                            <i class="fas fa-route"></i>
                        </div>
                        <div class="stat-info">
                            <h3 id="routeDistance">0 km</h3>
                            <p>Distance</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon green">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-info">
                            <h3 id="routeETA">0 min</h3>
                            <p>Estimated Time</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon orange">
                            <i class="fas fa-map-marker-alt"></i>
                        </div>
                        <div class="stat-info">
                            <h3 id="routeStops">2</h3>
                            <p>Stops</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <button type="button" class="btn btn-secondary" onclick="clearRoute()">
                    <i class="fas fa-redo"></i> Clear Route
                </button>
                <button type="button" class="btn btn-primary" onclick="saveRoute()" id="saveRouteBtn" style="display: none;">
                    <i class="fas fa-save"></i> Save Route
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let shipmentItemCount = 1;
const products = <?php echo json_encode($products); ?>;
const suppliers = <?php echo json_encode($suppliers); ?>;
let filteredProducts = []; // Products filtered by selected supplier

// Function to load products for a selected supplier
function loadSupplierProducts(supplierId) {
    const productSelects = document.querySelectorAll('#shipmentItems select[name*="[product_id]"]');
    
    if (!supplierId) {
        // Reset to all products if no supplier selected (empty array means show all)
        filteredProducts = [];
        updateProductSelects();
        return;
    }
    
    // Show loading state
    productSelects.forEach(select => {
        const currentValue = select.value;
        select.innerHTML = '<option value="">Loading products...</option>';
        select.disabled = true;
    });
    
    // Fetch products for this supplier
    fetch('?ajax=get_products&supplier_id=' + supplierId)
        .then(response => response.json())
        .then(supplierProducts => {
            // Only show products that belong to this supplier
            filteredProducts = supplierProducts;
            updateProductSelects();
        })
        .catch(error => {
            console.error('Error loading products:', error);
            productSelects.forEach(select => {
                select.innerHTML = '<option value="">Error loading products</option>';
            });
            filteredProducts = [];
        });
}

// Helper function to build product options grouped by supplier
function buildProductOptionsGrouped(productsList, currentValue = '') {
    let html = '<option value="">Select Product</option>';
    
    if (!productsList || productsList.length === 0) {
        return html;
    }
    
    // Group products by supplier
    const productsBySupplier = {};
    productsList.forEach(product => {
        const supplierName = product.supplier_name || 'No Supplier';
        if (!productsBySupplier[supplierName]) {
            productsBySupplier[supplierName] = [];
        }
        productsBySupplier[supplierName].push(product);
    });
    
    // Sort suppliers alphabetically
    const sortedSuppliers = Object.keys(productsBySupplier).sort();
    
    // Build optgroups
    sortedSuppliers.forEach(supplierName => {
        html += `<optgroup label="${escapeHtml(supplierName)}">`;
        productsBySupplier[supplierName].forEach(product => {
            let displayName = product.name + ' (' + product.sku;
            if (product.supplier_sku) {
                displayName += ' / ' + product.supplier_sku;
            }
            displayName += ')';
            if (product.is_primary) {
                displayName += ' [Primary]';
            }
            const selected = product.id == currentValue ? 'selected' : '';
            html += `<option value="${product.id}" ${selected}>${escapeHtml(displayName)}</option>`;
        });
        html += '</optgroup>';
    });
    
    return html;
}

// Function to update all product dropdowns with filtered products
function updateProductSelects() {
    const productSelects = document.querySelectorAll('#shipmentItems select[name*="[product_id]"]');
    const supplierSelect = document.getElementById('supplier_id');
    const hasSupplier = supplierSelect && supplierSelect.value;
    
    productSelects.forEach(select => {
        const currentValue = select.value;
        let html = '<option value="">Select Product</option>';
        
        // If supplier is selected, show only supplier's products
        if (hasSupplier && filteredProducts.length > 0) {
            // For filtered products (single supplier), show products from that supplier
            filteredProducts.forEach(product => {
                const displayName = product.supplier_sku 
                    ? `${product.name} (${product.sku} / ${product.supplier_sku})`
                    : `${product.name} (${product.sku})`;
                const primaryBadge = product.is_primary ? ' [Primary]' : '';
                const selected = product.id == currentValue ? 'selected' : '';
                html += `<option value="${product.id}" ${selected}>${displayName}${primaryBadge}</option>`;
            });
        } else if (hasSupplier && filteredProducts.length === 0) {
            // Supplier selected but no products found
            html = '<option value="">No products available for this supplier</option>';
        } else {
            // No supplier selected or supplier products not loaded yet - show all products grouped by supplier
            html = buildProductOptionsGrouped(products, currentValue);
        }
        
        select.innerHTML = html;
        select.disabled = false;
    });
}

// Function to restore supplier options from the suppliers array
function restoreSupplierOptions() {
    const supplierSelect = document.getElementById('supplier_id');
    if (!supplierSelect) return;
    
    // Build options from suppliers array
    let html = '<option value="">Select Supplier</option>';
    if (suppliers && suppliers.length > 0) {
        suppliers.forEach(supplier => {
            html += `<option value="${supplier.id}">${escapeHtml(supplier.company_name)}</option>`;
        });
    }
    supplierSelect.innerHTML = html;
    supplierSelect.disabled = false;
}

// Helper function to escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Function to generate tracking number
function generateTrackingNumber() {
    const prefix = 'TRK';
    const date = new Date();
    const dateStr = date.getFullYear().toString() + 
                   (date.getMonth() + 1).toString().padStart(2, '0') + 
                   date.getDate().toString().padStart(2, '0');
    const randomStr = Math.random().toString(36).substring(2, 8).toUpperCase();
    return prefix + '-' + dateStr + '-' + randomStr;
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Ensure supplier options are available
    restoreSupplierOptions();
});

// Function to open create shipment modal with tracking number
window.openCreateShipmentModal = function() {
    const trackingInput = document.getElementById('tracking_number');
    if (trackingInput) {
        trackingInput.value = generateTrackingNumber();
    }
    // Restore supplier options if they were cleared
    restoreSupplierOptions();
    // Reset filtered products
    filteredProducts = [];
    // Reset form fields
    const poSelect = document.getElementById('po_id');
    const transferSelect = document.getElementById('transfer_id');
    const supplierSelect = document.getElementById('supplier_id');
    const destSelect = document.getElementById('destination_warehouse_id');
    const scheduledDateInput = document.getElementById('scheduled_date');
    const transportCostInput = document.getElementById('transport_cost');
    const sourcePO = document.getElementById('source_po');
    const sourceTransfer = document.getElementById('source_transfer');
    
    // Reset source type to PO
    if (sourcePO) sourcePO.checked = true;
    if (sourceTransfer) sourceTransfer.checked = false;
    toggleSourceType('po');
    
    if (poSelect) poSelect.value = '';
    if (transferSelect) transferSelect.value = '';
    if (supplierSelect) supplierSelect.value = '';
    if (destSelect) {
        destSelect.value = '';
        destSelect.disabled = false;
    }
    if (scheduledDateInput) scheduledDateInput.value = '';
    if (transportCostInput) transportCostInput.value = '0';
    
    toggleShipmentFields();
    clearShipmentItems();
    // Update product selects
    updateProductSelects();
    // Open the modal
    openModal('createShipmentModal');
}

window.toggleSourceType = function(type) {
    const poGroup = document.getElementById('po_source_group');
    const transferGroup = document.getElementById('transfer_source_group');
    const destWarehouseGroup = document.getElementById('destination_warehouse_group');
    const supplierGroup = document.getElementById('supplier_group');
    const poSelect = document.getElementById('po_id');
    const transferSelect = document.getElementById('transfer_id');
    const destSelect = document.getElementById('destination_warehouse_id');
    const supplierSelect = document.getElementById('supplier_id');
    
    if (type === 'transfer') {
        // Show transfer group, hide PO group and supplier group
        if (poGroup) poGroup.style.display = 'none';
        if (transferGroup) transferGroup.style.display = 'block';
        if (destWarehouseGroup) destWarehouseGroup.style.display = 'none'; // Hide since transfer will set it
        if (supplierGroup) supplierGroup.style.display = 'none'; // Hide supplier for transfers
        
        // Clear PO selection
        if (poSelect) poSelect.value = '';
        
        // Clear items and supplier
        clearShipmentItems();
        if (supplierSelect) supplierSelect.value = '';
    } else {
        // Show PO group and supplier group, hide transfer group
        if (poGroup) poGroup.style.display = 'block';
        if (transferGroup) transferGroup.style.display = 'none';
        if (destWarehouseGroup) destWarehouseGroup.style.display = 'block';
        if (supplierGroup) supplierGroup.style.display = 'block'; // Show supplier for PO
        
        // Clear transfer selection
        if (transferSelect) transferSelect.value = '';
        if (destSelect) {
            destSelect.value = '';
            destSelect.disabled = false;
        }
        
        // Clear items and supplier
        clearShipmentItems();
        if (supplierSelect) supplierSelect.value = '';
    }
};

window.loadTransferData = function(transferId) {
    const supplierGroup = document.getElementById('supplier_group');
    const destSelect = document.getElementById('destination_warehouse_id');
    const scheduledDateInput = document.getElementById('scheduled_date');
    const destWarehouseGroup = document.getElementById('destination_warehouse_group');
    
    if (!transferId) {
        // Hide supplier group when transfer is cleared
        if (supplierGroup) supplierGroup.style.display = 'block';
        
        if (destSelect) {
            destSelect.value = '';
            destSelect.disabled = false;
        }
        if (scheduledDateInput) scheduledDateInput.value = '';
        if (destWarehouseGroup) destWarehouseGroup.style.display = 'block';
        clearShipmentItems();
        return;
    }
    
    // Hide supplier group for transfer shipments
    if (supplierGroup) supplierGroup.style.display = 'none';
    
    const transferSelect = document.getElementById('transfer_id');
    const selectedOption = transferSelect.options[transferSelect.selectedIndex];
    const sourceWarehouseId = selectedOption.getAttribute('data-source');
    const destWarehouseId = selectedOption.getAttribute('data-dest');
    const requestedDate = selectedOption.getAttribute('data-date');
    
    // Set destination warehouse (readonly when transfer is selected)
    if (destSelect && destWarehouseId) {
        destSelect.value = destWarehouseId;
        destSelect.disabled = true;
    }
    if (destWarehouseGroup) destWarehouseGroup.style.display = 'block';
    
    // Set scheduled date
    if (scheduledDateInput && requestedDate) {
        scheduledDateInput.value = requestedDate;
    }
    
    // Load transfer items via AJAX
    fetch('?ajax=get_transfer_shipment_data&transfer_id=' + transferId)
        .then(response => response.json())
        .then(data => {
            if (!data || !data.id) {
                alert('Transfer request not found.');
                return;
            }
            
            if (data.items && data.items.length > 0) {
                clearShipmentItems();
                // Wait a bit for items to clear, then add transfer items
                setTimeout(() => {
                    data.items.forEach((item) => {
                        addShipmentItemFromTransfer(item);
                    });
                    updateProductSelects();
                }, 100);
            } else {
                alert('No items found in this transfer request.');
            }
        })
        .catch(error => {
            console.error('Error loading transfer data:', error);
            alert('Error loading transfer data. Please try again.');
        });
};

window.loadPOData = function(poId) {
    if (!poId) {
        document.getElementById('supplier_id').value = '';
        clearShipmentItems();
        restoreSupplierOptions();
        return;
    }
    
    const supplierSelect = document.getElementById('supplier_id');
    // Don't clear options, just show loading state
    const currentValue = supplierSelect.value;
    supplierSelect.disabled = true;
    
    // Temporarily show loading in a way that doesn't destroy options
    const loadingOption = document.createElement('option');
    loadingOption.value = '';
    loadingOption.textContent = 'Loading...';
    loadingOption.disabled = true;
    supplierSelect.insertBefore(loadingOption, supplierSelect.firstChild);
    
    fetch('?ajax=get_po_shipment_data&po_id=' + poId)
        .then(response => response.json())
        .then(data => {
            // Remove loading option
            const loadingOpt = supplierSelect.querySelector('option[disabled]');
            if (loadingOpt) loadingOpt.remove();
            
            if (!data || !data.id) {
                alert('Purchase order not found.');
                supplierSelect.disabled = false;
                restoreSupplierOptions();
                return;
            }
            
            // Ensure supplier options are restored before setting value
            restoreSupplierOptions();
            if (data.supplier_id) {
                supplierSelect.value = data.supplier_id;
                supplierSelect.disabled = false;
                // Load products for the selected supplier
                loadSupplierProducts(data.supplier_id);
            } else {
                supplierSelect.disabled = false;
            }
            
            if (data.items && data.items.length > 0) {
                clearShipmentItems();
                // Wait for products to load if supplier was set
                const loadItems = () => {
                    data.items.forEach((item, index) => {
                        if (index === 0) {
                            const firstRow = document.querySelector('#shipmentItems .shipment-item-row');
                            if (firstRow) {
                                const productSelect = firstRow.querySelector('select[name*="[product_id]"]');
                                const quantityInput = firstRow.querySelector('input[name*="[quantity]"]');
                                if (productSelect) {
                                    // Update the select options first, then set value
                                    updateProductSelects();
                                    setTimeout(() => {
                                        productSelect.value = item.product_id;
                                    }, 100);
                                }
                                if (quantityInput) quantityInput.value = item.quantity;
                            }
                        } else {
                            addShipmentItemFromPO(item);
                        }
                    });
                };
                
                // If supplier products are being loaded, wait a bit for them to load
                if (data.supplier_id) {
                    setTimeout(loadItems, 500);
                } else {
                    loadItems();
                }
            }
        })
        .catch(error => {
            console.error('Error loading PO data:', error);
            alert('Error loading purchase order data.');
            // Remove loading option
            const loadingOpt = supplierSelect.querySelector('option[disabled]');
            if (loadingOpt) loadingOpt.remove();
            restoreSupplierOptions();
            supplierSelect.disabled = false;
        });
}

function clearShipmentItems() {
    const container = document.getElementById('shipmentItems');
    container.innerHTML = '';
    shipmentItemCount = 0;
    addShipmentItem();
}

function addShipmentItemFromPO(item) {
    const container = document.getElementById('shipmentItems');
    const newRow = document.createElement('div');
    newRow.className = 'shipment-item-row';
    
    // Check supplier selection
    const supplierSelect = document.getElementById('supplier_id');
    const hasSupplier = supplierSelect && supplierSelect.value;
    
    // Build product options based on supplier selection
    let productOptions = '<option value="">Select Product</option>';
    
    // If supplier is selected, show only supplier's products
    if (hasSupplier && filteredProducts.length > 0) {
        // Supplier selected - show only supplier's products
        filteredProducts.forEach(product => {
            const selected = product.id == item.product_id ? 'selected' : '';
            const displayName = product.supplier_sku 
                ? `${product.name} (${product.sku} / ${product.supplier_sku})`
                : `${product.name} (${product.sku})`;
            const primaryBadge = product.is_primary ? ' [Primary]' : '';
            productOptions += `<option value="${product.id}" ${selected}>${displayName}${primaryBadge}</option>`;
        });
    } else if (hasSupplier && filteredProducts.length === 0) {
        // Supplier selected but no products found
        productOptions = '<option value="">No products available for this supplier</option>';
    } else {
        // No supplier selected - show all products grouped by supplier
        productOptions = buildProductOptionsGrouped(products, item.product_id);
    }
    
    newRow.innerHTML = `
        <select name="items[${shipmentItemCount}][product_id]" class="form-control" required>
            ${productOptions}
        </select>
        <input type="number" name="items[${shipmentItemCount}][quantity]" placeholder="Qty" class="form-control" min="1" required value="${item.quantity}">
        <button type="button" class="btn btn-danger btn-sm" onclick="removeShipmentItem(this)"><i class="fas fa-times"></i></button>
    `;
    container.appendChild(newRow);
    shipmentItemCount++;
}

function addShipmentItemFromTransfer(item) {
    const container = document.getElementById('shipmentItems');
    const newRow = document.createElement('div');
    newRow.className = 'shipment-item-row';
    
    // Build product options - show all products since transfer doesn't use supplier
    let productOptions = '<option value="">Select Product</option>';
    productOptions = buildProductOptionsGrouped(products, item.product_id);
    
    newRow.innerHTML = `
        <select name="items[${shipmentItemCount}][product_id]" class="form-control" required>
            ${productOptions}
        </select>
        <input type="number" name="items[${shipmentItemCount}][quantity]" placeholder="Qty" class="form-control" min="1" required value="${item.quantity}">
        <button type="button" class="btn btn-danger btn-sm" onclick="removeShipmentItem(this)"><i class="fas fa-times"></i></button>
    `;
    container.appendChild(newRow);
    shipmentItemCount++;
}

function toggleShipmentFields() {
    // All fields are now always visible, so we just update product selects based on supplier selection
    const supplierSelect = document.getElementById('supplier_id');
    
    // If supplier is selected, filter products for that supplier
    if (supplierSelect && supplierSelect.value) {
        loadSupplierProducts(supplierSelect.value);
    } else {
        // No supplier selected, show all products
        filteredProducts = [];
        updateProductSelects();
    }
}

window.addShipmentItem = function() {
    const container = document.getElementById('shipmentItems');
    const newRow = document.createElement('div');
    newRow.className = 'shipment-item-row';
    
    // Check supplier selection
    const supplierSelect = document.getElementById('supplier_id');
    const hasSupplier = supplierSelect && supplierSelect.value;
    
    // Build product options based on supplier selection
    let productOptions = '<option value="">Select Product</option>';
    
    // If supplier is selected, show only supplier's products
    if (hasSupplier && filteredProducts.length > 0) {
        // Supplier selected - show only supplier's products
        filteredProducts.forEach(product => {
            const displayName = product.supplier_sku 
                ? `${product.name} (${product.sku} / ${product.supplier_sku})`
                : `${product.name} (${product.sku})`;
            const primaryBadge = product.is_primary ? ' [Primary]' : '';
            productOptions += `<option value="${product.id}">${displayName}${primaryBadge}</option>`;
        });
    } else if (hasSupplier && filteredProducts.length === 0) {
        // Supplier selected but no products found
        productOptions = '<option value="">No products available for this supplier</option>';
    } else {
        // No supplier selected - show all products grouped by supplier
        productOptions = buildProductOptionsGrouped(products);
    }
    
    newRow.innerHTML = `
        <select name="items[${shipmentItemCount}][product_id]" class="form-control" required>
            ${productOptions}
        </select>
        <input type="number" name="items[${shipmentItemCount}][quantity]" placeholder="Qty" class="form-control" min="1" required>
        <button type="button" class="btn btn-danger btn-sm" onclick="removeShipmentItem(this)"><i class="fas fa-times"></i></button>
    `;
    container.appendChild(newRow);
    shipmentItemCount++;
}

window.removeShipmentItem = function(btn) {
    btn.closest('.shipment-item-row').remove();
}

function viewShipmentDetails(shipmentId) {
    const modal = document.getElementById('shipmentDetailsModal');
    const content = document.getElementById('shipmentDetailsContent');
    
    content.innerHTML = '<p class="text-muted">Loading...</p>';
    openModal('shipmentDetailsModal');
    
    fetch('?ajax=get_shipment_details&shipment_id=' + shipmentId)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                content.innerHTML = '<p class="text-danger">' + data.error + '</p>';
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
                        </div>
                        
                        <div style="margin-bottom: 1.5rem;">
                            <h4>Route Information</h4>
                            <table class="data-table">
                                <tr>
                                    <th>Origin</th>
                                    <td>${(data.type === 'inbound' && !data.origin_warehouse) ? (data.supplier_name || 'N/A') : (data.origin_warehouse || 'N/A')}</td>
                                </tr>
                                <tr>
                                    <th>Destination</th>
                                    <td>${data.destination_warehouse || 'N/A'}</td>
                                </tr>
                                ${data.type === 'inbound' ? `
                                <tr>
                                    <th>Supplier</th>
                                    <td>${data.supplier_name || 'N/A'}</td>
                                </tr>
                                <tr>
                                    <th>PO Number</th>
                                    <td>${data.po_number || 'N/A'}</td>
                                </tr>
                                ` : ''}
                            </table>
                        </div>
                        
                        ${canEditLogistics && data.status === 'scheduled' ? `
                        <div style="margin-bottom: 1.5rem;">
                            <h4>Assign Driver and Vehicle</h4>
                            <form method="POST" action="" id="assignDriverVehicleForm_${data.id}" onsubmit="return submitDriverVehicleAssignment(event, ${data.id})">
                                <input type="hidden" name="shipment_id" value="${data.id}">
                                
                                <div class="form-group">
                                    <label for="assign_vehicle_id_${data.id}">Vehicle</label>
                                    <select name="vehicle_id" id="assign_vehicle_id_${data.id}" class="form-control">
                                        <option value="">-- Select Vehicle --</option>
                                        ${vehicles.map(vehicle => `
                                            <option value="${vehicle.id}" ${data.vehicle_id == vehicle.id ? 'selected' : ''}>
                                                ${escapeHtml(vehicle.vehicle_number)} ${vehicle.vehicle_type ? '(' + escapeHtml(vehicle.vehicle_type.charAt(0).toUpperCase() + vehicle.vehicle_type.slice(1)) + ')' : ''} ${vehicle.status != 'available' ? '- ' + escapeHtml(vehicle.status.charAt(0).toUpperCase() + vehicle.status.slice(1).replace('_', ' ')) : ''}
                                            </option>
                                        `).join('')}
                                    </select>
                                    <small class="text-muted">Select a vehicle to assign to this shipment</small>
                                </div>
                                
                                <div class="form-group">
                                    <label for="assign_driver_id_${data.id}">Driver</label>
                                    <select name="driver_id" id="assign_driver_id_${data.id}" class="form-control">
                                        <option value="">-- Select Driver --</option>
                                        ${drivers.map(driver => `
                                            <option value="${driver.id}" ${data.driver_id == driver.id ? 'selected' : ''}>
                                                ${escapeHtml(driver.full_name)} (${escapeHtml(driver.driver_code)}) ${driver.status != 'available' ? '- ' + escapeHtml(driver.status.charAt(0).toUpperCase() + driver.status.slice(1).replace('_', ' ')) : ''}
                                            </option>
                                        `).join('')}
                                    </select>
                                    <small class="text-muted">Select a driver to assign to this shipment</small>
                                </div>
                                
                                <div class="form-group">
                                    <button type="submit" name="assign_driver_vehicle" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Save Assignment
                                    </button>
                                </div>
                            </form>
                        </div>
                        ` : (data.vehicle_number || data.driver_name) ? `
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
                                    <th>Expected Date</th>
                                    <td>${data.expected_date ? formatDate(data.expected_date) : 'N/A'}</td>
                                </tr>
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
                            ` : '<p class="text-muted">No items found.</p>'}
                        </div>
                    </div>
                    
                    <!-- Map Routes Tab Content -->
                    ${(data.origin_warehouse_id || data.destination_warehouse_id || data.supplier_id) ? `
                    <div id="routeTabContent_${data.id}" class="shipment-tab-content" style="display: none;">
                        <div style="display: flex; justify-content: flex-end; margin-bottom: 1rem;">
                            <button type="button" class="btn btn-primary btn-sm" onclick="loadRouteInDetails(${data.id}, '${data.type}', ${data.origin_warehouse_id || 'null'}, ${data.destination_warehouse_id || 'null'}, ${data.supplier_id || 'null'})">
                                <i class="fas fa-sync-alt"></i> Refresh Route
                            </button>
                        </div>
                        ${data.status === 'in_transit' ? `
                        <div style="margin-bottom: 2rem; padding-bottom: 2rem; border-bottom: 2px solid #ddd;">
                            ${canEditLogistics ? `
                            <h4 style="margin-bottom: 1rem;"><i class="fas fa-map-marker-alt"></i> Update Current Location</h4>
                            <form id="updateLocationForm_${data.id}" onsubmit="updateCurrentLocation(event, ${data.id})" style="background: #f9f9f9; padding: 1.5rem; border-radius: 8px; margin-bottom: 2rem;">
                                <div style="display: grid; grid-template-columns: 1fr 1fr 2fr; gap: 1rem; margin-bottom: 1rem;">
                                    <div class="form-group">
                                        <label for="location_date_${data.id}"><strong>Date *</strong></label>
                                        <input type="date" id="location_date_${data.id}" name="location_date" class="form-control" value="${new Date().toISOString().split('T')[0]}" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="location_time_${data.id}"><strong>Time *</strong></label>
                                        <input type="time" id="location_time_${data.id}" name="location_time" class="form-control" value="${new Date().toTimeString().slice(0,5)}" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="location_name_${data.id}"><strong>Location Name *</strong></label>
                                        <input type="text" id="location_name_${data.id}" name="location_name" class="form-control" placeholder="e.g., Dasma Area" required>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Update Location
                                </button>
                            </form>
                            ` : ''}
                            
                            <div>
                                <h4 style="margin-bottom: 1rem;"><i class="fas fa-history"></i> Location History</h4>
                                <div id="locationHistoryTable_${data.id}" style="background: white; border: 1px solid #ddd; border-radius: 4px; overflow: hidden;">
                                    <table class="data-table" style="margin: 0;">
                                        <thead>
                                            <tr>
                                                <th>Date & Time</th>
                                                <th>Location</th>
                                            </tr>
                                        </thead>
                                        <tbody id="locationHistoryBody_${data.id}">
                                            <tr>
                                                <td colspan="2" style="text-align: center; padding: 1rem;">
                                                    <i class="fas fa-spinner fa-spin"></i> Loading...
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        ` : ''}
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
            
            // Load location history if status is in_transit
            if (shipmentData.status === 'in_transit') {
                loadLocationHistoryTable(shipmentId);
            }
        }, 100);
    }
};

// Update current location
function updateCurrentLocation(event, shipmentId) {
    event.preventDefault();
    
    const form = event.target;
    const formData = new FormData(form);
    formData.append('shipment_id', shipmentId);
    formData.append('ajax', 'update_location');
    
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success message
            const successMsg = document.createElement('div');
            successMsg.className = 'alert alert-success';
            successMsg.style.marginBottom = '1rem';
            successMsg.innerHTML = '<i class="fas fa-check-circle"></i> ' + data.message;
            
            // Remove existing alert if any
            const existingAlert = form.parentElement.querySelector('.alert');
            if (existingAlert) {
                existingAlert.remove();
            }
            
            form.parentElement.insertBefore(successMsg, form);
            
            // Clear form
            form.reset();
            // Set default date and time
            document.getElementById(`location_date_${shipmentId}`).value = new Date().toISOString().split('T')[0];
            document.getElementById(`location_time_${shipmentId}`).value = new Date().toTimeString().slice(0,5);
            
            // Auto-hide success message after 3 seconds
            setTimeout(() => {
                successMsg.remove();
            }, 3000);
            
            // Reload location history table
            loadLocationHistoryTable(shipmentId);
        } else {
            alert('Error: ' + (data.error || 'Failed to update location'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error updating location. Please try again.');
    })
    .finally(() => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
}

// Load location history table
function loadLocationHistoryTable(shipmentId) {
    const historyBody = document.getElementById(`locationHistoryBody_${shipmentId}`);
    if (!historyBody) return;
    
    historyBody.innerHTML = '<tr><td colspan="2" style="text-align: center; padding: 1rem;"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>';
    
    fetch(`?ajax=get_location_history&shipment_id=${shipmentId}`)
        .then(response => response.json())
        .then(data => {
            if (data.error || !data.history || data.history.length === 0) {
                historyBody.innerHTML = '<tr><td colspan="2" style="text-align: center; padding: 1rem; color: #999;">No location history available yet.</td></tr>';
                return;
            }
            
            // Display in reverse chronological order (newest first)
            let html = '';
            data.history.slice().reverse().forEach(location => {
                const time = new Date(location.recorded_at);
                const locationName = location.location_name || 'Unknown Location';
                
                html += `
                    <tr>
                        <td>${time.toLocaleDateString()} ${time.toLocaleTimeString()}</td>
                        <td><strong>${escapeHtml(locationName)}</strong></td>
                    </tr>
                `;
            });
            
            historyBody.innerHTML = html;
        })
        .catch(error => {
            console.error('Error loading location history:', error);
            historyBody.innerHTML = '<tr><td colspan="2" style="text-align: center; padding: 1rem; color: #dc3545;">Error loading location history.</td></tr>';
        });
}

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
    return date.toLocaleDateString();
}

function formatCurrency(amount) {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD'
    }).format(amount || 0);
}

function updateShipmentStatus(shipmentId, currentStatus, scheduledDate) {
    document.getElementById('status_shipment_id').value = shipmentId;
    const statusSelect = document.getElementById('status');
    const updateBtn = document.getElementById('updateStatusBtn');
    const form = document.getElementById('statusForm');
    
    // Set current status first (before disabling)
    if (statusSelect) {
        statusSelect.value = currentStatus;
    }
    
    // Remove any existing warning messages
    const existingWarning = form.querySelector('.alert-warning');
    if (existingWarning) {
        existingWarning.remove();
    }
    
    // Check if scheduled date has passed
    let scheduledDatePassed = false;
    if (scheduledDate) {
        const scheduled = new Date(scheduledDate);
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        scheduled.setHours(0, 0, 0, 0);
        scheduledDatePassed = scheduled < today;
    }
    
    if (scheduledDatePassed) {
        // Disable update button and form fields if scheduled date has passed
        updateBtn.disabled = true;
        updateBtn.title = 'Cannot update: Scheduled date (' + scheduledDate + ') has already passed';
        updateBtn.style.opacity = '0.5';
        updateBtn.style.cursor = 'not-allowed';
        
        // Disable status select and all form fields
        if (statusSelect) {
            statusSelect.disabled = true;
            statusSelect.title = 'Updates disabled: Scheduled date has passed';
        }
        
        // Disable all input fields in the form (except hidden shipment_id)
        const formInputs = form.querySelectorAll('input, select, textarea');
        formInputs.forEach(input => {
            if (input.id !== 'status_shipment_id') {
                input.disabled = true;
            }
        });
        
        // Show warning message
        const warningMsg = document.createElement('div');
        warningMsg.className = 'alert alert-warning';
        warningMsg.style.marginTop = '10px';
        warningMsg.style.marginBottom = '15px';
        warningMsg.innerHTML = '<i class="fas fa-exclamation-triangle"></i> <strong>Warning:</strong> The scheduled date (' + scheduledDate + ') has already passed. Updates are disabled.';
        form.insertBefore(warningMsg, updateBtn.parentElement);
    } else {
        // Enable everything if scheduled date hasn't passed
        updateBtn.disabled = false;
        updateBtn.title = '';
        updateBtn.style.opacity = '1';
        updateBtn.style.cursor = 'pointer';
        
        if (statusSelect) {
            statusSelect.disabled = false;
            statusSelect.title = '';
        }
        
        // Enable all form fields
        const formInputs = form.querySelectorAll('input, select, textarea');
        formInputs.forEach(input => {
            input.disabled = false;
        });
    }
    
    // Filter out invalid status transitions
    const options = statusSelect.options;
    for (let i = 0; i < options.length; i++) {
        options[i].disabled = false;
    }
    
    // Disable going backwards (e.g., can't go from delivered to scheduled)
    if (currentStatus === 'delivered') {
        // Can only cancel a delivered shipment
        for (let i = 0; i < options.length; i++) {
            if (options[i].value !== 'delivered' && options[i].value !== 'cancelled') {
                options[i].disabled = true;
            }
        }
    } else if (currentStatus === 'cancelled') {
        // Can't change a cancelled shipment
        for (let i = 0; i < options.length; i++) {
            if (options[i].value !== 'cancelled') {
                options[i].disabled = true;
            }
        }
    }
    
    toggleDeliveryDate();
    
    openModal('updateStatusModal');
}

function toggleDeliveryDate() {
    const status = document.getElementById('status').value;
    const deliveryDateGroup = document.getElementById('deliveryDateGroup');
    const deliveryDateInput = document.getElementById('delivery_date');
    
    // Hide all groups first
    if (deliveryDateGroup) deliveryDateGroup.style.display = 'none';
    if (deliveryDateInput) deliveryDateInput.required = false;
    
    if (status === 'delivered' && deliveryDateGroup && deliveryDateInput) {
        deliveryDateGroup.style.display = 'block';
        deliveryDateInput.required = true;
        // Set min to today to prevent past dates
        const today = new Date().toISOString().split('T')[0];
        deliveryDateInput.min = today;
        // Set default to today if not already set
        if (!deliveryDateInput.value) {
            deliveryDateInput.value = today;
        }
    }
}

// Handle form submission for status updates
document.addEventListener('DOMContentLoaded', function() {
    const statusForm = document.getElementById('statusForm');
    // Form will submit normally for all statuses
});

function assignDriverVehicle(shipmentId, vehicleId, driverId) {
    document.getElementById('assign_shipment_id').value = shipmentId;
    
    // Set current vehicle if assigned
    const vehicleSelect = document.getElementById('assign_vehicle_id');
    if (vehicleId && vehicleId !== 'null') {
        vehicleSelect.value = vehicleId;
    } else {
        vehicleSelect.value = '';
    }
    
    // Set current driver if assigned
    const driverSelect = document.getElementById('assign_driver_id');
    if (driverId && driverId !== 'null') {
        driverSelect.value = driverId;
    } else {
        driverSelect.value = '';
    }
    
    openModal('assignDriverVehicleModal');
}

function submitDriverVehicleAssignment(event, shipmentId) {
    event.preventDefault();
    
    const form = event.target;
    const formData = new FormData(form);
    
    // Show loading state
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (response.redirected) {
            // If redirected, reload the page to show success/error message
            window.location.reload();
        } else {
            return response.text();
        }
    })
    .then(data => {
        // If we get here, it means no redirect happened
        // Try to parse as JSON or reload page
        try {
            const jsonData = JSON.parse(data);
            if (jsonData.success) {
                alert('Driver and vehicle assigned successfully!');
                // Reload shipment details
                viewShipmentDetails(shipmentId);
            } else {
                alert('Error: ' + (jsonData.error || 'Failed to assign driver and vehicle'));
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }
        } catch (e) {
            // Not JSON, probably HTML - reload page
            window.location.reload();
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error assigning driver and vehicle. Please try again.');
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
    
    return false;
}

// Route Planning Functions
let currentShipmentId = null;
let currentRouteDistance = 0;
let currentRouteETA = 0;

function planRoute(shipmentId, type, originWarehouseId, destWarehouseId, supplierId) {
    currentShipmentId = shipmentId;
    
    // Open modal
    openModal('routePlanningModal');
    
    // Reset route info
    document.getElementById('routeInfo').style.display = 'none';
    document.getElementById('saveRouteBtn').style.display = 'none';
    
    // Initialize map
    setTimeout(() => {
        if (map) {
            map.remove();
            map = null;
        }
        initRouteMap('routeMap');
        
        // Fetch warehouse/supplier coordinates
        fetchRouteCoordinates(type, originWarehouseId, destWarehouseId, supplierId);
    }, 100);
}

function fetchRouteCoordinates(type, originWarehouseId, destWarehouseId, supplierId) {
    // Fetch coordinates via AJAX
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
                return;
            }
            
            // Add markers and draw route
            if (data.origin && data.origin.lat && data.origin.lng) {
                addMarker(data.origin.lat, data.origin.lng, data.origin.name, true);
            }
            
            if (data.destination && data.destination.lat && data.destination.lng) {
                addMarker(data.destination.lat, data.destination.lng, data.destination.name, false);
            }
            
            // Draw route if both points exist
            if (data.origin && data.origin.lat && data.destination && data.destination.lat) {
                // Show loading message
                document.getElementById('routeInfo').style.display = 'block';
                document.getElementById('routeDistance').textContent = 'Calculating...';
                document.getElementById('routeETA').textContent = 'Calculating...';
                
                // Draw route (returns a Promise with actual driving route)
                drawRoute(
                    data.origin.lat, data.origin.lng,
                    data.destination.lat, data.destination.lng
                ).then(routeData => {
                    // Route drawn successfully - routeData contains {distance, duration}
                    currentRouteDistance = routeData.distance;
                    currentRouteETA = routeData.duration; // Use actual duration from OSRM
                    
                    // Update route info
                    document.getElementById('routeDistance').textContent = routeData.distance.toFixed(2) + ' km';
                    document.getElementById('routeETA').textContent = routeData.duration + ' min';
                    document.getElementById('saveRouteBtn').style.display = 'inline-block';
                }).catch(error => {
                    console.error('Error drawing route:', error);
                    alert('Error calculating route. Using straight-line distance.');
                });
            } else {
                alert('Missing coordinates. Please add latitude/longitude to warehouses in the database.');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading route coordinates.');
        });
}

function saveRoute() {
    if (!currentShipmentId || currentRouteDistance === 0) {
        alert('No route to save.');
        return;
    }
    
    // Save route data via AJAX
    const formData = new FormData();
    formData.append('save_route', '1');
    formData.append('shipment_id', currentShipmentId);
    formData.append('distance_km', currentRouteDistance.toFixed(2));
    formData.append('eta_minutes', currentRouteETA);
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Route saved successfully!');
            closeModal('routePlanningModal');
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Failed to save route'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error saving route.');
    });
}
</script>


<script>
// Shipment type tab switching functionality (Purchase Order / Warehouse Transfer)
function switchShipmentType(type) {
    // Hide all shipment type tab contents
    const poTabContent = document.getElementById('poShipmentTabContent');
    const transferTabContent = document.getElementById('transferShipmentTabContent');
    if (poTabContent) poTabContent.style.display = 'none';
    if (transferTabContent) transferTabContent.style.display = 'none';
    
    // Reset all shipment type tab buttons
    const shipmentTypeTabs = ['poShipmentTab', 'transferShipmentTab'];
    shipmentTypeTabs.forEach(tabId => {
        const tabBtn = document.getElementById(tabId);
        if (tabBtn) {
            tabBtn.classList.remove('active');
            tabBtn.style.borderBottom = '3px solid transparent';
            tabBtn.style.fontWeight = '500';
            tabBtn.style.color = '#64748b';
        }
    });
    
    // Show selected shipment type tab content and activate button
    if (type === 'purchase_order') {
        if (poTabContent) poTabContent.style.display = 'block';
        const poTab = document.getElementById('poShipmentTab');
        if (poTab) {
            poTab.classList.add('active');
            poTab.style.borderBottom = '3px solid #F2ACB9';
            poTab.style.fontWeight = '600';
            poTab.style.color = '#F2ACB9';
        }
    } else if (type === 'warehouse_transfer') {
        if (transferTabContent) transferTabContent.style.display = 'block';
        const transferTab = document.getElementById('transferShipmentTab');
        if (transferTab) {
            transferTab.classList.add('active');
            transferTab.style.borderBottom = '3px solid #F2ACB9';
            transferTab.style.fontWeight = '600';
            transferTab.style.color = '#F2ACB9';
        }
    }
}

// Tab switching functionality
function switchTab(tab) {
    // Hide all tab contents
    document.getElementById('shipmentsTabContent').style.display = 'none';
    document.getElementById('driversTabContent').style.display = 'none';
    document.getElementById('vehiclesTabContent').style.display = 'none';
    
    // Reset all tab buttons
    const tabs = ['shipmentsTab', 'driversTab', 'vehiclesTab'];
    tabs.forEach(tabId => {
        const tabBtn = document.getElementById(tabId);
        if (tabBtn) {
            tabBtn.classList.remove('active');
            tabBtn.style.borderBottom = '3px solid transparent';
            tabBtn.style.fontWeight = '500';
            tabBtn.style.color = '#64748b';
        }
    });
    
    // Show selected tab content and activate button
    if (tab === 'shipments') {
        document.getElementById('shipmentsTabContent').style.display = 'block';
        document.getElementById('shipmentsTab').classList.add('active');
        document.getElementById('shipmentsTab').style.borderBottom = '3px solid #F2ACB9';
        document.getElementById('shipmentsTab').style.fontWeight = '600';
        document.getElementById('shipmentsTab').style.color = '#F2ACB9';
        document.getElementById('scheduleShipmentBtn').style.display = 'inline-block';
    } else if (tab === 'drivers') {
        document.getElementById('driversTabContent').style.display = 'block';
        document.getElementById('driversTab').classList.add('active');
        document.getElementById('driversTab').style.borderBottom = '3px solid #F2ACB9';
        document.getElementById('driversTab').style.fontWeight = '600';
        document.getElementById('driversTab').style.color = '#F2ACB9';
        document.getElementById('scheduleShipmentBtn').style.display = 'none';
    } else if (tab === 'vehicles') {
        document.getElementById('vehiclesTabContent').style.display = 'block';
        document.getElementById('vehiclesTab').classList.add('active');
        document.getElementById('vehiclesTab').style.borderBottom = '3px solid #F2ACB9';
        document.getElementById('vehiclesTab').style.fontWeight = '600';
        document.getElementById('vehiclesTab').style.color = '#F2ACB9';
        document.getElementById('scheduleShipmentBtn').style.display = 'none';
    }
}


// Driver functions
const drivers = <?php echo json_encode($drivers); ?>;
const canEditLogistics = <?php echo canEdit('logistics') ? 'true' : 'false'; ?>;

function openDriverModal(driverId = null) {
    const modal = document.getElementById('driverModal');
    const form = document.getElementById('driverForm');
    const title = document.getElementById('driverModalTitle');
    
    if (driverId) {
        const driver = drivers.find(d => d.id == driverId);
        if (driver) {
            title.textContent = 'Edit Driver';
            document.getElementById('driver_id').value = driver.id;
            document.getElementById('driver_code').value = driver.driver_code || '';
            document.getElementById('full_name').value = driver.full_name || '';
            document.getElementById('license_number').value = driver.license_number || '';
            document.getElementById('license_type').value = driver.license_type || 'B';
            document.getElementById('license_expiry').value = driver.license_expiry || '';
            document.getElementById('phone').value = driver.phone || '';
            document.getElementById('email').value = driver.email || '';
            document.getElementById('address').value = driver.address || '';
            document.getElementById('driver_status').value = driver.status || 'available';
            document.getElementById('hire_date').value = driver.hire_date || '';
            document.getElementById('driver_notes').value = driver.notes || '';
        }
    } else {
        title.textContent = 'Add Driver';
        form.reset();
        document.getElementById('driver_id').value = '';
    }
    
    openModal('driverModal');
}

function editDriver(driverId) {
    openDriverModal(driverId);
}

function deleteDriver(driverId) {
    if (confirm('Are you sure you want to delete this driver?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="driver_id" value="' + driverId + '">' +
                        '<input type="hidden" name="delete_driver" value="1">';
        document.body.appendChild(form);
        form.submit();
    }
}

// Vehicle functions
const vehicles = <?php echo json_encode($vehicles); ?>;

function openVehicleModal(vehicleId = null) {
    const modal = document.getElementById('vehicleModal');
    const form = document.getElementById('vehicleForm');
    const title = document.getElementById('vehicleModalTitle');
    
    if (vehicleId) {
        const vehicle = vehicles.find(v => v.id == vehicleId);
        if (vehicle) {
            title.textContent = 'Edit Vehicle';
            document.getElementById('vehicle_id').value = vehicle.id;
            document.getElementById('vehicle_number').value = vehicle.vehicle_number || '';
            document.getElementById('vehicle_type').value = vehicle.vehicle_type || 'truck';
            document.getElementById('make').value = vehicle.make || '';
            document.getElementById('model').value = vehicle.model || '';
            document.getElementById('year').value = vehicle.year || '';
            document.getElementById('license_plate').value = vehicle.license_plate || '';
            document.getElementById('capacity_kg').value = vehicle.capacity_kg || '';
            document.getElementById('capacity_volume').value = vehicle.capacity_volume || '';
            document.getElementById('fuel_type').value = vehicle.fuel_type || 'diesel';
            document.getElementById('vehicle_status').value = vehicle.status || 'available';
            document.getElementById('current_location').value = vehicle.current_location || '';
            document.getElementById('last_maintenance_date').value = vehicle.last_maintenance_date || '';
            document.getElementById('next_maintenance_date').value = vehicle.next_maintenance_date || '';
            document.getElementById('vehicle_notes').value = vehicle.notes || '';
        }
    } else {
        title.textContent = 'Add Vehicle';
        form.reset();
        document.getElementById('vehicle_id').value = '';
    }
    
    openModal('vehicleModal');
}

function editVehicle(vehicleId) {
    openVehicleModal(vehicleId);
}

function deleteVehicle(vehicleId) {
    if (confirm('Are you sure you want to delete this vehicle?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="vehicle_id" value="' + vehicleId + '">' +
                        '<input type="hidden" name="delete_vehicle" value="1">';
        document.body.appendChild(form);
        form.submit();
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Show shipments tab by default
    switchTab('shipments');
});
</script>

<script src="<?php echo BASE_URL; ?>assets/js/logistics_map.js"></script>
<script src="<?php echo BASE_URL; ?>assets/js/shipment_tracking.js"></script>

<?php include '../includes/footer.php'; ?>

