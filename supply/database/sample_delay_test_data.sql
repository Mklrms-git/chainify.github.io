-- Sample Data for Testing Delay Detection Feature
-- This file creates two purchase orders with past delivery dates to test delay detection

USE supply_chain_db;

-- Get the first available supplier and user IDs (you may need to adjust these)
SET @supplier_id = (SELECT id FROM suppliers WHERE status = 'active' LIMIT 1);
SET @created_by = (SELECT id FROM users WHERE role IN ('admin', 'procurement_staff') LIMIT 1);
SET @product_id = (SELECT id FROM products LIMIT 1);

-- If no data exists, use default values (you'll need to adjust these)
-- SET @supplier_id = 1;
-- SET @created_by = 1;
-- SET @product_id = 1;

-- Insert Sample Purchase Order 1: Delayed by 5 days (status: pending)
-- This will show as DELAYED and supplier can request a delay
INSERT INTO purchase_orders (
    po_number, 
    supplier_id, 
    created_by, 
    order_date, 
    delivery_date, 
    status, 
    total_amount, 
    notes,
    delay_status
) VALUES (
    CONCAT('PO-TEST-DELAY-', DATE_FORMAT(NOW(), '%Y%m%d'), '-001'),
    @supplier_id,
    @created_by,
    DATE_SUB(CURDATE(), INTERVAL 10 DAY),  -- Ordered 10 days ago
    DATE_SUB(CURDATE(), INTERVAL 5 DAY),   -- Delivery date was 5 days ago (DELAYED)
    'pending',  -- Status is pending (not scheduled/received/cancelled)
    15000.00,
    'Test purchase order for delay detection - Sample 1',
    'none'  -- No delay request yet
);

-- Get the PO ID for the first test PO
SET @po_id_1 = LAST_INSERT_ID();

-- Insert items for PO 1
INSERT INTO purchase_order_items (po_id, product_id, quantity, unit_price, subtotal)
SELECT @po_id_1, @product_id, 100, 150.00, 15000.00;

-- Insert Sample Purchase Order 2: Delayed by 10 days (status: approved)
-- This will also show as DELAYED and supplier can request a delay
INSERT INTO purchase_orders (
    po_number, 
    supplier_id, 
    created_by, 
    order_date, 
    delivery_date, 
    status, 
    total_amount, 
    notes,
    delay_status,
    admin_approved,
    supplier_approved
) VALUES (
    CONCAT('PO-TEST-DELAY-', DATE_FORMAT(NOW(), '%Y%m%d'), '-002'),
    @supplier_id,
    @created_by,
    DATE_SUB(CURDATE(), INTERVAL 15 DAY),  -- Ordered 15 days ago
    DATE_SUB(CURDATE(), INTERVAL 10 DAY),  -- Delivery date was 10 days ago (DELAYED)
    'approved',  -- Status is approved (not scheduled/received/cancelled)
    25000.00,
    'Test purchase order for delay detection - Sample 2',
    'none',  -- No delay request yet
    1,  -- Admin approved
    0   -- Supplier not approved yet
);

-- Get the PO ID for the second test PO
SET @po_id_2 = LAST_INSERT_ID();

-- Insert items for PO 2
INSERT INTO purchase_order_items (po_id, product_id, quantity, unit_price, subtotal)
SELECT @po_id_2, @product_id, 200, 125.00, 25000.00;

-- Display inserted data
SELECT 
    po_number,
    delivery_date,
    DATEDIFF(CURDATE(), delivery_date) AS days_delayed,
    status,
    delay_status
FROM purchase_orders
WHERE po_number LIKE 'PO-TEST-DELAY-%'
ORDER BY id DESC
LIMIT 2;
