-- Migration: Improve Sales History Table
-- This migration enhances the sales_history table with better structure,
-- indexes, and additional fields for comprehensive sales tracking and analysis

-- ============================================
-- PART 1: Add Missing Indexes for Performance
-- ============================================

-- Index on sale_date for time-based queries (used heavily in demand forecasting)
ALTER TABLE sales_history 
ADD INDEX idx_sale_date (sale_date DESC);

-- Composite index for product and date queries (common pattern in forecasting)
ALTER TABLE sales_history 
ADD INDEX idx_product_date (product_id, sale_date DESC);

-- Index on created_at for audit trails
ALTER TABLE sales_history 
ADD INDEX idx_created_at (created_at DESC);

-- ============================================
-- PART 2: Add Sales Transaction Support
-- ============================================

-- Add sales_order_number to group related sales items into transactions
ALTER TABLE sales_history 
ADD COLUMN sales_order_number VARCHAR(50) NULL 
COMMENT 'Groups related sales items into a single transaction/order'
AFTER id;

-- Add index for sales order lookups
ALTER TABLE sales_history 
ADD INDEX idx_sales_order (sales_order_number);

-- ============================================
-- PART 3: Add Warehouse/Location Tracking
-- ============================================

-- Add warehouse_id to track which warehouse/store the sale came from
ALTER TABLE sales_history 
ADD COLUMN warehouse_id INT NULL 
COMMENT 'Warehouse/store location where sale occurred'
AFTER product_id;

-- Add foreign key constraint
ALTER TABLE sales_history 
ADD CONSTRAINT fk_sales_history_warehouse 
FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE SET NULL;

-- Add index for warehouse-based queries
ALTER TABLE sales_history 
ADD INDEX idx_warehouse_date (warehouse_id, sale_date DESC);

-- ============================================
-- PART 4: Add Customer Information
-- ============================================

