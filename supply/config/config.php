<?php
// Application Configuration
session_start();

// Base URL
define('BASE_URL', 'http://localhost/supply/');

// Application Settings
define('APP_NAME', 'Supply Chain Management System');
define('APP_VERSION', '1.0.0');

// Session timeout (30 minutes)
define('SESSION_TIMEOUT', 1800);

// Check if session is expired (skip redirect for AJAX requests)
$is_ajax = (isset($_POST['ajax']) && $_POST['ajax'] == '1') || 
           (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest');
           
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
    session_unset();
    session_destroy();
    if (!$is_ajax) {
        header('Location: ' . BASE_URL . 'index.php');
        exit();
    }
    // For AJAX, just let it continue - the handler will check authentication
}
if (isset($_SESSION['last_activity'])) {
    $_SESSION['last_activity'] = time();
}

// Include database configuration
require_once __DIR__ . '/database.php';

// Helper Functions
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . 'index.php');
        exit();
    }
}

function requireRole($allowedRoles) {
    requireLogin();
    if (!in_array($_SESSION['role'], $allowedRoles)) {
        header('Location: ' . BASE_URL . 'dashboard.php');
        exit();
    }
}

function hasRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

function isAdmin() {
    return hasRole('admin');
}

function isSupplier() {
    return hasRole('supplier');
}

// Get supplier ID for current logged-in supplier user
function getSupplierId() {
    if (!isLoggedIn() || !isSupplier()) {
        return null;
    }
    
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT id FROM suppliers WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $supplier = $result->fetch_assoc();
    $stmt->close();
    closeDBConnection($conn);
    
    return $supplier ? $supplier['id'] : null;
}

// Role-Based Access Control Functions
function canAccessModule($module) {
    if (!isLoggedIn()) return false;
    
    $role = $_SESSION['role'];
    
    // Admin has access to everything
    if ($role === 'admin') return true;
    
    // Define module access by role
    $moduleAccess = [
        'dashboard' => ['admin', 'procurement_staff', 'warehouse_officer', 'logistics_manager', 'supplier'],
        'demand_forecasting' => ['admin', 'procurement_staff'],
        'procurement' => ['admin', 'procurement_staff', 'warehouse_officer', 'logistics_manager'],
        'suppliers' => ['admin', 'procurement_staff'],
        'supplier_portal' => ['supplier'],
        'logistics' => ['admin', 'warehouse_officer', 'logistics_manager', 'procurement_staff'],
        'inventory' => ['admin', 'procurement_staff', 'warehouse_officer', 'logistics_manager'],
        'sales' => ['admin', 'procurement_staff', 'warehouse_officer', 'logistics_manager'],
        'warehouse' => ['admin', 'warehouse_officer', 'logistics_manager'],
        'reports' => ['admin', 'procurement_staff', 'warehouse_officer', 'logistics_manager'],
        'users' => ['admin']
    ];
    
    return isset($moduleAccess[$module]) && in_array($role, $moduleAccess[$module]);
}

function canViewModule($module) {
    return canAccessModule($module);
}

function canCreate($module) {
    if (!isLoggedIn()) return false;
    
    $role = $_SESSION['role'];
    if ($role === 'admin') return true;
    
    $createPermissions = [
        'procurement' => ['admin', 'procurement_staff'],
        'suppliers' => ['admin', 'procurement_staff'],
        'logistics' => ['admin', 'logistics_manager'],
        'inventory' => ['admin', 'warehouse_officer'],
        'warehouse' => ['admin', 'warehouse_officer'],
        'demand_forecasting' => ['admin']
    ];
    
    return isset($createPermissions[$module]) && in_array($role, $createPermissions[$module]);
}

function canEdit($module) {
    if (!isLoggedIn()) return false;
    
    $role = $_SESSION['role'];
    if ($role === 'admin') return true;
    
    $editPermissions = [
        'procurement' => ['admin', 'procurement_staff'],
        'suppliers' => ['admin', 'procurement_staff'],
        'logistics' => ['admin', 'logistics_manager'],
        'inventory' => ['admin', 'warehouse_officer'],
        'warehouse' => ['admin', 'warehouse_officer'],
        'demand_forecasting' => ['admin']
    ];
    
    return isset($editPermissions[$module]) && in_array($role, $editPermissions[$module]);
}

