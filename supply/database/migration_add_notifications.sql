-- Migration: Add General Notifications Table
-- This migration adds a general notifications table for user notifications

USE supply_chain_db;

-- Create general notifications table for all users
CREATE TABLE IF NOT EXISTS notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL COMMENT 'User who should receive the notification',
    role VARCHAR(50) NOT NULL COMMENT 'Role of the user receiving the notification',
    notification_type VARCHAR(50) NOT NULL COMMENT 'Type: shipment_scheduled, po_created, etc.',
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    reference_type VARCHAR(50) NULL COMMENT 'Type of reference: shipment, purchase_order, etc.',
    reference_id INT NULL COMMENT 'ID of the referenced record',
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_read (user_id, is_read),
    INDEX idx_role_read (role, is_read),
    INDEX idx_created_at (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

