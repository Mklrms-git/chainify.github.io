-- Migration: Add Delay Remarks System for Purchase Orders
-- This migration adds delay tracking and remarks functionality to purchase_orders table
-- Allows suppliers to request delays with new dates and procurement to approve/reject

USE supply_chain_db;

-- Add delay tracking fields to purchase_orders table
ALTER TABLE purchase_orders
ADD COLUMN delay_status ENUM('none', 'requested', 'approved', 'rejected') DEFAULT 'none' COMMENT 'Status of delay request: none, requested by supplier, approved, or rejected by procurement',
ADD COLUMN delay_requested_date DATE NULL COMMENT 'New delivery date requested by supplier',
ADD COLUMN delay_notes TEXT NULL COMMENT 'Notes from supplier explaining the delay',
ADD COLUMN delay_response_notes TEXT NULL COMMENT 'Notes from procurement staff when responding to delay request',
ADD COLUMN delay_requested_at TIMESTAMP NULL COMMENT 'When supplier requested the delay',
ADD COLUMN delay_responded_at TIMESTAMP NULL COMMENT 'When procurement responded to delay request',
ADD COLUMN delay_responded_by INT NULL COMMENT 'User ID of procurement staff who responded',
ADD INDEX idx_delay_status (delay_status),
ADD FOREIGN KEY (delay_responded_by) REFERENCES users(id) ON DELETE SET NULL;
