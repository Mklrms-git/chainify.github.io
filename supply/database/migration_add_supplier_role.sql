-- Migration: Add Supplier Role and Link Suppliers to Users
-- This migration adds supplier role support and links suppliers to user accounts

USE supply_chain_db;

-- Add supplier role to users table
ALTER TABLE users 
MODIFY COLUMN role ENUM('admin', 'procurement_staff', 'warehouse_officer', 'logistics_manager', 'supplier') NOT NULL;

-- Add user_id column to suppliers table to link supplier accounts
ALTER TABLE suppliers 
ADD COLUMN user_id INT NULL AFTER id,
ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
ADD INDEX idx_user_id (user_id);

-- Add supplier_approved status to purchase_orders
ALTER TABLE purchase_orders 
MODIFY COLUMN status ENUM('pending', 'approved', 'supplier_approved', 'in_transit', 'received', 'cancelled') DEFAULT 'pending';

-- Add notification tracking table for PO notifications
CREATE TABLE IF NOT EXISTS po_notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    po_id INT NOT NULL,
    supplier_id INT NOT NULL,
    notification_type ENUM('po_approved', 'po_cancelled', 'shipment_scheduled') NOT NULL,
    message TEXT,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
    INDEX idx_supplier_read (supplier_id, is_read),
    INDEX idx_po_id (po_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