-- Create customers table if it doesn't exist (optional, for future use)
CREATE TABLE IF NOT EXISTS customers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_code VARCHAR(50) UNIQUE NOT NULL,
    customer_name VARCHAR(100) NOT NULL,
    customer_type ENUM('retail', 'wholesale', 'corporate', 'individual') DEFAULT 'retail',
    email VARCHAR(100),
    phone VARCHAR(20),
    address TEXT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customer_code (customer_code),
    INDEX idx_customer_type (customer_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Add customer_id to sales_history
ALTER TABLE sales_history 
ADD COLUMN customer_id INT NULL 
COMMENT 'Customer who made the purchase'
AFTER warehouse_id;

-- Add foreign key constraint
ALTER TABLE sales_history 
ADD CONSTRAINT fk_sales_history_customer 
FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL;

-- Add index for customer-based queries
ALTER TABLE sales_history 
ADD INDEX idx_customer_date (customer_id, sale_date DESC);

-- ============================================
-- PART 5: Add Sales Representative Tracking
-- ============================================

-- Add sales_rep_id to track who made the sale
ALTER TABLE sales_history 
ADD COLUMN sales_rep_id INT NULL 
COMMENT 'User/sales representative who processed the sale'
AFTER customer_id;

-- Add foreign key constraint
ALTER TABLE sales_history 
ADD CONSTRAINT fk_sales_history_sales_rep 
FOREIGN KEY (sales_rep_id) REFERENCES users(id) ON DELETE SET NULL;

-- Add index for sales rep performance queries
ALTER TABLE sales_history 
ADD INDEX idx_sales_rep_date (sales_rep_id, sale_date DESC);

-- ============================================
-- PART 6: Add Financial Fields
-- ============================================

-- Add subtotal (quantity * unit_price) for faster calculations
ALTER TABLE sales_history 
ADD COLUMN subtotal DECIMAL(12,2) GENERATED ALWAYS AS (quantity * unit_price) STORED 
COMMENT 'Calculated subtotal (quantity * unit_price)'
AFTER unit_price;

-- Add discount amount
ALTER TABLE sales_history 
ADD COLUMN discount_amount DECIMAL(10,2) DEFAULT 0.00 
COMMENT 'Discount amount applied to this line item'
AFTER subtotal;

-- Add discount_percentage
ALTER TABLE sales_history 
ADD COLUMN discount_percentage DECIMAL(5,2) DEFAULT 0.00 
COMMENT 'Discount percentage applied'
AFTER discount_amount;

-- Add tax_amount
ALTER TABLE sales_history 
ADD COLUMN tax_amount DECIMAL(10,2) DEFAULT 0.00 
COMMENT 'Tax amount for this line item'
AFTER discount_percentage;

-- Add tax_rate
ALTER TABLE sales_history 
ADD COLUMN tax_rate DECIMAL(5,2) DEFAULT 0.00 
COMMENT 'Tax rate percentage'
AFTER tax_amount;

-- Add total_amount (subtotal - discount + tax)
ALTER TABLE sales_history 
ADD COLUMN total_amount DECIMAL(12,2) GENERATED ALWAYS AS (
    (quantity * unit_price) - COALESCE(discount_amount, 0) + COALESCE(tax_amount, 0)
) STORED 
COMMENT 'Final amount after discount and tax'
AFTER tax_rate;

-- ============================================
-- PART 7: Add Sales Channel Tracking
-- ============================================

-- Add sales_channel to track where sale came from
ALTER TABLE sales_history 
ADD COLUMN sales_channel ENUM('in_store', 'online', 'phone', 'wholesale', 'other') DEFAULT 'in_store' 
COMMENT 'Channel through which sale was made'
AFTER sales_rep_id;

-- Add index for channel analysis
ALTER TABLE sales_history 
ADD INDEX idx_sales_channel_date (sales_channel, sale_date DESC);

-- ============================================
-- PART 8: Add Return/Refund Tracking
-- ============================================

-- Add return_reference_id to link returns to original sale
ALTER TABLE sales_history 
ADD COLUMN return_reference_id INT NULL 
COMMENT 'Reference to original sale if this is a return/refund'
AFTER sales_channel;

-- Add foreign key to self-reference for returns
ALTER TABLE sales_history 
ADD CONSTRAINT fk_sales_history_return 
FOREIGN KEY (return_reference_id) REFERENCES sales_history(id) ON DELETE SET NULL;

-- Add is_return flag
ALTER TABLE sales_history 
ADD COLUMN is_return BOOLEAN DEFAULT FALSE 
COMMENT 'Whether this record is a return/refund'
AFTER return_reference_id;

-- Add return_reason
ALTER TABLE sales_history 
ADD COLUMN return_reason TEXT NULL 
COMMENT 'Reason for return/refund'
AFTER is_return;

-- Add index for return tracking
ALTER TABLE sales_history 
ADD INDEX idx_return_reference (return_reference_id);

-- ============================================
-- PART 9: Add Metadata and Audit Fields
-- ============================================

-- Add notes field for additional information
ALTER TABLE sales_history 
ADD COLUMN notes TEXT NULL 
COMMENT 'Additional notes or comments about this sale'
AFTER return_reason;

-- Add updated_at for tracking modifications
ALTER TABLE sales_history 
ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP 
COMMENT 'Timestamp of last update'
AFTER created_at;

-- Add updated_by for audit trail
ALTER TABLE sales_history 
ADD COLUMN updated_by INT NULL 
COMMENT 'User who last updated this record'
AFTER updated_at;

-- Add foreign key for updated_by
ALTER TABLE sales_history 
ADD CONSTRAINT fk_sales_history_updated_by 
FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL;

-- ============================================
-- PART 10: Add Promotion/Campaign Tracking
-- ============================================

-- Add promotion_id for linking to promotions/campaigns
ALTER TABLE sales_history 
ADD COLUMN promotion_code VARCHAR(50) NULL 
COMMENT 'Promotion or campaign code applied'
AFTER discount_percentage;

-- Add index for promotion analysis
ALTER TABLE sales_history 
ADD INDEX idx_promotion_code (promotion_code);

-- ============================================
-- PART 11: Add Payment Method Tracking
-- ============================================

-- Add payment_method
ALTER TABLE sales_history 
ADD COLUMN payment_method ENUM('cash', 'credit_card', 'debit_card', 'bank_transfer', 'check', 'other') NULL 
COMMENT 'Payment method used'
AFTER sales_channel;

-- Add index for payment method analysis
ALTER TABLE sales_history 
ADD INDEX idx_payment_method (payment_method);

-- ============================================
-- PART 12: Update Existing Data (if needed)
-- ============================================

-- Generate sales_order_number for existing records (group by date and product)
-- This creates a unique order number for each sale_date
UPDATE sales_history 
SET sales_order_number = CONCAT('SO-', DATE_FORMAT(sale_date, '%Y%m%d'), '-', LPAD(id, 6, '0'))
WHERE sales_order_number IS NULL;

-- ============================================
-- PART 13: Create View for Sales Summary
-- ============================================

-- Create a view for easier sales reporting
CREATE OR REPLACE VIEW sales_summary AS
SELECT 
    DATE_FORMAT(sh.sale_date, '%Y-%m') as period,
    sh.product_id,
    p.name as product_name,
    p.sku,
    sh.warehouse_id,
    w.name as warehouse_name,
    sh.sales_channel,
    sh.payment_method,
    COUNT(*) as transaction_count,
    SUM(sh.quantity) as total_quantity,
    SUM(sh.subtotal) as total_subtotal,
    SUM(sh.discount_amount) as total_discount,
    SUM(sh.tax_amount) as total_tax,
    SUM(sh.total_amount) as total_amount,
    AVG(sh.unit_price) as avg_unit_price,
    MIN(sh.unit_price) as min_unit_price,
    MAX(sh.unit_price) as max_unit_price
FROM sales_history sh
LEFT JOIN products p ON sh.product_id = p.id
LEFT JOIN warehouses w ON sh.warehouse_id = w.id
WHERE sh.is_return = FALSE
GROUP BY 
    DATE_FORMAT(sh.sale_date, '%Y-%m'),
    sh.product_id,
    sh.warehouse_id,
    sh.sales_channel,
    sh.payment_method;

-- ============================================
-- PART 14: Create Indexes for Common Query Patterns
-- ============================================

-- Composite index for product, date, and warehouse queries
ALTER TABLE sales_history 
ADD INDEX idx_product_warehouse_date (product_id, warehouse_id, sale_date DESC);

-- Composite index for date range queries with channel
ALTER TABLE sales_history 
ADD INDEX idx_date_channel (sale_date DESC, sales_channel);

-- ============================================
-- SUMMARY OF IMPROVEMENTS
-- ============================================
-- 1. ✅ Performance: Added 10+ indexes for faster queries
-- 2. ✅ Transaction Grouping: Added sales_order_number
-- 3. ✅ Location Tracking: Added warehouse_id
-- 4. ✅ Customer Tracking: Added customer_id and customers table
-- 5. ✅ Sales Rep Tracking: Added sales_rep_id
-- 6. ✅ Financial Fields: Added subtotal, discount, tax, total_amount
-- 7. ✅ Sales Channel: Added sales_channel enum
-- 8. ✅ Return Tracking: Added return_reference_id, is_return, return_reason
-- 9. ✅ Audit Trail: Added updated_at, updated_by
-- 10. ✅ Promotion Tracking: Added promotion_code
-- 11. ✅ Payment Method: Added payment_method
-- 12. ✅ Reporting View: Created sales_summary view
-- 13. ✅ Calculated Fields: Added generated columns for subtotal and total_amount
