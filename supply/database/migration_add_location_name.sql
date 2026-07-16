-- Migration: Add location_name field to shipment_locations table
-- Execute this in phpMyAdmin or MySQL command line

-- Add location_name field to shipment_locations table
ALTER TABLE shipment_locations 
ADD COLUMN location_name VARCHAR(255) NULL AFTER longitude;

-- Update existing records to have a default location name if needed
-- UPDATE shipment_locations SET location_name = CONCAT('Location ', latitude, ', ', longitude) WHERE location_name IS NULL;
