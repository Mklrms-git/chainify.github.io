-- Migration: Add 'scheduled' status to purchase_orders table
-- This allows PO status to be 'scheduled' when supplier schedules a shipment

USE supply_chain_db;

-- Add 'scheduled' status to purchase_orders status enum (removed supplier_approved)
ALTER TABLE purchase_orders 
MODIFY COLUMN status ENUM('pending', 'approved', 'scheduled', 'in_transit', 'received', 'cancelled') DEFAULT 'pending';

