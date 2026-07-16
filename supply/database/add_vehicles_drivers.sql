-- Migration: Add Vehicles and Drivers Tables
-- Date: 2026-01-04
-- Description: Adds vehicle and driver management tables and updates shipments table

-- Vehicles Table
CREATE TABLE IF NOT EXISTS vehicles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    vehicle_number VARCHAR(50) UNIQUE NOT NULL,
    vehicle_type ENUM('truck', 'van', 'container', 'trailer', 'other') NOT NULL DEFAULT 'truck',
    make VARCHAR(100),
    model VARCHAR(100),
    year YEAR,
    license_plate VARCHAR(50) UNIQUE,
    capacity_kg DECIMAL(10,2),
    capacity_volume DECIMAL(10,2),
    fuel_type ENUM('diesel', 'gasoline', 'electric', 'hybrid', 'other') DEFAULT 'diesel',
    status ENUM('available', 'in_use', 'maintenance', 'inactive') DEFAULT 'available',
    current_location VARCHAR(200),
    last_maintenance_date DATE,
    next_maintenance_date DATE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Drivers Table
CREATE TABLE IF NOT EXISTS drivers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    driver_code VARCHAR(50) UNIQUE NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    license_number VARCHAR(50) UNIQUE,
    license_type ENUM('A', 'B', 'C', 'D', 'E') NOT NULL DEFAULT 'B',
    license_expiry DATE,
    phone VARCHAR(20),
    email VARCHAR(100),
    address TEXT,
    status ENUM('available', 'on_duty', 'off_duty', 'sick_leave', 'inactive') DEFAULT 'available',
    hire_date DATE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Add vehicle_id and driver_id to shipments table
ALTER TABLE shipments 
ADD COLUMN vehicle_id INT DEFAULT NULL AFTER supplier_id,
ADD COLUMN driver_id INT DEFAULT NULL AFTER vehicle_id,
ADD FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL,
ADD FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE SET NULL;

-- Create indexes for better performance
CREATE INDEX idx_vehicle_status ON vehicles(status);
CREATE INDEX idx_driver_status ON drivers(status);
CREATE INDEX idx_shipment_vehicle ON shipments(vehicle_id);
CREATE INDEX idx_shipment_driver ON shipments(driver_id);


