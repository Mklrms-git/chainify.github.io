-- Migration: Add Route Planning Fields
-- Execute this in phpMyAdmin or MySQL command line

-- Add latitude/longitude to warehouses
ALTER TABLE warehouses 
ADD COLUMN latitude DECIMAL(10,8) NULL,
ADD COLUMN longitude DECIMAL(11,8) NULL;

-- Add route fields to shipments
ALTER TABLE shipments 
ADD COLUMN distance_km DECIMAL(10,2) NULL,
ADD COLUMN estimated_time_minutes INT NULL;

-- Sample coordinates for existing warehouses (Philippines - adjust for your location)
-- UPDATE warehouses SET latitude = 14.5995, longitude = 120.9842 WHERE name = 'Main Warehouse';
-- UPDATE warehouses SET latitude = 14.6760, longitude = 121.0437 WHERE name = 'North Distribution Center';
-- UPDATE warehouses SET latitude = 14.4056, longitude = 120.8783 WHERE name = 'South Distribution Center';

