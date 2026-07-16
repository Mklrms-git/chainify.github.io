-- ============================================
-- CREATE SALES HISTORY TABLE AND SAMPLE DATA
-- ============================================
-- This script creates the sales_history table if it doesn't exist
-- and inserts up to 10 sample records
-- Connected to: demand_forecasting.php and modules/sales.php
-- ============================================

USE supply_chain_db;

-- ============================================
-- PART 1: Create sales_history table if not exists
-- ============================================

CREATE TABLE IF NOT EXISTS sales_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    product_id INT NOT NULL,
    sale_date DATE NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_sale_date (sale_date),
    INDEX idx_product_date (product_id, sale_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================
-- PART 2: Clear existing sample data (optional)
-- Uncomment the line below if you want to start fresh
-- ============================================
-- DELETE FROM sales_history WHERE id > 0;

-- ============================================
-- PART 3: Get product IDs dynamically
-- Uses the first 5 products from the products table
-- ============================================

SET @product1 = (SELECT id FROM products ORDER BY id LIMIT 1);
SET @product2 = (SELECT id FROM products ORDER BY id LIMIT 1 OFFSET 1);
SET @product3 = (SELECT id FROM products ORDER BY id LIMIT 1 OFFSET 2);
SET @product4 = (SELECT id FROM products ORDER BY id LIMIT 1 OFFSET 3);
SET @product5 = (SELECT id FROM products ORDER BY id LIMIT 1 OFFSET 4);

-- Get product prices
SET @price1 = (SELECT unit_price FROM products WHERE id = @product1);
SET @price2 = (SELECT unit_price FROM products WHERE id = @product2);
SET @price3 = (SELECT unit_price FROM products WHERE id = @product3);
SET @price4 = (SELECT unit_price FROM products WHERE id = @product4);
SET @price5 = (SELECT unit_price FROM products WHERE id = @product5);

-- ============================================
-- PART 4: Insert 10 sample sales history records
-- ============================================

-- Insert 10 sample records
INSERT INTO sales_history (product_id, sale_date, quantity, unit_price) VALUES
(@product1, '2024-01-15', 45, @price1),
(@product1, '2024-02-10', 52, @price1),
(@product2, '2024-01-20', 30, @price2),
(@product2, '2024-02-18', 35, @price2),
(@product3, '2024-01-25', 15, @price3),
(@product3, '2024-02-22', 18, @price3),
(@product4, '2024-02-05', 120, @price4),
(@product4, '2024-02-28', 135, @price4),
(@product5, '2024-02-12', 8, @price5),
(@product5, '2024-03-01', 10, @price5);

-- ============================================
-- PART 5: Display summary
-- ============================================

SELECT 
    COUNT(*) as total_records,
    MIN(sale_date) as earliest_sale,
    MAX(sale_date) as latest_sale,
    SUM(quantity) as total_quantity_sold,
    SUM(quantity * unit_price) as total_revenue
FROM sales_history;

-- ============================================
-- VERIFICATION: Show inserted records
-- ============================================

SELECT 
    sh.id,
    p.name as product_name,
    p.sku,
    sh.sale_date,
    sh.quantity,
    sh.unit_price,
    (sh.quantity * sh.unit_price) as total_amount,
    sh.created_at
FROM sales_history sh
JOIN products p ON sh.product_id = p.id
ORDER BY sh.sale_date DESC, sh.id DESC
LIMIT 10;

-- ============================================
-- CONNECTION VERIFICATION
-- ============================================
-- This table is connected to:
-- 1. demand_forecasting.php - Uses sales_history for forecasting analysis
-- 2. modules/sales.php - Saves sales data to this table
-- 3. products table - Foreign key relationship via product_id
-- ============================================
