-- Migration: Add Supplier Ratings System
-- This migration adds supplier ratings functionality for purchase orders
-- Allows procurement_staff to rate suppliers after delivery (received status)
-- Rating is a single value from 1-5 stars

USE supply_chain_db;

-- Create supplier_ratings table
CREATE TABLE IF NOT EXISTS supplier_ratings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    po_id INT NOT NULL,
    supplier_id INT NOT NULL,
    rated_by INT NOT NULL COMMENT 'User ID of procurement_staff who rated',
    rating DECIMAL(2,1) NOT NULL COMMENT 'Rating 1-5 stars',
    comments TEXT NULL COMMENT 'Additional comments about the delivery',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
    FOREIGN KEY (rated_by) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_po_rating (po_id),
    INDEX idx_supplier_id (supplier_id),
    INDEX idx_po_id (po_id),
    INDEX idx_rated_by (rated_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
