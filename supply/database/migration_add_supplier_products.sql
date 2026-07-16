-- Migration: Add supplier_products junction table
-- This table links products to suppliers (many-to-many relationship)
-- A product can be supplied by multiple suppliers, and a supplier can supply multiple products

USE supply_chain_db;

-- Create supplier_products junction table
CREATE TABLE IF NOT EXISTS supplier_products (
    id INT PRIMARY KEY AUTO_INCREMENT,
    supplier_id INT NOT NULL,
    product_id INT NOT NULL,
    supplier_sku VARCHAR(50) NULL COMMENT 'Supplier-specific SKU for this product',
    supplier_price DECIMAL(10,2) NULL COMMENT 'Price from this specific supplier',
    lead_time_days INT DEFAULT 7 COMMENT 'Lead time in days for this product from this supplier',
    is_primary BOOLEAN DEFAULT FALSE COMMENT 'Whether this is the primary supplier for this product',
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY unique_supplier_product (supplier_id, product_id),
    INDEX idx_supplier_id (supplier_id),
    INDEX idx_product_id (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

