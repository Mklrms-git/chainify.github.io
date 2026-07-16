<?php
require_once '../config/config.php';
requireModuleAccess('reports');

$conn = getDBConnection();

// Define allowed report types by role
$role = $_SESSION['role'];
$allowed_reports = [];

// Handle scheduled reports page
if (isset($_GET['page']) && $_GET['page'] === 'scheduled' && $role === 'admin') {
    // Handle save scheduled report
    if (isset($_POST['save_schedule']) || isset($_POST['create_schedule']) || isset($_POST['update_schedule'])) {
        $schedule_id = intval($_POST['schedule_id'] ?? 0);
        $schedule_name = trim($_POST['schedule_name'] ?? '');
        $report_type = $_POST['report_type'] ?? '';
        $frequency = $_POST['frequency'] ?? '';
        $schedule_day = $_POST['schedule_day'] ?? null;
        $schedule_time = $_POST['schedule_time'] ?? '09:00:00';
        $email_recipients = trim($_POST['email_recipients'] ?? '');
        $format = $_POST['format'] ?? 'pdf';
        $status = $_POST['status'] ?? 'active';
            
            // Build filters JSON
            $filters = [
                'date_from' => $_POST['filter_date_from'] ?? '',
                'date_to' => $_POST['filter_date_to'] ?? '',
                'warehouse' => $_POST['filter_warehouse'] ?? '',
                'supplier' => $_POST['filter_supplier'] ?? ''
            ];
            $filters_json = json_encode($filters);
            
            // Validate
            if (empty($schedule_name) || empty($report_type) || empty($frequency) || empty($email_recipients)) {
                $error = 'Please fill all required fields.';
            } else {
                // Validate email addresses
                $emails = explode(',', $email_recipients);
                $valid_emails = [];
                foreach ($emails as $email) {
                    $email = trim($email);
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $valid_emails[] = $email;
                    }
                }
                if (empty($valid_emails)) {
                    $error = 'Please provide at least one valid email address.';
                } else {
                    $email_recipients = implode(', ', $valid_emails);
                    
                    // Calculate next run time
                    $next_run = null;
                    if ($frequency === 'daily') {
                        $next_run = date('Y-m-d', strtotime('+1 day')) . ' ' . $schedule_time;
                    } elseif ($frequency === 'weekly') {
                        $schedule_day = $schedule_day ?? 1;
                        $current_day_of_week = date('N');
                        $days_until_next = (7 + $schedule_day - $current_day_of_week) % 7;
                        if ($days_until_next == 0) $days_until_next = 7;
                        $next_run = date('Y-m-d', strtotime("+{$days_until_next} days")) . ' ' . $schedule_time;
                    } elseif ($frequency === 'monthly') {
                        $schedule_day = $schedule_day ?? 1;
                        $next_month = date('Y-m-01', strtotime('+1 month'));
                        $next_run = date('Y-m-d', strtotime($next_month . ' +' . ($schedule_day - 1) . ' days')) . ' ' . $schedule_time;
                    }
                    
                    if ($schedule_id > 0) {
                        // Update
                        $stmt = $conn->prepare("
                            UPDATE scheduled_reports 
                            SET schedule_name = ?, report_type = ?, frequency = ?, schedule_day = ?, 
                                schedule_time = ?, email_recipients = ?, format = ?, filters = ?, 
                                status = ?, next_run_at = ?, updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->bind_param("sssissssssi", $schedule_name, $report_type, $frequency, $schedule_day, 
                                         $schedule_time, $email_recipients, $format, $filters_json, $status, $next_run, $schedule_id);
                    } else {
                        // Insert
                        $stmt = $conn->prepare("
                            INSERT INTO scheduled_reports 
                            (schedule_name, report_type, frequency, schedule_day, schedule_time, 
                             email_recipients, format, filters, status, next_run_at, created_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->bind_param("sssissssssi", $schedule_name, $report_type, $frequency, $schedule_day,
                                         $schedule_time, $email_recipients, $format, $filters_json, $status, $next_run, $_SESSION['user_id']);
                    }
                    
                    if ($stmt->execute()) {
                        $message = $schedule_id > 0 ? 'Scheduled report updated successfully!' : 'Scheduled report created successfully!';
                    } else {
                        $error = 'Failed to save scheduled report: ' . $stmt->error;
                    }
                    $stmt->close();
                }
            }
    }
    
    // Handle delete
    if (isset($_GET['delete'])) {
        $schedule_id = intval($_GET['delete']);
        $stmt = $conn->prepare("DELETE FROM scheduled_reports WHERE id = ?");
        $stmt->bind_param("i", $schedule_id);
        if ($stmt->execute()) {
            $message = 'Scheduled report deleted successfully!';
        } else {
            $error = 'Failed to delete scheduled report.';
        }
        $stmt->close();
    }
    
    // Get all scheduled reports
    $schedules = [];
    $result = $conn->query("
        SELECT sr.*, u.full_name as created_by_name 
        FROM scheduled_reports sr
        LEFT JOIN users u ON sr.created_by = u.id
        ORDER BY sr.created_at DESC
    ");
    while ($row = $result->fetch_assoc()) {
        $schedules[] = $row;
    }
    
    // Get edit schedule data
    $edit_schedule = null;
    if (isset($_GET['edit'])) {
        $schedule_id = intval($_GET['edit']);
        $stmt = $conn->prepare("SELECT * FROM scheduled_reports WHERE id = ?");
        $stmt->bind_param("i", $schedule_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $edit_schedule = $result->fetch_assoc();
        $stmt->close();
        
        if ($edit_schedule) {
            $edit_schedule['filters'] = json_decode($edit_schedule['filters'], true) ?: [];
        }
    }
    
    // Get warehouses and suppliers for filters
    $warehouses = [];
    $result = $conn->query("SELECT id, name FROM warehouses WHERE status = 'active' ORDER BY name");
    while ($row = $result->fetch_assoc()) {
        $warehouses[] = $row;
    }
    
    $suppliers = [];
    $result = $conn->query("SELECT id, company_name FROM suppliers WHERE status = 'active' ORDER BY company_name");
    while ($row = $result->fetch_assoc()) {
        $suppliers[] = $row;
    }
    
    closeDBConnection($conn);
    
    $pageTitle = 'Scheduled Reports';
    include '../includes/header.php';
    ?>
    
    <div class="page-header">
        <h2><i class="fas fa-calendar-alt"></i> Scheduled Reports</h2>
        <div class="header-actions">
            <a href="reports.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Reports
            </a>
            <button class="btn btn-primary" onclick="openModal('scheduleModal')">
                <i class="fas fa-plus"></i> Schedule Report
            </button>
        </div>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <div class="dashboard-card">
        <div class="card-header">
            <h3>Scheduled Reports</h3>
        </div>
        <div class="card-body">
            <?php if (empty($schedules)): ?>
                <p class="text-muted">No scheduled reports found. Click "Schedule Report" to create one.</p>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Schedule Name</th>
                            <th>Report Type</th>
                            <th>Frequency</th>
                            <th>Schedule Time</th>
                            <th>Format</th>
                            <th>Recipients</th>
                            <th>Status</th>
                            <th>Last Run</th>
                            <th>Next Run</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($schedules as $schedule): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($schedule['schedule_name']); ?></strong></td>
                                <td><?php echo ucfirst($schedule['report_type']); ?></td>
                                <td>
                                    <?php echo ucfirst($schedule['frequency']); ?>
                                    <?php if ($schedule['schedule_day']): ?>
                                        (Day: <?php echo $schedule['schedule_day']; ?>)
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('h:i A', strtotime($schedule['schedule_time'])); ?></td>
                                <td><?php echo strtoupper($schedule['format']); ?></td>
                                <td><?php echo htmlspecialchars(substr($schedule['email_recipients'], 0, 30)) . (strlen($schedule['email_recipients']) > 30 ? '...' : ''); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $schedule['status'] == 'active' ? 'success' : 'secondary'; ?>">
                                        <?php echo ucfirst($schedule['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo $schedule['last_run_at'] ? formatDateTime($schedule['last_run_at']) : 'Never'; ?></td>
                                <td><?php echo $schedule['next_run_at'] ? formatDateTime($schedule['next_run_at']) : 'Not scheduled'; ?></td>
                                <td>
                                    <a href="?page=scheduled&edit=<?php echo $schedule['id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    <a href="?page=scheduled&delete=<?php echo $schedule['id']; ?>" class="btn btn-sm btn-danger" 
                                       onclick="return confirm('Are you sure you want to delete this scheduled report?')">
                                        <i class="fas fa-trash"></i> Delete
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Schedule Report Modal -->
    <div id="scheduleModal" class="modal <?php echo $edit_schedule ? 'show' : ''; ?>">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h3><?php echo $edit_schedule ? 'Edit' : 'Create'; ?> Scheduled Report</h3>
                <button class="modal-close" onclick="closeModal('scheduleModal'); window.location.href='reports.php?page=scheduled'">&times;</button>
            </div>
            <form method="POST" action="">
                <?php if ($edit_schedule): ?>
                    <input type="hidden" name="schedule_id" value="<?php echo $edit_schedule['id']; ?>">
                    <input type="hidden" name="update_schedule" value="1">
                <?php else: ?>
                    <input type="hidden" name="create_schedule" value="1">
                <?php endif; ?>
                
                <div class="form-group">
                    <label for="schedule_name">Schedule Name *</label>
                    <input type="text" name="schedule_name" id="schedule_name" class="form-control" 
                           value="<?php echo htmlspecialchars($edit_schedule['schedule_name'] ?? ''); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="report_type">Report Type *</label>
                    <select name="report_type" id="report_type" class="form-control" required>
                        <option value="">Select Report Type</option>
                        <option value="inventory" <?php echo ($edit_schedule['report_type'] ?? '') == 'inventory' ? 'selected' : ''; ?>>Inventory Report</option>
                        <option value="procurement" <?php echo ($edit_schedule['report_type'] ?? '') == 'procurement' ? 'selected' : ''; ?>>Procurement Report</option>
                        <option value="supplier" <?php echo ($edit_schedule['report_type'] ?? '') == 'supplier' ? 'selected' : ''; ?>>Supplier Performance</option>
                        <option value="logistics" <?php echo ($edit_schedule['report_type'] ?? '') == 'logistics' ? 'selected' : ''; ?>>Logistics & Delivery</option>
                        <option value="demand" <?php echo ($edit_schedule['report_type'] ?? '') == 'demand' ? 'selected' : ''; ?>>Demand Forecast</option>
                        <option value="cost" <?php echo ($edit_schedule['report_type'] ?? '') == 'cost' ? 'selected' : ''; ?>>Cost Analysis</option>
                        <option value="warehouse" <?php echo ($edit_schedule['report_type'] ?? '') == 'warehouse' ? 'selected' : ''; ?>>Warehouse Report</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="frequency">Frequency *</label>
                    <select name="frequency" id="frequency" class="form-control" required onchange="toggleScheduleDay()">
                        <option value="">Select Frequency</option>
                        <option value="daily" <?php echo ($edit_schedule['frequency'] ?? '') == 'daily' ? 'selected' : ''; ?>>Daily</option>
                        <option value="weekly" <?php echo ($edit_schedule['frequency'] ?? '') == 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                        <option value="monthly" <?php echo ($edit_schedule['frequency'] ?? '') == 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                    </select>
                </div>
                
                <div class="form-group" id="schedule_day_group" style="display: none;">
                    <label for="schedule_day">Schedule Day *</label>
                    <select name="schedule_day" id="schedule_day" class="form-control">
                        <option value="">Select Day</option>
                    </select>
                    <small class="text-muted" id="schedule_day_help"></small>
                </div>
                
                <div class="form-group">
                    <label for="schedule_time">Schedule Time *</label>
                    <input type="time" name="schedule_time" id="schedule_time" class="form-control" 
                           value="<?php echo $edit_schedule['schedule_time'] ?? '09:00'; ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="format">Report Format *</label>
                    <select name="format" id="format" class="form-control" required>
                        <option value="pdf" <?php echo ($edit_schedule['format'] ?? 'pdf') == 'pdf' ? 'selected' : ''; ?>>PDF</option>
                        <option value="csv" <?php echo ($edit_schedule['format'] ?? '') == 'csv' ? 'selected' : ''; ?>>CSV</option>
                        <option value="excel" <?php echo ($edit_schedule['format'] ?? '') == 'excel' ? 'selected' : ''; ?>>Excel</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="email_recipients">Email Recipients *</label>
                    <textarea name="email_recipients" id="email_recipients" class="form-control" rows="3" required
                              placeholder="email1@example.com, email2@example.com"><?php echo htmlspecialchars($edit_schedule['email_recipients'] ?? ''); ?></textarea>
                    <small class="text-muted">Separate multiple email addresses with commas</small>
                </div>
                
                <h4>Report Filters (Optional)</h4>
                
                <div class="form-group">
                    <label for="filter_date_from">Date From</label>
                    <input type="date" name="filter_date_from" id="filter_date_from" class="form-control"
                           value="<?php echo $edit_schedule['filters']['date_from'] ?? ''; ?>">
                </div>
                
                <div class="form-group">
                    <label for="filter_date_to">Date To</label>
                    <input type="date" name="filter_date_to" id="filter_date_to" class="form-control"
                           value="<?php echo $edit_schedule['filters']['date_to'] ?? ''; ?>">
                </div>
                
                <div class="form-group">
                    <label for="filter_warehouse">Warehouse</label>
                    <select name="filter_warehouse" id="filter_warehouse" class="form-control">
                        <option value="">All Warehouses</option>
                        <?php foreach ($warehouses as $warehouse): ?>
                            <option value="<?php echo $warehouse['id']; ?>" 
                                    <?php echo ($edit_schedule['filters']['warehouse'] ?? '') == $warehouse['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($warehouse['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="filter_supplier">Supplier</label>
                    <select name="filter_supplier" id="filter_supplier" class="form-control">
                        <option value="">All Suppliers</option>
                        <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?php echo $supplier['id']; ?>"
                                    <?php echo ($edit_schedule['filters']['supplier'] ?? '') == $supplier['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($supplier['company_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <?php if ($edit_schedule): ?>
                <div class="form-group">
                    <label for="status">Status</label>
                    <select name="status" id="status" class="form-control">
                        <option value="active" <?php echo ($edit_schedule['status'] ?? 'active') == 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo ($edit_schedule['status'] ?? '') == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="form-group">
                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="fas fa-save"></i> <?php echo $edit_schedule ? 'Update' : 'Create'; ?> Scheduled Report
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
    function toggleScheduleDay() {
        const frequency = document.getElementById('frequency').value;
        const scheduleDayGroup = document.getElementById('schedule_day_group');
        const scheduleDay = document.getElementById('schedule_day');
        const scheduleDayHelp = document.getElementById('schedule_day_help');
        
        if (frequency === 'weekly' || frequency === 'monthly') {
            scheduleDayGroup.style.display = 'block';
            scheduleDay.required = true;
            
            // Clear existing options
            scheduleDay.innerHTML = '<option value="">Select Day</option>';
            
            if (frequency === 'weekly') {
                scheduleDayHelp.textContent = 'Day of week (1=Monday, 7=Sunday)';
                for (let i = 1; i <= 7; i++) {
                    const option = document.createElement('option');
                    option.value = i;
                    option.textContent = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][i - 1] + ' (' + i + ')';
                    if (i == <?php echo $edit_schedule['schedule_day'] ?? ''; ?>) {
                        option.selected = true;
                    }
                    scheduleDay.appendChild(option);
                }
            } else if (frequency === 'monthly') {
                scheduleDayHelp.textContent = 'Day of month (1-31)';
                for (let i = 1; i <= 31; i++) {
                    const option = document.createElement('option');
                    option.value = i;
                    option.textContent = i;
                    if (i == <?php echo $edit_schedule['schedule_day'] ?? ''; ?>) {
                        option.selected = true;
                    }
                    scheduleDay.appendChild(option);
                }
            }
        } else {
            scheduleDayGroup.style.display = 'none';
            scheduleDay.required = false;
        }
    }
    
    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        toggleScheduleDay();
    });
    </script>
    <?php
    include '../includes/footer.php';
    exit();
}

if ($role === 'admin') {
    $allowed_reports = ['inventory', 'procurement', 'supplier', 'logistics', 'demand', 'cost', 'warehouse', 'sales'];
} elseif ($role === 'procurement_staff') {
    $allowed_reports = ['procurement', 'supplier'];
} elseif ($role === 'warehouse_officer') {
    $allowed_reports = ['inventory', 'warehouse'];
} elseif ($role === 'logistics_manager') {
    $allowed_reports = ['logistics'];
}

// Set default report type based on role
$default_report = !empty($allowed_reports) ? $allowed_reports[0] : 'inventory';
$report_type = $_GET['type'] ?? $default_report;

// Validate report type access
if (!in_array($report_type, $allowed_reports)) {
    $report_type = $default_report;
}
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$warehouse_filter = $_GET['warehouse'] ?? '';
$supplier_filter = $_GET['supplier'] ?? '';

$report_data = [];
$report_title = '';
$cost_stats = [];
$cost_by_type = [];

// Function to get report data (used for both display and export)
function getReportData($conn, $report_type, $date_from, $date_to, $warehouse_filter, $supplier_filter) {
    $report_data = [];
    $report_title = '';
    
    switch ($report_type) {
        case 'inventory':
            $report_title = 'Inventory Report';
            $where_conditions = [];
            if ($warehouse_filter) $where_conditions[] = "w.id = " . intval($warehouse_filter);
            $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
            
            $result = $conn->query("
                SELECT i.*, 
                       p.name as product_name, 
                       p.sku,
                       p.unit_price,
                       w.name as warehouse_name,
                       CASE WHEN i.quantity <= p.min_stock_level THEN 'Low' ELSE 'OK' END as stock_status
                FROM inventory i
                JOIN products p ON i.product_id = p.id
                JOIN warehouses w ON i.warehouse_id = w.id
                $where_clause
                ORDER BY p.name, w.name
            ");
            if ($result && $result !== false) {
                while ($row = $result->fetch_assoc()) {
                    $report_data[] = $row;
                }
            } else {
                error_log("Inventory report query failed: " . $conn->error);
            }
            break;
            
        case 'procurement':
            $report_title = 'Procurement Report';
            $where_conditions = ["po.order_date BETWEEN '$date_from' AND '$date_to'"];
            if ($supplier_filter) $where_conditions[] = "s.id = " . intval($supplier_filter);
            $where_clause = "WHERE " . implode(" AND ", $where_conditions);
            
            $result = $conn->query("
                SELECT po.*, 
                       s.company_name as supplier_name,
                       u.full_name as created_by_name,
                       COUNT(poi.id) as item_count
                FROM purchase_orders po
                JOIN suppliers s ON po.supplier_id = s.id
                JOIN users u ON po.created_by = u.id
                LEFT JOIN purchase_order_items poi ON po.id = poi.po_id
                $where_clause
                GROUP BY po.id
                ORDER BY po.order_date DESC
            ");
            if ($result && $result !== false) {
                while ($row = $result->fetch_assoc()) {
                    $report_data[] = $row;
                }
            } else {
                error_log("Procurement report query failed: " . $conn->error);
            }
            break;
            
        case 'supplier':
            $report_title = 'Supplier Performance Report';
            $result = $conn->query("
                SELECT s.*,
                       COUNT(DISTINCT po.id) as total_orders,
                       COUNT(DISTINCT CASE WHEN po.status = 'received' THEN po.id END) as completed_orders,
                       COUNT(DISTINCT CASE WHEN po.status = 'cancelled' THEN po.id END) as cancelled_orders,
                       SUM(CASE WHEN po.status = 'received' THEN po.total_amount ELSE 0 END) as total_value,
                       AVG(CASE WHEN po.status = 'received' THEN DATEDIFF(po.delivery_date, po.order_date) ELSE NULL END) as avg_delivery_days
                FROM suppliers s
                LEFT JOIN purchase_orders po ON s.id = po.supplier_id
                GROUP BY s.id
                ORDER BY s.performance_rating DESC
            ");
            if ($result && $result !== false) {
                while ($row = $result->fetch_assoc()) {
                    $report_data[] = $row;
                }
            } else {
                error_log("Supplier report query failed: " . $conn->error);
            }
            break;
            
        case 'logistics':
            $report_title = 'Logistics & Delivery Report';
            $where_conditions = ["s.scheduled_date BETWEEN '$date_from' AND '$date_to'"];
            $where_clause = "WHERE " . implode(" AND ", $where_conditions);
            
            $result = $conn->query("
                SELECT s.id, s.shipment_number, s.type, s.status, s.scheduled_date, s.delivery_date,
                       s.tracking_number, s.transport_cost,
                       po.po_number,
                       w1.name as origin_warehouse_name,
                       w2.name as destination_warehouse_name,
                       sup.company_name as supplier_name,
                       v.vehicle_number as vehicle_name,
                       d.full_name as driver_name,
                       SUM(si.quantity) as total_items
                FROM shipments s
                LEFT JOIN purchase_orders po ON s.po_id = po.id
                LEFT JOIN warehouses w1 ON s.origin_warehouse_id = w1.id
                LEFT JOIN warehouses w2 ON s.destination_warehouse_id = w2.id
                LEFT JOIN suppliers sup ON s.supplier_id = sup.id
                LEFT JOIN vehicles v ON s.vehicle_id = v.id
                LEFT JOIN drivers d ON s.driver_id = d.id
                LEFT JOIN shipment_items si ON s.id = si.shipment_id
                $where_clause
                GROUP BY s.id
                ORDER BY s.scheduled_date DESC
            ");
            if ($result && $result !== false) {
                while ($row = $result->fetch_assoc()) {
                    $report_data[] = $row;
                }
            } else {
                error_log("Logistics report query failed: " . $conn->error);
            }
            break;
            
        case 'demand':
            $report_title = 'Demand Forecast Report';
            $where_conditions = ["df.forecast_period BETWEEN '$date_from' AND '$date_to'"];
            $where_clause = "WHERE " . implode(" AND ", $where_conditions);
            
            $result = $conn->query("
                SELECT df.*,
                       p.name as product_name,
                       p.sku,
                       u.full_name as created_by_name
                FROM demand_forecasts df
                JOIN products p ON df.product_id = p.id
                JOIN users u ON df.created_by = u.id
                $where_clause
                ORDER BY df.forecast_period DESC, p.name
            ");
            if ($result && $result !== false) {
                while ($row = $result->fetch_assoc()) {
                    $report_data[] = $row;
                }
            } else {
                error_log("Demand forecast report query failed: " . $conn->error);
            }
            break;
            
        case 'cost':
            $report_title = 'Cost Analysis Report';
            $where_conditions = ["po.order_date BETWEEN '$date_from' AND '$date_to'"];
            if ($supplier_filter) $where_conditions[] = "sup.id = " . intval($supplier_filter);
            $where_clause = "WHERE " . implode(" AND ", $where_conditions);
            
            $result = $conn->query("
                SELECT 
                    DATE_FORMAT(po.order_date, '%Y-%m') as month,
                    COUNT(DISTINCT po.id) as order_count,
                    SUM(po.total_amount) as total_procurement_cost,
                    SUM(s.transport_cost) as total_transport_cost,
                    SUM(po.total_amount) + COALESCE(SUM(s.transport_cost), 0) as total_cost
                FROM purchase_orders po
                LEFT JOIN shipments s ON po.id = s.po_id
                LEFT JOIN suppliers sup ON po.supplier_id = sup.id
                $where_clause
                GROUP BY DATE_FORMAT(po.order_date, '%Y-%m')
                ORDER BY month DESC
            ");
            if ($result && $result !== false) {
                while ($row = $result->fetch_assoc()) {
                    $report_data[] = $row;
                }
            } else {
                error_log("Cost analysis report query failed: " . $conn->error);
            }
            break;
            
        case 'warehouse':
            $report_title = 'Warehouse Report';
            $where_conditions = [];
            if ($warehouse_filter) $where_conditions[] = "w.id = " . intval($warehouse_filter);
            $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
            
            $result = $conn->query("
                SELECT w.*,
                       COUNT(DISTINCT i.product_id) as product_count,
                       SUM(i.quantity) as total_quantity,
                       COUNT(DISTINCT CASE WHEN i.quantity <= p.min_stock_level THEN i.id END) as low_stock_items,
                       (w.utilized_capacity / w.capacity * 100) as capacity_percent
                FROM warehouses w
                LEFT JOIN inventory i ON w.id = i.warehouse_id
                LEFT JOIN products p ON i.product_id = p.id
                $where_clause
                GROUP BY w.id
                ORDER BY w.name
            ");
            if ($result && $result !== false) {
                while ($row = $result->fetch_assoc()) {
                    $report_data[] = $row;
                }
            } else {
                error_log("Warehouse report query failed: " . $conn->error);
            }
            break;
            
        case 'sales':
            $report_title = 'Sales Report';
            $where_conditions = ["sh.sale_date BETWEEN '$date_from' AND '$date_to'"];
            $where_clause = "WHERE " . implode(" AND ", $where_conditions);
            
            $result = $conn->query("
                SELECT sh.*,
                       p.name as product_name,
                       p.sku,
                       (sh.quantity * sh.unit_price) as total_amount
                FROM sales_history sh
                JOIN products p ON sh.product_id = p.id
                $where_clause
                ORDER BY sh.sale_date DESC, p.name
            ");
            if ($result && $result !== false) {
                while ($row = $result->fetch_assoc()) {
                    $report_data[] = $row;
                }
            } else {
                error_log("Sales report query failed: " . $conn->error);
                // If warehouse_id column doesn't exist, try query without it
                if (strpos($conn->error, 'warehouse_id') !== false) {
                    $where_conditions = ["sh.sale_date BETWEEN '$date_from' AND '$date_to'"];
                    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
                    $result = $conn->query("
                        SELECT sh.*,
                               p.name as product_name,
                               p.sku,
                               (sh.quantity * sh.unit_price) as total_amount
                        FROM sales_history sh
                        JOIN products p ON sh.product_id = p.id
                        $where_clause
                        ORDER BY sh.sale_date DESC, p.name
                    ");
                    if ($result && $result !== false) {
                        while ($row = $result->fetch_assoc()) {
                            $report_data[] = $row;
                        }
                    }
                }
            }
            break;
    }
    
    return ['title' => $report_title, 'data' => $report_data];
}

// Helper function to format date for CSV/Excel (prevents hashtag issue)
function formatDateForExport($date) {
    if (empty($date) || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') {
        return '';
    }
    // Format as MM/DD/YYYY for Excel compatibility
    $timestamp = strtotime($date);
    return $timestamp ? date('m/d/Y', $timestamp) : '';
}

// Helper function to format datetime for CSV/Excel
function formatDateTimeForExport($datetime) {
    if (empty($datetime) || $datetime == '0000-00-00 00:00:00') {
        return '';
    }
    // Format as MM/DD/YYYY HH:MM for Excel compatibility
    $timestamp = strtotime($datetime);
    return $timestamp ? date('m/d/Y H:i', $timestamp) : '';
}

// Helper function to get warehouse name
function getWarehouseName($conn, $warehouse_id) {
    if (!$warehouse_id) return '';
    $stmt = $conn->prepare("SELECT name FROM warehouses WHERE id = ?");
    $stmt->bind_param("i", $warehouse_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ? $row['name'] : '';
}

// Helper function to get supplier name
function getSupplierName($conn, $supplier_id) {
    if (!$supplier_id) return '';
    $stmt = $conn->prepare("SELECT company_name FROM suppliers WHERE id = ?");
    $stmt->bind_param("i", $supplier_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ? $row['company_name'] : '';
}

// Handle export request
if (isset($_GET['export']) && in_array($_GET['export'], ['csv', 'pdf', 'excel'])) {
    $export_format = $_GET['export'];
    $report_result = getReportData($conn, $report_type, $date_from, $date_to, $warehouse_filter, $supplier_filter);
    $report_title = $report_result['title'];
    $report_data = $report_result['data'];
    
    if ($export_format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . str_replace(' ', '_', strtolower($report_title)) . '_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Add UTF-8 BOM for Excel compatibility
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Get exporter information
        $exporter_name = $_SESSION['full_name'] ?? 'Unknown User';
        $export_date = date('F d, Y h:i A');
        
        // Add report metadata
        fputcsv($output, [$report_title]);
        fputcsv($output, []);
        fputcsv($output, ['Export Date:', $export_date]);
        fputcsv($output, ['Exported By:', $exporter_name]);
        
        // Add report-specific metadata
        if ($report_type == 'inventory' && $warehouse_filter) {
            $warehouse_name = getWarehouseName($conn, $warehouse_filter);
            if ($warehouse_name) {
                fputcsv($output, ['Warehouse:', $warehouse_name]);
            }
        } elseif ($report_type == 'warehouse') {
            if ($warehouse_filter) {
                $warehouse_name = getWarehouseName($conn, $warehouse_filter);
                if ($warehouse_name) {
                    fputcsv($output, ['Warehouse:', $warehouse_name]);
                }
            }
            fputcsv($output, ['Date Range:', date('M d, Y', strtotime($date_from)) . ' to ' . date('M d, Y', strtotime($date_to))]);
        } elseif ($report_type == 'procurement') {
            if ($supplier_filter) {
                $supplier_name = getSupplierName($conn, $supplier_filter);
                if ($supplier_name) {
                    fputcsv($output, ['Supplier:', $supplier_name]);
                }
            }
            fputcsv($output, ['Date Range:', date('M d, Y', strtotime($date_from)) . ' to ' . date('M d, Y', strtotime($date_to))]);
        } elseif ($report_type == 'logistics') {
            fputcsv($output, ['Date Range:', date('M d, Y', strtotime($date_from)) . ' to ' . date('M d, Y', strtotime($date_to))]);
        } elseif ($report_type == 'demand') {
            fputcsv($output, ['Date Range:', date('M d, Y', strtotime($date_from)) . ' to ' . date('M d, Y', strtotime($date_to))]);
        } elseif ($report_type == 'cost') {
            fputcsv($output, ['Date Range:', date('M d, Y', strtotime($date_from)) . ' to ' . date('M d, Y', strtotime($date_to))]);
            if ($supplier_filter) {
                $supplier_name = getSupplierName($conn, $supplier_filter);
                if ($supplier_name) {
                    fputcsv($output, ['Supplier:', $supplier_name]);
                }
            }
        } elseif ($report_type == 'sales') {
            fputcsv($output, ['Date Range:', date('M d, Y', strtotime($date_from)) . ' to ' . date('M d, Y', strtotime($date_to))]);
        }
        
        fputcsv($output, []); // Empty row
        fputcsv($output, []); // Empty row
        
        // Process and format data based on report type
        $formatted_data = [];
        $headers = [];
        
        if (!empty($report_data)) {
            foreach ($report_data as $row) {
                $formatted_row = [];
                
                if ($report_type == 'inventory') {
                    if (empty($headers)) {
                        $headers = ['Product Name', 'SKU', 'Warehouse', 'Quantity', 'Stock Status'];
                        if (canViewFinancialData()) {
                            $headers[] = 'Unit Price';
                            $headers[] = 'Total Value';
                        }
                        $headers[] = 'Last Updated';
                    }
                    $formatted_row = [
                        $row['product_name'] ?? '',
                        $row['sku'] ?? '',
                        $row['warehouse_name'] ?? '',
                        $row['quantity'] ?? 0,
                        $row['stock_status'] ?? '',
                    ];
                    if (canViewFinancialData()) {
                        $formatted_row[] = number_format($row['unit_price'] ?? 0, 2);
                        $formatted_row[] = number_format(($row['quantity'] ?? 0) * ($row['unit_price'] ?? 0), 2);
                    }
                    $updated_at = $row['updated_at'] ?? $row['last_updated'] ?? '';
                    $formatted_row[] = !empty($updated_at) ? formatDateForExport($updated_at) : 'N/A';
                    
                } elseif ($report_type == 'warehouse') {
                    if (empty($headers)) {
                        $headers = ['Warehouse Name', 'Location', 'Capacity', 'Utilized', 'Capacity %', 'Products', 'Total Quantity', 'Low Stock Items', 'Status', 'Last Updated'];
                    }
                    $formatted_row = [
                        $row['name'] ?? '',
                        $row['location'] ?? 'N/A',
                        number_format($row['capacity'] ?? 0),
                        number_format($row['utilized_capacity'] ?? 0),
                        number_format($row['capacity_percent'] ?? 0, 1) . '%',
                        $row['product_count'] ?? 0,
                        number_format($row['total_quantity'] ?? 0),
                        $row['low_stock_items'] ?? 0,
                        ucfirst($row['status'] ?? 'active'),
                        (!empty($row['updated_at']) || !empty($row['last_updated'])) ? formatDateForExport($row['updated_at'] ?? $row['last_updated']) : 'N/A',
                    ];
                    
                } elseif ($report_type == 'procurement') {
                    if (empty($headers)) {
                        $headers = ['PO Number', 'Supplier', 'Order Date', 'Delivery Date', 'Items', 'Status'];
                        if (canViewFinancialData()) {
                            $headers[] = 'Amount';
                        }
                        $headers[] = 'Last Updated';
                    }
                    $formatted_row = [
                        $row['po_number'] ?? '',
                        $row['supplier_name'] ?? '',
                        formatDateForExport($row['order_date'] ?? ''),
                        formatDateForExport($row['delivery_date'] ?? ''),
                        $row['item_count'] ?? 0,
                        ucfirst(str_replace('_', ' ', $row['status'] ?? '')),
                    ];
                    if (canViewFinancialData()) {
                        $formatted_row[] = number_format($row['total_amount'] ?? 0, 2);
                    }
                    $updated_at = $row['updated_at'] ?? $row['last_updated'] ?? '';
                    $formatted_row[] = !empty($updated_at) ? formatDateForExport($updated_at) : 'N/A';
                    
                } elseif ($report_type == 'supplier') {
                    if (empty($headers)) {
                        $headers = ['Company Name', 'Performance Rating', 'Total Orders', 'Completed Orders', 'Cancelled Orders'];
                        if (canViewFinancialData()) {
                            $headers[] = 'Total Value';
                        }
                        $headers[] = 'Avg Delivery Days';
                    }
                    $formatted_row = [
                        $row['company_name'] ?? '',
                        number_format($row['performance_rating'] ?? 0, 2) . '/5.0',
                        $row['total_orders'] ?? 0,
                        $row['completed_orders'] ?? 0,
                        $row['cancelled_orders'] ?? 0,
                    ];
                    if (canViewFinancialData()) {
                        $formatted_row[] = number_format($row['total_value'] ?? 0, 2);
                    }
                    $formatted_row[] = $row['avg_delivery_days'] ? round($row['avg_delivery_days']) : 'N/A';
                    
                } elseif ($report_type == 'logistics') {
                    if (empty($headers)) {
                        $headers = ['Shipment Number', 'Type', 'PO Number', 'Origin', 'Destination', 'Supplier', 'Vehicle', 'Driver', 'Scheduled Date', 'Delivery Date', 'Items', 'Status'];
                        if (canViewFinancialData()) {
                            $headers[] = 'Transport Cost';
                        }
                    }
                    // Determine origin and destination (all shipments are inbound)
                    $origin = $row['supplier_name'] ?? 'N/A';
                    $destination = $row['destination_warehouse_name'] ?? 'N/A';
                    
                    $formatted_row = [
                        $row['shipment_number'] ?? '',
                        ucfirst($row['type'] ?? ''),
                        $row['po_number'] ?? 'N/A',
                        $origin,
                        $destination,
                        $row['supplier_name'] ?? 'N/A',
                        $row['vehicle_name'] ?? 'N/A',
                        $row['driver_name'] ?? 'N/A',
                        formatDateForExport($row['scheduled_date'] ?? ''),
                        formatDateForExport($row['delivery_date'] ?? ''),
                        $row['total_items'] ?? 0,
                        ucfirst(str_replace('_', ' ', $row['status'] ?? '')),
                    ];
                    if (canViewFinancialData()) {
                        $formatted_row[] = number_format($row['transport_cost'] ?? 0, 2);
                    }
                    
                } elseif ($report_type == 'demand') {
                    if (empty($headers)) {
                        $headers = ['Product Name', 'SKU', 'Forecast Period', 'Forecasted Quantity', 'Confidence Level', 'Created By'];
                    }
                    $formatted_row = [
                        $row['product_name'] ?? '',
                        $row['sku'] ?? '',
                        formatDateForExport($row['forecast_period'] ?? ''),
                        $row['forecasted_quantity'] ?? 0,
                        ($row['confidence_level'] ?? 0) . '%',
                        $row['created_by_name'] ?? '',
                    ];
                    
                } elseif ($report_type == 'cost') {
                    if (empty($headers)) {
                        $headers = ['Month', 'Order Count', 'Procurement Cost', 'Transport Cost', 'Total Cost'];
                    }
                    $formatted_row = [
                        date('F Y', strtotime($row['month'] . '-01')),
                        $row['order_count'] ?? 0,
                        number_format($row['total_procurement_cost'] ?? 0, 2),
                        number_format($row['total_transport_cost'] ?? 0, 2),
                        number_format($row['total_cost'] ?? 0, 2),
                    ];
                } elseif ($report_type == 'sales') {
                    if (empty($headers)) {
                        $headers = ['Product Name', 'SKU', 'Sale Date', 'Quantity', 'Unit Price'];
                        if (canViewFinancialData()) {
                            $headers[] = 'Total Amount';
                        }
                    }
                    $formatted_row = [
                        $row['product_name'] ?? '',
                        $row['sku'] ?? '',
                        formatDateForExport($row['sale_date'] ?? ''),
                        $row['quantity'] ?? 0,
                        number_format($row['unit_price'] ?? 0, 2),
                    ];
                    if (canViewFinancialData()) {
                        $formatted_row[] = number_format($row['total_amount'] ?? 0, 2);
                    }
                }
                
                $formatted_data[] = $formatted_row;
            }
        }
        
        // Write headers
        if (!empty($headers)) {
            fputcsv($output, $headers);
        }
        
        // Write data rows
        foreach ($formatted_data as $row) {
            fputcsv($output, $row);
        }
        
        fclose($output);
        closeDBConnection($conn);
        exit();
    } elseif ($export_format === 'excel') {
        // Excel export (using HTML table format)
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . str_replace(' ', '_', strtolower($report_title)) . '_' . date('Y-m-d') . '.xls"');
        
        // Get exporter information
        $exporter_name = $_SESSION['full_name'] ?? 'Unknown User';
        $export_date = date('F d, Y h:i A');
        
        echo "<html><head><meta http-equiv='Content-Type' content='text/html; charset=utf-8'></head><body>";
        echo "<style>
            table { border-collapse: collapse; width: 100%; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #f2f2f2; font-weight: bold; }
            .metadata { font-size: 12px; color: #666; margin-bottom: 10px; }
        </style>";
        echo "<table>";
        
        // Add report metadata
        echo "<tr><td colspan='10' style='font-size: 16px; font-weight: bold; border: none; padding-bottom: 10px;'>" . htmlspecialchars($report_title) . "</td></tr>";
        echo "<tr><td colspan='10' style='border: none; padding: 5px;'><div class='metadata'>";
        echo "<strong>Export Date:</strong> " . htmlspecialchars($export_date) . "<br>";
        echo "<strong>Exported By:</strong> " . htmlspecialchars($exporter_name) . "<br>";
        
        // Add report-specific metadata
        if ($report_type == 'inventory' && $warehouse_filter) {
            $warehouse_name = getWarehouseName($conn, $warehouse_filter);
            if ($warehouse_name) {
                echo "<strong>Warehouse:</strong> " . htmlspecialchars($warehouse_name) . "<br>";
            }
        } elseif ($report_type == 'warehouse') {
            if ($warehouse_filter) {
                $warehouse_name = getWarehouseName($conn, $warehouse_filter);
                if ($warehouse_name) {
                    echo "<strong>Warehouse:</strong> " . htmlspecialchars($warehouse_name) . "<br>";
                }
            }
            echo "<strong>Date Range:</strong> " . date('M d, Y', strtotime($date_from)) . " to " . date('M d, Y', strtotime($date_to)) . "<br>";
        } elseif ($report_type == 'procurement') {
            if ($supplier_filter) {
                $supplier_name = getSupplierName($conn, $supplier_filter);
                if ($supplier_name) {
                    echo "<strong>Supplier:</strong> " . htmlspecialchars($supplier_name) . "<br>";
                }
            }
            echo "<strong>Date Range:</strong> " . date('M d, Y', strtotime($date_from)) . " to " . date('M d, Y', strtotime($date_to)) . "<br>";
        } elseif (in_array($report_type, ['logistics', 'demand', 'cost', 'sales'])) {
            echo "<strong>Date Range:</strong> " . date('M d, Y', strtotime($date_from)) . " to " . date('M d, Y', strtotime($date_to)) . "<br>";
            if ($report_type == 'cost' && $supplier_filter) {
                $supplier_name = getSupplierName($conn, $supplier_filter);
                if ($supplier_name) {
                    echo "<strong>Supplier:</strong> " . htmlspecialchars($supplier_name) . "<br>";
                }
            }
        }
        echo "</div></td></tr>";
        echo "<tr><td colspan='10' style='border: none; height: 10px;'></td></tr>";
        
        // Process and format data (same logic as CSV)
        $formatted_data = [];
        $headers = [];
        
        if (!empty($report_data)) {
            foreach ($report_data as $row) {
                $formatted_row = [];
                
                if ($report_type == 'inventory') {
                    if (empty($headers)) {
                        $headers = ['Product Name', 'SKU', 'Warehouse', 'Quantity', 'Stock Status'];
                        if (canViewFinancialData()) {
                            $headers[] = 'Unit Price';
                            $headers[] = 'Total Value';
                        }
                        $headers[] = 'Last Updated';
                    }
                    $formatted_row = [
                        $row['product_name'] ?? '',
                        $row['sku'] ?? '',
                        $row['warehouse_name'] ?? '',
                        $row['quantity'] ?? 0,
                        $row['stock_status'] ?? '',
                    ];
                    if (canViewFinancialData()) {
                        $formatted_row[] = number_format($row['unit_price'] ?? 0, 2);
                        $formatted_row[] = number_format(($row['quantity'] ?? 0) * ($row['unit_price'] ?? 0), 2);
                    }
                    $updated_at = $row['updated_at'] ?? $row['last_updated'] ?? '';
                    $formatted_row[] = !empty($updated_at) ? formatDateForExport($updated_at) : 'N/A';
                    
                } elseif ($report_type == 'warehouse') {
                    if (empty($headers)) {
                        $headers = ['Warehouse Name', 'Location', 'Capacity', 'Utilized', 'Capacity %', 'Products', 'Total Quantity', 'Low Stock Items', 'Status', 'Last Updated'];
                    }
                    $formatted_row = [
                        $row['name'] ?? '',
                        $row['location'] ?? 'N/A',
                        number_format($row['capacity'] ?? 0),
                        number_format($row['utilized_capacity'] ?? 0),
                        number_format($row['capacity_percent'] ?? 0, 1) . '%',
                        $row['product_count'] ?? 0,
                        number_format($row['total_quantity'] ?? 0),
                        $row['low_stock_items'] ?? 0,
                        ucfirst($row['status'] ?? 'active'),
                        (!empty($row['updated_at']) || !empty($row['last_updated'])) ? formatDateForExport($row['updated_at'] ?? $row['last_updated']) : 'N/A',
                    ];
                    
                } elseif ($report_type == 'procurement') {
                    if (empty($headers)) {
                        $headers = ['PO Number', 'Supplier', 'Order Date', 'Delivery Date', 'Items', 'Status'];
                        if (canViewFinancialData()) {
                            $headers[] = 'Amount';
                        }
                        $headers[] = 'Last Updated';
                    }
                    $formatted_row = [
                        $row['po_number'] ?? '',
                        $row['supplier_name'] ?? '',
                        formatDateForExport($row['order_date'] ?? ''),
                        formatDateForExport($row['delivery_date'] ?? ''),
                        $row['item_count'] ?? 0,
                        ucfirst(str_replace('_', ' ', $row['status'] ?? '')),
                    ];
                    if (canViewFinancialData()) {
                        $formatted_row[] = number_format($row['total_amount'] ?? 0, 2);
                    }
                    $updated_at = $row['updated_at'] ?? $row['last_updated'] ?? '';
                    $formatted_row[] = !empty($updated_at) ? formatDateForExport($updated_at) : 'N/A';
                    
                } elseif ($report_type == 'supplier') {
                    if (empty($headers)) {
                        $headers = ['Company Name', 'Performance Rating', 'Total Orders', 'Completed Orders', 'Cancelled Orders'];
                        if (canViewFinancialData()) {
                            $headers[] = 'Total Value';
                        }
                        $headers[] = 'Avg Delivery Days';
                    }
                    $formatted_row = [
                        $row['company_name'] ?? '',
                        number_format($row['performance_rating'] ?? 0, 2) . '/5.0',
                        $row['total_orders'] ?? 0,
                        $row['completed_orders'] ?? 0,
                        $row['cancelled_orders'] ?? 0,
                    ];
                    if (canViewFinancialData()) {
                        $formatted_row[] = number_format($row['total_value'] ?? 0, 2);
                    }
                    $formatted_row[] = $row['avg_delivery_days'] ? round($row['avg_delivery_days']) : 'N/A';
                    
                } elseif ($report_type == 'logistics') {
                    if (empty($headers)) {
                        $headers = ['Shipment Number', 'Type', 'PO Number', 'Origin', 'Destination', 'Supplier', 'Vehicle', 'Driver', 'Scheduled Date', 'Delivery Date', 'Items', 'Status'];
                        if (canViewFinancialData()) {
                            $headers[] = 'Transport Cost';
                        }
                    }
                    // All shipments are inbound - origin is supplier, destination is warehouse
                    $origin = $row['supplier_name'] ?? 'N/A';
                    $destination = $row['destination_warehouse_name'] ?? 'N/A';
                    
                    $formatted_row = [
                        $row['shipment_number'] ?? '',
                        ucfirst($row['type'] ?? ''),
                        $row['po_number'] ?? 'N/A',
                        $origin,
                        $destination,
                        $row['supplier_name'] ?? 'N/A',
                        $row['vehicle_name'] ?? 'N/A',
                        $row['driver_name'] ?? 'N/A',
                        formatDateForExport($row['scheduled_date'] ?? ''),
                        formatDateForExport($row['delivery_date'] ?? ''),
                        $row['total_items'] ?? 0,
                        ucfirst(str_replace('_', ' ', $row['status'] ?? '')),
                    ];
                    if (canViewFinancialData()) {
                        $formatted_row[] = number_format($row['transport_cost'] ?? 0, 2);
                    }
                    
                } elseif ($report_type == 'demand') {
                    if (empty($headers)) {
                        $headers = ['Product Name', 'SKU', 'Forecast Period', 'Forecasted Quantity', 'Confidence Level', 'Created By'];
                    }
                    $formatted_row = [
                        $row['product_name'] ?? '',
                        $row['sku'] ?? '',
                        formatDateForExport($row['forecast_period'] ?? ''),
                        $row['forecasted_quantity'] ?? 0,
                        ($row['confidence_level'] ?? 0) . '%',
                        $row['created_by_name'] ?? '',
                    ];
                    
                } elseif ($report_type == 'cost') {
                    if (empty($headers)) {
                        $headers = ['Month', 'Order Count', 'Procurement Cost', 'Transport Cost', 'Total Cost'];
                    }
                    $formatted_row = [
                        date('F Y', strtotime($row['month'] . '-01')),
                        $row['order_count'] ?? 0,
                        number_format($row['total_procurement_cost'] ?? 0, 2),
                        number_format($row['total_transport_cost'] ?? 0, 2),
                        number_format($row['total_cost'] ?? 0, 2),
                    ];
                } elseif ($report_type == 'sales') {
                    if (empty($headers)) {
                        $headers = ['Product Name', 'SKU', 'Sale Date', 'Quantity', 'Unit Price'];
                        if (canViewFinancialData()) {
                            $headers[] = 'Total Amount';
                        }
                    }
                    $formatted_row = [
                        $row['product_name'] ?? '',
                        $row['sku'] ?? '',
                        formatDateForExport($row['sale_date'] ?? ''),
                        $row['quantity'] ?? 0,
                        number_format($row['unit_price'] ?? 0, 2),
                    ];
                    if (canViewFinancialData()) {
                        $formatted_row[] = number_format($row['total_amount'] ?? 0, 2);
                    }
                }
                
                $formatted_data[] = $formatted_row;
            }
            
            // Write headers
            if (!empty($headers)) {
            echo "<tr>";
            foreach ($headers as $header) {
                    echo "<th>" . htmlspecialchars($header) . "</th>";
            }
            echo "</tr>";
            }
            
            // Write data rows
            foreach ($formatted_data as $formatted_row) {
                echo "<tr>";
                foreach ($formatted_row as $cell) {
                    echo "<td>" . htmlspecialchars($cell) . "</td>";
                }
                echo "</tr>";
            }
        }
        
        echo "</table></body></html>";
        closeDBConnection($conn);
        exit();
    } elseif ($export_format === 'pdf') {
        // PDF export using HTML (browser print to PDF)
        header('Content-Type: text/html; charset=utf-8');
        
        // Get exporter information
        $exporter_name = $_SESSION['full_name'] ?? 'Unknown User';
        $export_date = date('F d, Y h:i A');
        $logo_path = BASE_URL . 'assets/img/logo5.png';
        
        // Process and format data (same logic as CSV/Excel)
        $formatted_data = [];
        $headers = [];
        
        if (!empty($report_data)) {
            foreach ($report_data as $row) {
                $formatted_row = [];
                
                if ($report_type == 'inventory') {
                    if (empty($headers)) {
                        $headers = ['Product Name', 'SKU', 'Warehouse', 'Quantity', 'Stock Status'];
                        if (canViewFinancialData()) {
                            $headers[] = 'Unit Price';
                            $headers[] = 'Total Value';
                        }
                        $headers[] = 'Last Updated';
                    }
                    $formatted_row = [
                        $row['product_name'] ?? '',
                        $row['sku'] ?? '',
                        $row['warehouse_name'] ?? '',
                        $row['quantity'] ?? 0,
                        $row['stock_status'] ?? '',
                    ];
                    if (canViewFinancialData()) {
                        $formatted_row[] = number_format($row['unit_price'] ?? 0, 2);
                        $formatted_row[] = number_format(($row['quantity'] ?? 0) * ($row['unit_price'] ?? 0), 2);
                    }
                    $updated_at = $row['updated_at'] ?? $row['last_updated'] ?? '';
                    $formatted_row[] = !empty($updated_at) ? formatDateForExport($updated_at) : 'N/A';
                    
                } elseif ($report_type == 'warehouse') {
                    if (empty($headers)) {
                        $headers = ['Warehouse Name', 'Location', 'Capacity', 'Utilized', 'Capacity %', 'Products', 'Total Quantity', 'Low Stock Items', 'Status', 'Last Updated'];
                    }
                    $formatted_row = [
                        $row['name'] ?? '',
                        $row['location'] ?? 'N/A',
                        number_format($row['capacity'] ?? 0),
                        number_format($row['utilized_capacity'] ?? 0),
                        number_format($row['capacity_percent'] ?? 0, 1) . '%',
                        $row['product_count'] ?? 0,
                        number_format($row['total_quantity'] ?? 0),
                        $row['low_stock_items'] ?? 0,
                        ucfirst($row['status'] ?? 'active'),
                        (!empty($row['updated_at']) || !empty($row['last_updated'])) ? formatDateForExport($row['updated_at'] ?? $row['last_updated']) : 'N/A',
                    ];
                    
                } elseif ($report_type == 'procurement') {
                    if (empty($headers)) {
                        $headers = ['PO Number', 'Supplier', 'Order Date', 'Delivery Date', 'Items', 'Status'];
                        if (canViewFinancialData()) {
                            $headers[] = 'Amount';
                        }
                        $headers[] = 'Last Updated';
                    }
                    $formatted_row = [
                        $row['po_number'] ?? '',
                        $row['supplier_name'] ?? '',
                        formatDateForExport($row['order_date'] ?? ''),
                        formatDateForExport($row['delivery_date'] ?? ''),
                        $row['item_count'] ?? 0,
                        ucfirst(str_replace('_', ' ', $row['status'] ?? '')),
                    ];
                    if (canViewFinancialData()) {
                        $formatted_row[] = number_format($row['total_amount'] ?? 0, 2);
                    }
                    $updated_at = $row['updated_at'] ?? $row['last_updated'] ?? '';
                    $formatted_row[] = !empty($updated_at) ? formatDateForExport($updated_at) : 'N/A';
                    
                } elseif ($report_type == 'supplier') {
                    if (empty($headers)) {
                        $headers = ['Company Name', 'Performance Rating', 'Total Orders', 'Completed Orders', 'Cancelled Orders'];
                        if (canViewFinancialData()) {
                            $headers[] = 'Total Value';
                        }
                        $headers[] = 'Avg Delivery Days';
                    }
                    $formatted_row = [
                        $row['company_name'] ?? '',
                        number_format($row['performance_rating'] ?? 0, 2) . '/5.0',
                        $row['total_orders'] ?? 0,
                        $row['completed_orders'] ?? 0,
                        $row['cancelled_orders'] ?? 0,
                    ];
                    if (canViewFinancialData()) {
                        $formatted_row[] = number_format($row['total_value'] ?? 0, 2);
                    }
                    $formatted_row[] = $row['avg_delivery_days'] ? round($row['avg_delivery_days']) : 'N/A';
                    
                } elseif ($report_type == 'logistics') {
                    if (empty($headers)) {
                        $headers = ['Shipment Number', 'Type', 'PO Number', 'Origin', 'Destination', 'Supplier', 'Vehicle', 'Driver', 'Scheduled Date', 'Delivery Date', 'Items', 'Status'];
                        if (canViewFinancialData()) {
                            $headers[] = 'Transport Cost';
                        }
                    }
                    // All shipments are inbound - origin is supplier, destination is warehouse
                    $origin = $row['supplier_name'] ?? 'N/A';
                    $destination = $row['destination_warehouse_name'] ?? 'N/A';
                    
                    $formatted_row = [
                        $row['shipment_number'] ?? '',
                        ucfirst($row['type'] ?? ''),
                        $row['po_number'] ?? 'N/A',
                        $origin,
                        $destination,
                        $row['supplier_name'] ?? 'N/A',
                        $row['vehicle_name'] ?? 'N/A',
                        $row['driver_name'] ?? 'N/A',
                        formatDateForExport($row['scheduled_date'] ?? ''),
                        formatDateForExport($row['delivery_date'] ?? ''),
                        $row['total_items'] ?? 0,
                        ucfirst(str_replace('_', ' ', $row['status'] ?? '')),
                    ];
                    if (canViewFinancialData()) {
                        $formatted_row[] = number_format($row['transport_cost'] ?? 0, 2);
                    }
                    
                } elseif ($report_type == 'demand') {
                    if (empty($headers)) {
                        $headers = ['Product Name', 'SKU', 'Forecast Period', 'Forecasted Quantity', 'Confidence Level', 'Created By'];
                    }
                    $formatted_row = [
                        $row['product_name'] ?? '',
                        $row['sku'] ?? '',
                        formatDateForExport($row['forecast_period'] ?? ''),
                        $row['forecasted_quantity'] ?? 0,
                        ($row['confidence_level'] ?? 0) . '%',
                        $row['created_by_name'] ?? '',
                    ];
                    
                } elseif ($report_type == 'cost') {
                    if (empty($headers)) {
                        $headers = ['Month', 'Order Count', 'Procurement Cost', 'Transport Cost', 'Total Cost'];
                    }
                    $formatted_row = [
                        date('F Y', strtotime($row['month'] . '-01')),
                        $row['order_count'] ?? 0,
                        number_format($row['total_procurement_cost'] ?? 0, 2),
                        number_format($row['total_transport_cost'] ?? 0, 2),
                        number_format($row['total_cost'] ?? 0, 2),
                    ];
                } elseif ($report_type == 'sales') {
                    if (empty($headers)) {
                        $headers = ['Product Name', 'SKU', 'Sale Date', 'Quantity', 'Unit Price'];
                        if (canViewFinancialData()) {
                            $headers[] = 'Total Amount';
                        }
                    }
                    $formatted_row = [
                        $row['product_name'] ?? '',
                        $row['sku'] ?? '',
                        formatDateForExport($row['sale_date'] ?? ''),
                        $row['quantity'] ?? 0,
                        number_format($row['unit_price'] ?? 0, 2),
                    ];
                    if (canViewFinancialData()) {
                        $formatted_row[] = number_format($row['total_amount'] ?? 0, 2);
                    }
                }
                
                $formatted_data[] = $formatted_row;
            }
        }
        
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title><?php echo htmlspecialchars($report_title); ?> - <?php echo date('Y-m-d'); ?></title>
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
            <h1><?php echo htmlspecialchars($report_title); ?></h1>
            </div>
            
            <div class="report-info">
                <strong>Export Date:</strong> <?php echo htmlspecialchars($export_date); ?><br>
                <strong>Exported By:</strong> <?php echo htmlspecialchars($exporter_name); ?><br>
                <?php 
                // Add report-specific metadata
                if ($report_type == 'inventory' && $warehouse_filter) {
                    $warehouse_name = getWarehouseName($conn, $warehouse_filter);
                    if ($warehouse_name) {
                        echo "<strong>Warehouse:</strong> " . htmlspecialchars($warehouse_name) . "<br>";
                    }
                } elseif ($report_type == 'warehouse') {
                    if ($warehouse_filter) {
                        $warehouse_name = getWarehouseName($conn, $warehouse_filter);
                        if ($warehouse_name) {
                            echo "<strong>Warehouse:</strong> " . htmlspecialchars($warehouse_name) . "<br>";
                        }
                    }
                    echo "<strong>Date Range:</strong> " . date('M d, Y', strtotime($date_from)) . " to " . date('M d, Y', strtotime($date_to)) . "<br>";
                } elseif ($report_type == 'procurement') {
                    if ($supplier_filter) {
                        $supplier_name = getSupplierName($conn, $supplier_filter);
                        if ($supplier_name) {
                            echo "<strong>Supplier:</strong> " . htmlspecialchars($supplier_name) . "<br>";
                        }
                    }
                    echo "<strong>Date Range:</strong> " . date('M d, Y', strtotime($date_from)) . " to " . date('M d, Y', strtotime($date_to)) . "<br>";
                } elseif ($report_type == 'logistics') {
                    echo "<strong>Date Range:</strong> " . date('M d, Y', strtotime($date_from)) . " to " . date('M d, Y', strtotime($date_to)) . "<br>";
                    
                } elseif ($report_type == 'sales') {
                    echo "<strong>Date Range:</strong> " . date('M d, Y', strtotime($date_from)) . " to " . date('M d, Y', strtotime($date_to)) . "<br>";
                }
                ?>
            </div>
            
            <?php 
            // Add summary section for logistics report
            if ($report_type == 'logistics' && !empty($report_data)) {
                $total_shipments = count($report_data);
                $total_items = array_sum(array_column($report_data, 'total_items'));
                $total_cost = canViewFinancialData() ? array_sum(array_column($report_data, 'transport_cost')) : 0;
                echo "<div class='summary-section'>";
                echo "<h3>Summary Overview</h3>";
                echo "<p><strong>Total Shipments:</strong> " . $total_shipments . "</p>";
                echo "<p><strong>Total Items:</strong> " . number_format($total_items) . "</p>";
                if (canViewFinancialData() && $total_cost > 0) {
                    echo "<p><strong>Total Transport Cost:</strong> " . number_format($total_cost, 2) . "</p>";
                }
                echo "</div>";
            }
            
            if (in_array($report_type, ['demand', 'cost'])):
                ?>
                <div class="report-info">
                <?php
                echo "<strong>Date Range:</strong> " . date('M d, Y', strtotime($date_from)) . " to " . date('M d, Y', strtotime($date_to)) . "<br>";
                if ($report_type == 'cost' && $supplier_filter) {
                    $supplier_name = getSupplierName($conn, $supplier_filter);
                    if ($supplier_name) {
                        echo "<strong>Supplier:</strong> " . htmlspecialchars($supplier_name) . "<br>";
                    }
                }
                ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($formatted_data) && !empty($headers)): ?>
            <table>
                <thead>
                    <tr>
                        <?php foreach ($headers as $header): ?>
                        <th><?php echo htmlspecialchars($header); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($formatted_data as $row): ?>
                    <tr>
                        <?php foreach ($row as $cell): ?>
                        <td><?php echo htmlspecialchars($cell); ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p>No data available for this report.</p>
            <?php endif; ?>
            
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
}

// Get report data for display
$report_result = getReportData($conn, $report_type, $date_from, $date_to, $warehouse_filter, $supplier_filter);
$report_title = $report_result['title'];
$report_data = $report_result['data'];

// Get cost statistics for logistics report
if ($report_type == 'logistics') {
    $result = $conn->query("SELECT SUM(transport_cost) as total FROM shipments WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
    $cost_stats['monthly_total'] = $result->fetch_assoc()['total'] ?? 0;
    
    $result = $conn->query("SELECT AVG(transport_cost) as avg FROM shipments WHERE transport_cost > 0");
    $cost_stats['avg_cost'] = $result->fetch_assoc()['avg'] ?? 0;
    
    $result = $conn->query("SELECT type, SUM(transport_cost) as total FROM shipments WHERE transport_cost > 0 GROUP BY type");
    $cost_by_type = [];
    while ($row = $result->fetch_assoc()) {
        $cost_by_type[$row['type']] = $row['total'];
    }
}

// Get warehouses for filter
$warehouses = [];
$result = $conn->query("SELECT id, name FROM warehouses WHERE status = 'active' ORDER BY name");
while ($row = $result->fetch_assoc()) {
    $warehouses[] = $row;
}

// Get suppliers for filter
$suppliers = [];
$result = $conn->query("SELECT id, company_name FROM suppliers WHERE status = 'active' ORDER BY company_name");
while ($row = $result->fetch_assoc()) {
    $suppliers[] = $row;
}

closeDBConnection($conn);

$pageTitle = 'Reports';
include '../includes/header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-file-alt"></i> Reports</h2>
</div>

<?php if ($report_type == 'logistics' && (canViewFinancialData() || $_SESSION['role'] === 'logistics_manager')): ?>
<!-- Transportation Costs Overview -->
<div class="stats-grid" style="margin-bottom: 2rem;">
    <div class="stat-card">
        <div class="stat-icon teal">
            <i class="fas fa-calendar-alt"></i>
        </div>
        <div class="stat-info">
            <h3><?php echo formatCurrency($cost_stats['monthly_total']); ?></h3>
            <p>This Month</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="fas fa-chart-line"></i>
        </div>
        <div class="stat-info">
            <h3><?php echo formatCurrency($cost_stats['avg_cost']); ?></h3>
            <p>Avg per Shipment</p>
        </div>
    </div>
    
    <?php if (!empty($cost_by_type['inbound'])): ?>
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="fas fa-arrow-down"></i>
        </div>
        <div class="stat-info">
            <h3><?php echo formatCurrency($cost_by_type['inbound']); ?></h3>
            <p>Inbound Total</p>
        </div>
    </div>
    <?php endif; ?>
    
</div>
<?php endif; ?>

<div class="filters-bar">
    <div class="filter-group">
        <label>Report Type</label>
        <select class="form-control" onchange="window.location.href='?type=' + this.value + '&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&warehouse=<?php echo $warehouse_filter; ?>&supplier=<?php echo $supplier_filter; ?>'">
            <?php if (in_array('inventory', $allowed_reports)): ?>
            <option value="inventory" <?php echo $report_type == 'inventory' ? 'selected' : ''; ?>>Inventory Report</option>
            <?php endif; ?>
            <?php if (in_array('warehouse', $allowed_reports)): ?>
            <option value="warehouse" <?php echo $report_type == 'warehouse' ? 'selected' : ''; ?>>Warehouse Report</option>
            <?php endif; ?>
            <?php if (in_array('procurement', $allowed_reports)): ?>
            <option value="procurement" <?php echo $report_type == 'procurement' ? 'selected' : ''; ?>>Procurement Report</option>
            <?php endif; ?>
            <?php if (in_array('supplier', $allowed_reports)): ?>
            <option value="supplier" <?php echo $report_type == 'supplier' ? 'selected' : ''; ?>>Supplier Performance</option>
            <?php endif; ?>
            <?php if (in_array('logistics', $allowed_reports)): ?>
            <option value="logistics" <?php echo $report_type == 'logistics' ? 'selected' : ''; ?>>Logistics & Delivery</option>
            <?php endif; ?>
            <?php if (in_array('demand', $allowed_reports)): ?>
            <option value="demand" <?php echo $report_type == 'demand' ? 'selected' : ''; ?>>Demand Forecast</option>
            <?php endif; ?>
            <?php if (in_array('cost', $allowed_reports) && canViewFinancialData()): ?>
            <option value="cost" <?php echo $report_type == 'cost' ? 'selected' : ''; ?>>Cost Analysis</option>
            <?php endif; ?>
            <?php if (in_array('sales', $allowed_reports)): ?>
            <option value="sales" <?php echo $report_type == 'sales' ? 'selected' : ''; ?>>Sales Report</option>
            <?php endif; ?>
        </select>
    </div>
    
    <?php if (in_array($report_type, ['procurement', 'logistics', 'demand', 'cost', 'warehouse', 'sales'])): ?>
        <div class="filter-group">
            <label>Date From</label>
            <input type="date" class="form-control" value="<?php echo $date_from; ?>" onchange="updateReport()" id="date_from">
        </div>
        <div class="filter-group">
            <label>Date To</label>
            <input type="date" class="form-control" value="<?php echo $date_to; ?>" onchange="updateReport()" id="date_to">
        </div>
    <?php endif; ?>
    
    <?php if (in_array($report_type, ['inventory', 'warehouse'])): ?>
        <div class="filter-group">
            <label>Warehouse</label>
            <select class="form-control" onchange="updateReport()" id="warehouse">
                <option value="">All Warehouses</option>
                <?php foreach ($warehouses as $warehouse): ?>
                    <option value="<?php echo $warehouse['id']; ?>" <?php echo $warehouse_filter == $warehouse['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($warehouse['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    
    <?php if (in_array($report_type, ['procurement', 'cost'])): ?>
        <div class="filter-group">
            <label>Supplier</label>
            <select class="form-control" onchange="updateReport()" id="supplier">
                <option value="">All Suppliers</option>
                <?php foreach ($suppliers as $supplier): ?>
                    <option value="<?php echo $supplier['id']; ?>" <?php echo $supplier_filter == $supplier['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($supplier['company_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    
    <div class="filter-group">
        <div style="display: flex; gap: 5px;">
            </button>
            <button class="btn btn-primary" onclick="exportReport('excel')" title="Export as Excel">
                <i class="fas fa-file-excel"></i> Excel
            </button>
            <button class="btn btn-primary" onclick="exportReport('pdf')" title="Export as PDF">
                <i class="fas fa-file-pdf"></i> PDF
            </button>
        </div>
    </div>
</div>

<div class="dashboard-card">
    <div class="card-header">
        <h3><?php echo $report_title; ?></h3>
    </div>
    <div class="card-body">
        <?php if (empty($report_data)): ?>
            <p class="text-muted">No data found for the selected criteria.</p>
        <?php else: ?>
            <?php if ($report_type == 'inventory'): ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>SKU</th>
                            <th>Warehouse</th>
                            <th>Quantity</th>
                            <?php if (canViewFinancialData()): ?>
                            <th>Unit Price</th>
                            <th>Total Value</th>
                            <?php endif; ?>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['product_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['sku']); ?></td>
                                <td><?php echo htmlspecialchars($row['warehouse_name']); ?></td>
                                <td><?php echo $row['quantity']; ?></td>
                                <?php if (canViewFinancialData()): ?>
                                <td><?php echo formatCurrency($row['unit_price']); ?></td>
                                <td><?php echo formatCurrency($row['quantity'] * $row['unit_price']); ?></td>
                                <?php endif; ?>
                                <td><span class="badge badge-<?php echo $row['stock_status'] == 'Low' ? 'danger' : 'success'; ?>"><?php echo $row['stock_status']; ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php elseif ($report_type == 'procurement'): ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>PO Number</th>
                            <th>Supplier</th>
                            <th>Order Date</th>
                            <th>Delivery Date</th>
                            <th>Items</th>
                            <?php if (canViewFinancialData()): ?>
                            <th>Amount</th>
                            <?php endif; ?>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['po_number']); ?></td>
                                <td><?php echo htmlspecialchars($row['supplier_name']); ?></td>
                                <td><?php echo formatDate($row['order_date']); ?></td>
                                <td><?php echo $row['delivery_date'] ? formatDate($row['delivery_date']) : 'N/A'; ?></td>
                                <td><?php echo $row['item_count']; ?></td>
                                <?php if (canViewFinancialData()): ?>
                                <td><?php echo formatCurrency($row['total_amount']); ?></td>
                                <?php endif; ?>
                                <td><span class="badge badge-<?php echo $row['status']; ?>"><?php echo ucfirst(str_replace('_', ' ', $row['status'])); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php elseif ($report_type == 'supplier'): ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Company Name</th>
                            <th>Performance Rating</th>
                            <th>Total Orders</th>
                            <th>Completed Orders</th>
                            <th>Cancelled Orders</th>
                            <?php if (canViewFinancialData()): ?>
                            <th>Total Value</th>
                            <?php endif; ?>
                            <th>Avg Delivery Days</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['company_name']); ?></td>
                                <td><span class="badge badge-<?php echo $row['performance_rating'] >= 4 ? 'success' : ($row['performance_rating'] >= 3 ? 'warning' : 'danger'); ?>"><?php echo number_format($row['performance_rating'], 2); ?>/5.0</span></td>
                                <td><?php echo $row['total_orders']; ?></td>
                                <td><?php echo $row['completed_orders']; ?></td>
                                <td><?php echo $row['cancelled_orders']; ?></td>
                                <?php if (canViewFinancialData()): ?>
                                <td><?php echo formatCurrency($row['total_value'] ?? 0); ?></td>
                                <?php endif; ?>
                                <td><?php echo $row['avg_delivery_days'] ? round($row['avg_delivery_days']) : 'N/A'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php elseif ($report_type == 'logistics'): ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Shipment #</th>
                            <th>PO Number</th>
                            <th>Origin</th>
                            <th>Destination</th>
                            <th>Scheduled Date</th>
                            <th>Items</th>
                            <?php if (canViewFinancialData()): ?>
                            <th>Cost</th>
                            <?php endif; ?>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['shipment_number']); ?></td>
                                <td><?php echo $row['po_number'] ?? 'N/A'; ?></td>
                                <td><?php 
                                    // For inbound shipments, origin is the supplier
                                    if (empty($row['origin_warehouse'])) {
                                        echo $row['supplier_name'] ?? 'N/A';
                                    } else {
                                        echo $row['origin_warehouse'] ?? 'N/A';
                                    }
                                ?></td>
                                <td><?php echo $row['destination_warehouse'] ?? 'N/A'; ?></td>
                                <td><?php echo formatDate($row['scheduled_date']); ?></td>
                                <td><?php echo $row['total_items']; ?></td>
                                <?php if (canViewFinancialData()): ?>
                                <td><?php echo formatCurrency($row['transport_cost']); ?></td>
                                <?php endif; ?>
                                <td><span class="badge badge-<?php echo $row['status']; ?>"><?php echo ucfirst(str_replace('_', ' ', $row['status'])); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php elseif ($report_type == 'demand'): ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>SKU</th>
                            <th>Forecast Period</th>
                            <th>Forecasted Quantity</th>
                            <th>Confidence Level</th>
                            <th>Created By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['product_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['sku']); ?></td>
                                <td><?php echo formatDate($row['forecast_period']); ?></td>
                                <td><strong><?php echo $row['forecasted_quantity']; ?></strong></td>
                                <td><?php echo $row['confidence_level']; ?>%</td>
                                <td><?php echo htmlspecialchars($row['created_by_name']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php elseif ($report_type == 'cost'): ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Order Count</th>
                            <th>Procurement Cost</th>
                            <th>Transport Cost</th>
                            <th>Total Cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data as $row): ?>
                            <tr>
                                <td><strong><?php echo date('F Y', strtotime($row['month'] . '-01')); ?></strong></td>
                                <td><?php echo $row['order_count']; ?></td>
                                <td><?php echo formatCurrency($row['total_procurement_cost'] ?? 0); ?></td>
                                <td><?php echo formatCurrency($row['total_transport_cost'] ?? 0); ?></td>
                                <td><strong><?php echo formatCurrency($row['total_cost'] ?? 0); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php elseif ($report_type == 'warehouse'): ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Warehouse Name</th>
                            <th>Location</th>
                            <th>Capacity</th>
                            <th>Utilized</th>
                            <th>Capacity %</th>
                            <th>Products</th>
                            <th>Total Quantity</th>
                            <th>Low Stock Items</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data as $row): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($row['location'] ?? 'N/A'); ?></td>
                                <td><?php echo number_format($row['capacity'] ?? 0); ?></td>
                                <td><?php echo number_format($row['utilized_capacity'] ?? 0); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo ($row['capacity_percent'] ?? 0) >= 90 ? 'danger' : (($row['capacity_percent'] ?? 0) >= 70 ? 'warning' : 'success'); ?>">
                                        <?php echo number_format($row['capacity_percent'] ?? 0, 1); ?>%
                                    </span>
                                </td>
                                <td><?php echo $row['product_count'] ?? 0; ?></td>
                                <td><?php echo number_format($row['total_quantity'] ?? 0); ?></td>
                                <td>
                                    <?php if (($row['low_stock_items'] ?? 0) > 0): ?>
                                        <span class="badge badge-danger"><?php echo $row['low_stock_items']; ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-success">0</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo ($row['status'] ?? 'active') == 'active' ? 'success' : 'secondary'; ?>">
                                        <?php echo ucfirst($row['status'] ?? 'active'); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php elseif ($report_type == 'sales'): ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Product Name</th>
                            <th>SKU</th>
                            <th>Sale Date</th>
                            <th>Quantity</th>
                            <?php if (canViewFinancialData()): ?>
                            <th>Unit Price</th>
                            <th>Total Amount</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['product_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['sku']); ?></td>
                                <td><?php echo formatDate($row['sale_date']); ?></td>
                                <td><?php echo $row['quantity']; ?></td>
                                <?php if (canViewFinancialData()): ?>
                                <td><?php echo formatCurrency($row['unit_price']); ?></td>
                                <td><strong><?php echo formatCurrency($row['total_amount'] ?? ($row['quantity'] * $row['unit_price'])); ?></strong></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
function updateReport() {
    const params = new URLSearchParams(window.location.search);
    params.set('type', '<?php echo $report_type; ?>');
    
    const dateFrom = document.getElementById('date_from');
    const dateTo = document.getElementById('date_to');
    const warehouse = document.getElementById('warehouse');
    const supplier = document.getElementById('supplier');
    
    if (dateFrom) params.set('date_from', dateFrom.value);
    if (dateTo) params.set('date_to', dateTo.value);
    if (warehouse) params.set('warehouse', warehouse.value);
    if (supplier) params.set('supplier', supplier.value);
    
    window.location.href = '?' + params.toString();
}

function exportReport(format) {
    const params = new URLSearchParams(window.location.search);
    params.set('export', format);
    window.location.href = '?' + params.toString();
}
</script>

<?php include '../includes/footer.php'; ?>
