-- Fix supplier_ratings table structure
-- Run this if you get "Unknown column 'rating' in 'field list'" error

USE supply_chain_db;

-- First, check if table exists and what columns it has
-- Run this to see the current structure:
-- DESCRIBE supplier_ratings;

-- Option 1: If table exists but is missing the rating column, add it
-- Uncomment the following if you need to add the rating column:
/*
ALTER TABLE supplier_ratings 
ADD COLUMN rating DECIMAL(2,1) NOT NULL COMMENT 'Rating 1-5 stars' AFTER rated_by;
*/

-- Option 2: If the table structure is completely wrong, drop and recreate
-- WARNING: This will delete all existing ratings!
-- Uncomment only if you're sure you want to start fresh:
/*
DROP TABLE IF EXISTS supplier_ratings;

CREATE TABLE supplier_ratings (
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
*/

-- Option 3: Check what columns exist (run this first to diagnose)
DESCRIBE supplier_ratings;