function canDelete($module, $context = []) {
    if (!isLoggedIn()) return false;
    
    $role = $_SESSION['role'];
    if ($role === 'admin') return true;
    
    // Procurement Staff cannot delete confirmed purchase orders
    if ($module === 'procurement' && $role === 'procurement_staff') {
        if (isset($context['status']) && in_array($context['status'], ['received', 'approved'])) {
            return false;
        }
    }
    
    // Warehouse Officer cannot delete inventory records (can only adjust quantities)
    // Only Admin can delete inventory records
    if ($module === 'inventory' && $role === 'admin') {
        return true;
    }
    
    // Logistics Manager cannot delete shipment records (can only update status)
    if ($module === 'logistics' && $role === 'logistics_manager') {
        return false;
    }
    
    // Warehouse Officer can delete pending transfer requests (not completed or in transit)
    if ($module === 'warehouse' && $role === 'warehouse_officer') {
        if (isset($context['status']) && in_array($context['status'], ['pending'])) {
            return true;
        }
    }
    
    return false; // By default, non-admin roles cannot delete
}

function canApprove($module, $context = []) {
    if (!isLoggedIn()) return false;
    
    $role = $_SESSION['role'];
    if ($role === 'admin') return true;
    
    // Procurement Staff cannot approve their own purchase orders (for large orders)
    if ($module === 'procurement' && $role === 'procurement_staff') {
        if (isset($context['created_by']) && $context['created_by'] == $_SESSION['user_id']) {
            // Check if it's a large order (e.g., > 100000)
            if (isset($context['total_amount']) && $context['total_amount'] > 100000) {
                return false; // Requires admin approval
            }
        }
    }
    
    // Warehouse Officer cannot approve large transfer requests
    if ($module === 'warehouse' && $role === 'warehouse_officer') {
        if (isset($context['transfer_value']) && $context['transfer_value'] > 50000) {
            return false; // Requires admin approval
        }
    }
    
    // Logistics Manager cannot approve budget for transportation expenses
    if ($module === 'logistics' && $role === 'logistics_manager') {
        return false; // View only for budget approval
    }
    
    return false; // By default, non-admin roles cannot approve
}

function canViewFinancialData() {
    if (!isLoggedIn()) return false;
    
    $role = $_SESSION['role'];
    // Only Admin can view detailed financial/cost breakdowns
    return $role === 'admin';
}

function canManageUsers() {
    return isAdmin();
}

function canApproveDelivery($context = []) {
    if (!isLoggedIn()) return false;
    
    $role = $_SESSION['role'];
    if ($role === 'admin') return true;
    
    // Warehouse Officer can approve incoming deliveries (mark shipments as delivered)
    // This allows them to receive goods and update inventory
    if ($role === 'warehouse_officer') {
        // Only allow approval of inbound shipments (deliveries from suppliers)
        if (isset($context['shipment_type']) && $context['shipment_type'] === 'inbound') {
            return true;
        }
        // If shipment_type not provided, allow by default (will be checked in logistics module)
        if (!isset($context['shipment_type'])) {
            return true;
        }
    }
    
    // Logistics Manager can approve all shipments
    if ($role === 'logistics_manager') {
        return true;
    }
    
    return false;
}

function requireModuleAccess($module) {
    requireLogin();
    if (!canAccessModule($module)) {
        header('Location: ' . BASE_URL . 'dashboard.php?error=access_denied');
        exit();
    }
}

function requireCreatePermission($module) {
    requireLogin();
    if (!canCreate($module)) {
        header('Location: ' . BASE_URL . 'dashboard.php?error=permission_denied');
        exit();
    }
}

function requireEditPermission($module) {
    requireLogin();
    if (!canEdit($module)) {
        header('Location: ' . BASE_URL . 'dashboard.php?error=permission_denied');
        exit();
    }
}

function requireDeletePermission($module, $context = []) {
    requireLogin();
    if (!canDelete($module, $context)) {
        header('Location: ' . BASE_URL . 'dashboard.php?error=permission_denied');
        exit();
    }
}

