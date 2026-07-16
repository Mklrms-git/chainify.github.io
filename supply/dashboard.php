<?php
require_once 'config/config.php';
requireLogin();

$conn = getDBConnection();

// Get dashboard statistics
$stats = [];

// Total products in stock
$result = $conn->query("SELECT COUNT(DISTINCT product_id) as total FROM inventory WHERE quantity > 0");
$stats['total_products'] = $result->fetch_assoc()['total'];

// Low stock alerts
$result = $conn->query("
    SELECT COUNT(*) as total FROM inventory i
    JOIN products p ON i.product_id = p.id
    WHERE i.quantity <= p.min_stock_level
");
$stats['low_stock'] = $result->fetch_assoc()['total'];

// Pending purchase orders
$result = $conn->query("SELECT COUNT(*) as total FROM purchase_orders WHERE status = 'pending'");
$stats['pending_orders'] = $result->fetch_assoc()['total'];

// Deliveries in transit
$result = $conn->query("SELECT COUNT(*) as total FROM shipments WHERE status = 'in_transit'");
$stats['in_transit'] = $result->fetch_assoc()['total'];

// Active shipments
$result = $conn->query("SELECT COUNT(*) as total FROM shipments WHERE status IN ('scheduled', 'in_transit')");
$stats['active_shipments'] = $result->fetch_assoc()['total'];

// Supplier performance (average rating)
$result = $conn->query("SELECT AVG(performance_rating) as avg_rating FROM suppliers WHERE status = 'active'");
$stats['supplier_rating'] = round($result->fetch_assoc()['avg_rating'], 2);

// Recent purchase orders
$recent_orders = [];
$result = $conn->query("
    SELECT po.*, s.company_name as supplier_name, u.full_name as created_by_name
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    JOIN users u ON po.created_by = u.id
    ORDER BY po.created_at DESC
    LIMIT 5
");
while ($row = $result->fetch_assoc()) {
    $recent_orders[] = $row;
}

// Low stock items with supplier information
$low_stock_items = [];
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
        s.company_name as supplier_name
    FROM inventory i
    JOIN products p ON i.product_id = p.id
    JOIN warehouses w ON i.warehouse_id = w.id
    LEFT JOIN supplier_products sp ON p.id = sp.product_id AND sp.status = 'active' AND sp.is_primary = 1
    LEFT JOIN suppliers s ON sp.supplier_id = s.id AND s.status = 'active'
    WHERE i.quantity <= p.min_stock_level AND sp.supplier_id IS NOT NULL
    ORDER BY i.quantity ASC, p.name ASC, w.name ASC
    LIMIT 5
");
while ($row = $result->fetch_assoc()) {
    $low_stock_items[] = $row;
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

closeDBConnection($conn);

$pageTitle = 'Dashboard';
include 'includes/header.php';

// Display error messages if redirected from permission checks
if (isset($_GET['error'])) {
    $error_message = '';
    switch ($_GET['error']) {
        case 'access_denied':
            $error_message = 'You do not have permission to access that module.';
            break;
        case 'permission_denied':
            $error_message = 'You do not have permission to perform that action.';
            break;
        case 'approval_denied':
            $error_message = 'You do not have permission to approve this request.';
            break;
    }
    if ($error_message) {
        echo '<div class="alert alert-error">' . htmlspecialchars($error_message) . '</div>';
    }
}

// Display supplier portal error if redirected
if (isset($_SESSION['supplier_portal_error'])) {
    echo '<div class="alert alert-error">' . htmlspecialchars($_SESSION['supplier_portal_error']) . '</div>';
    unset($_SESSION['supplier_portal_error']);
}
?>

<div class="dashboard">
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon blue">
                <i class="fas fa-box"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo $stats['total_products']; ?></h3>
                <p>Products in Stock</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon orange">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo $stats['low_stock']; ?></h3>
                <p>Low Stock Alerts</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon yellow">
                <i class="fas fa-clipboard-list"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo $stats['pending_orders']; ?></h3>
                <p>Pending Orders</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon green">
                <i class="fas fa-truck"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo $stats['in_transit']; ?></h3>
                <p>Deliveries in Transit</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon purple">
                <i class="fas fa-shipping-fast"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo $stats['active_shipments']; ?></h3>
                <p>Active Shipments</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon teal">
                <i class="fas fa-star"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo $stats['supplier_rating']; ?>/5.0</h3>
                <p>Avg Supplier Rating</p>
            </div>
        </div>
    </div>
    
    <div class="dashboard-grid">
        <div class="dashboard-card">
            <div class="card-header">
                <h3><i class="fas fa-clipboard-list"></i> Recent Purchase Orders</h3>
                <a href="<?php echo BASE_URL; ?>modules/procurement.php" class="btn-link">View All</a>
            </div>
            <div class="card-body">
                <?php if (empty($recent_orders)): ?>
                    <p class="text-muted">No purchase orders found.</p>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>PO Number</th>
                                <th>Supplier</th>
                                <th>Status</th>
                                <th>Delivery Date</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_orders as $order): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($order['po_number']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($order['supplier_name']); ?></td>
                                    <td><span class="badge badge-<?php echo $order['status']; ?>"><?php echo ucfirst(str_replace('_', ' ', $order['status'])); ?></span></td>
                                    <td><?php echo $order['delivery_date'] ? formatDate($order['delivery_date']) : 'N/A'; ?></td>
                                    <td><?php echo formatCurrency($order['total_amount']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="dashboard-card">
            <div class="card-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Low Stock Alerts</h3>
                <?php if (canCreate('procurement')): ?>
                <button class="btn-link" onclick="viewLowStockItems()" style="background: none; border: none; cursor: pointer; padding: 0;">
                    View All
                </button>
                <?php else: ?>
                <a href="<?php echo BASE_URL; ?>modules/inventory.php" class="btn-link">View All</a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (empty($low_stock_items)): ?>
                    <p class="text-muted">No low stock items.</p>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>SKU</th>
                                <th>Warehouse</th>
                                <th>Current</th>
                                <th>Min Level</th>
                                <th>Supplier</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($low_stock_items as $item): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($item['product_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($item['sku']); ?></td>
                                    <td><?php echo htmlspecialchars($item['warehouse_name']); ?></td>
                                    <td class="text-danger"><strong><?php echo $item['current_quantity']; ?></strong></td>
                                    <td><?php echo $item['min_stock_level']; ?></td>
                                    <td><?php echo htmlspecialchars($item['supplier_name'] ?? 'N/A'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function formatCurrency(amount) {
    return '₱' + parseFloat(amount).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
}

function viewLowStockItems() {
    // Show loading state
    document.getElementById('lowStockItemsContent').innerHTML = '<p class="text-muted">Loading low stock items...</p>';
    openModal('lowStockItemsModal');
    
    // Fetch low stock items
    fetch('?ajax=get_low_stock_items')
        .then(response => response.json())
        .then(data => {
            if (!data || data.length === 0) {
                document.getElementById('lowStockItemsContent').innerHTML = '<p class="text-muted">No low stock items found with suppliers.</p>';
                return;
            }
            
            // Group items by supplier, keeping warehouse information separate
            // This allows us to track which warehouse needs each product
            const supplierGroups = {};
            data.forEach(item => {
                const supplierId = item.supplier_id;
                if (!supplierGroups[supplierId]) {
                    supplierGroups[supplierId] = {
                        supplier_name: item.supplier_name,
                        items: []
                    };
                }
                
                // Calculate reorder quantity for this specific warehouse-product combination
                const reorderQty = item.reorder_quantity || Math.max(
                    item.min_stock_level * 2 - item.current_quantity,
                    item.min_stock_level
                );
                
                supplierGroups[supplierId].items.push({
                    product_id: item.product_id,
                    product_name: item.product_name,
                    sku: item.sku,
                    min_stock_level: item.min_stock_level,
                    current_quantity: item.current_quantity,
                    reorder_quantity: reorderQty,
                    unit_price: item.unit_price,
                    warehouse_id: item.warehouse_id,
                    warehouse_name: item.warehouse_name
                });
            });
            
            let html = '<form method="POST" action="<?php echo BASE_URL; ?>modules/procurement.php" id="automaticPOForm">';
            html += '<input type="hidden" name="create_automatic_po" value="1">';
            
            // Expected date field
            html += '<div class="form-group">';
            html += '<label for="automatic_expected_date">Expected Delivery Date *</label>';
            html += '<input type="date" name="expected_date" id="automatic_expected_date" class="form-control" min="' + new Date().toISOString().split('T')[0] + '" required>';
            html += '<small class="text-muted">Expected delivery date for the purchase order</small>';
            html += '</div>';
            
            // Items grouped by supplier
            html += '<div style="max-height: 500px; overflow-y: auto; border: 1px solid #ddd; padding: 15px; border-radius: 4px; margin-bottom: 20px;">';
            html += '<h4 style="margin-bottom: 15px;">Low Stock Items (Pre-filled)</h4>';
            
            Object.keys(supplierGroups).forEach(supplierId => {
                const group = supplierGroups[supplierId];
                html += '<div style="margin-bottom: 30px; padding-bottom: 20px; border-bottom: 2px solid #ddd;">';
                html += '<h5 style="color: #007bff; margin-bottom: 15px;"><i class="fas fa-truck"></i> Supplier: ' + group.supplier_name + '</h5>';
                html += '<table class="data-table" style="width: 100%;">';
                html += '<thead><tr><th>Product</th><th>SKU</th><th>Warehouse</th><th>Current</th><th>Min Level</th><th>Reorder Qty</th><th>Unit Price</th><th>Subtotal</th></tr></thead>';
                html += '<tbody>';
                
                group.items.forEach(item => {
                    const reorderQty = item.reorder_quantity || (item.min_stock_level * 2 - item.current_quantity);
                    const subtotal = reorderQty * item.unit_price;
                    
                    html += '<tr>';
                    html += '<td><strong>' + item.product_name + '</strong></td>';
                    html += '<td>' + item.sku + '</td>';
                    html += '<td><small>' + item.warehouse_name + '</small></td>';
                    html += '<td class="text-danger"><strong>' + item.current_quantity + '</strong></td>';
                    html += '<td>' + item.min_stock_level + '</td>';
                    html += '<td>';
                    html += '<input type="number" name="quantity_' + item.product_id + '" value="' + reorderQty + '" min="1" class="form-control" style="width: 100px;" onchange="updateAutomaticPOTotal()" required>';
                    html += '<input type="hidden" name="selected_products[]" value="' + item.product_id + '">';
                    html += '<input type="hidden" name="unit_price_' + item.product_id + '" value="' + item.unit_price + '">';
                    html += '<input type="hidden" name="warehouse_id_' + item.product_id + '" value="' + item.warehouse_id + '">';
                    html += '</td>';
                    html += '<td>' + formatCurrency(item.unit_price) + '</td>';
                    html += '<td class="subtotal-' + item.product_id + '">' + formatCurrency(subtotal) + '</td>';
                    html += '</tr>';
                });
                
                html += '</tbody></table>';
                html += '</div>';
            });
            
            html += '</div>';
            
            // Notes field
            html += '<div class="form-group">';
            html += '<label for="automatic_notes">Notes</label>';
            html += '<textarea name="notes" id="automatic_notes" class="form-control" rows="3" placeholder="Automatic order generated from low stock alert">Automatic order generated from low stock alert</textarea>';
            html += '</div>';
            
            // Info box
            html += '<div style="padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px; margin-bottom: 20px;">';
            html += '<i class="fas fa-info-circle"></i> <strong>Note:</strong> This automatic purchase order will be created and sent to suppliers, but it requires admin approval before processing.';
            html += '</div>';
            
            // Submit button
            html += '<div style="display: flex; gap: 10px; justify-content: flex-end;">';
            html += '<button type="button" class="btn btn-secondary" onclick="closeModal(\'lowStockItemsModal\')">';
            html += '<i class="fas fa-times"></i> Cancel';
            html += '</button>';
            html += '<button type="submit" class="btn btn-primary">';
            html += '<i class="fas fa-magic"></i> Create Automatic Order';
            html += '</button>';
            html += '</div>';
            
            html += '</form>';
            
            document.getElementById('lowStockItemsContent').innerHTML = html;
            
            // Set default expected date (7 days from now)
            const dateInput = document.getElementById('automatic_expected_date');
            const defaultDate = new Date();
            defaultDate.setDate(defaultDate.getDate() + 7);
            dateInput.value = defaultDate.toISOString().split('T')[0];
        })
        .catch(error => {
            console.error('Error loading low stock items:', error);
            document.getElementById('lowStockItemsContent').innerHTML = '<p class="text-danger">Error loading low stock items.</p>';
        });
}

function updateAutomaticPOTotal() {
    // Update subtotals when quantities change
    const form = document.getElementById('automaticPOForm');
    if (!form) return;
    
    const quantityInputs = form.querySelectorAll('input[type="number"][name^="quantity_"]');
    quantityInputs.forEach(input => {
        const productId = input.name.replace('quantity_', '');
        const quantity = parseInt(input.value) || 0;
        const unitPriceInput = form.querySelector('input[name="unit_price_' + productId + '"]');
        const unitPrice = parseFloat(unitPriceInput ? unitPriceInput.value : 0);
        const subtotal = quantity * unitPrice;
        
        const subtotalCell = form.querySelector('.subtotal-' + productId);
        if (subtotalCell) {
            subtotalCell.textContent = formatCurrency(subtotal);
        }
    });
}
</script>

<!-- Low Stock Items Modal -->
<div id="lowStockItemsModal" class="modal">
    <div class="modal-content" style="max-width: 1000px;">
        <div class="modal-header">
            <h3><i class="fas fa-exclamation-triangle" style="color: #dc3545;"></i> Low Stock Items - Automatic Order</h3>
            <button class="modal-close" onclick="closeModal('lowStockItemsModal')">&times;</button>
        </div>
        <div class="card-body" id="lowStockItemsContent">
            <p class="text-muted">Loading...</p>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

