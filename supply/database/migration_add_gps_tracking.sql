-- Migration: Add GPS Tracking Fields
-- Execute this in phpMyAdmin or MySQL command line

-- Table to store GPS location updates for shipments
CREATE TABLE shipment_locations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    shipment_id INT NOT NULL,
    latitude DECIMAL(10,8) NOT NULL,
    longitude DECIMAL(11,8) NOT NULL,
    speed_kmh DECIMAL(5,2) NULL,
    heading_degrees INT NULL,
    accuracy_meters DECIMAL(6,2) NULL,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE,
    INDEX idx_shipment_recorded (shipment_id, recorded_at DESC)
);

-- Add current location fields to shipments table for quick access
ALTER TABLE shipments 
ADD COLUMN current_latitude DECIMAL(10,8) NULL,
ADD COLUMN current_longitude DECIMAL(11,8) NULL,
ADD COLUMN last_location_update TIMESTAMP NULL,
ADD COLUMN estimated_arrival TIMESTAMP NULL;

-- Index for faster queries
CREATE INDEX idx_shipments_status_location ON shipments(status, last_location_update);