function requireApprovePermission($module, $context = []) {
    requireLogin();
    if (!canApprove($module, $context)) {
        header('Location: ' . BASE_URL . 'dashboard.php?error=approval_denied');
        exit();
    }
}

function formatCurrency($amount) {
    return '₱' . number_format($amount, 2);
}

function formatDate($date) {
    return date('M d, Y', strtotime($date));
}

function formatDateTime($datetime) {
    return date('M d, Y h:i A', strtotime($datetime));
}

function formatPhoneNumber($phone) {
    if (empty($phone)) return 'N/A';
    
    // Remove all non-digit characters
    $digits = preg_replace('/[^0-9]/', '', $phone);
    
    // If it starts with 63 (country code), format as +63 9 123456789
    if (strlen($digits) == 12 && substr($digits, 0, 2) == '63') {
        return '+63 ' . substr($digits, 2, 1) . ' ' . substr($digits, 3);
    }
    // If it starts with 0, format as 09 1234 5678
    elseif (strlen($digits) == 11 && substr($digits, 0, 1) == '0') {
        return '0' . substr($digits, 1, 1) . ' ' . substr($digits, 2, 4) . ' ' . substr($digits, 6);
    }
    // If it's 10 digits starting with 9, format as 09 1234 5678
    elseif (strlen($digits) == 10 && substr($digits, 0, 1) == '9') {
        return '0' . substr($digits, 0, 1) . ' ' . substr($digits, 1, 4) . ' ' . substr($digits, 5);
    }
    // If it's already formatted or doesn't match, return as is
    return $phone;
}

// Notification Functions
function createNotification($user_id, $role, $notification_type, $title, $message, $reference_type = null, $reference_id = null) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, role, notification_type, title, message, reference_type, reference_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssssi", $user_id, $role, $notification_type, $title, $message, $reference_type, $reference_id);
    $result = $stmt->execute();
    $stmt->close();
    closeDBConnection($conn);
    return $result;
}

function notifyUsersByRole($role, $notification_type, $title, $message, $reference_type = null, $reference_id = null) {
    $conn = getDBConnection();
    // Get all active users with the specified role
    $stmt = $conn->prepare("SELECT id FROM users WHERE role = ? AND status = 'active'");
    $stmt->bind_param("s", $role);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $notified = 0;
    while ($user = $result->fetch_assoc()) {
        if (createNotification($user['id'], $role, $notification_type, $title, $message, $reference_type, $reference_id)) {
            $notified++;
        }
    }
    $stmt->close();
    closeDBConnection($conn);
    return $notified;
}

function notifySupplier($supplier_id, $notification_type, $title, $message, $reference_type = null, $reference_id = null) {
    $conn = getDBConnection();
    // Get user_id from supplier
    $stmt = $conn->prepare("SELECT user_id FROM suppliers WHERE id = ? AND user_id IS NOT NULL");
    $stmt->bind_param("i", $supplier_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $supplier = $result->fetch_assoc();
    $stmt->close();
    closeDBConnection($conn);
    
    if ($supplier && $supplier['user_id']) {
        return createNotification($supplier['user_id'], 'supplier', $notification_type, $title, $message, $reference_type, $reference_id);
    }
    return false;
}

function getUnreadNotificationCount($user_id = null) {
    if (!$user_id) {
        $user_id = $_SESSION['user_id'] ?? null;
    }
    if (!$user_id) return 0;
    
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = FALSE");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    closeDBConnection($conn);
    
    return $row['count'] ?? 0;
}

function normalizePhoneNumber($phone) {
    if (empty($phone)) return '';
    
    // Remove all non-digit characters
    $digits = preg_replace('/[^0-9]/', '', $phone);
    
    // If it starts with +63 or 63, keep as is (international format)
    if (preg_match('/^\+?63/', $phone)) {
        return preg_replace('/[^0-9+]/', '', $phone);
    }
    // If it starts with 0, keep as is (local format)
    elseif (substr($digits, 0, 1) == '0') {
        return $digits;
    }
    // If it's 10 digits starting with 9, add 0 prefix
    elseif (strlen($digits) == 10 && substr($digits, 0, 1) == '9') {
        return '0' . $digits;
    }
    // Otherwise return digits as is
    return $digits;
}
?>

