-- Sample Data: 5 Drivers and 3 Vehicles
-- Date: 2026-01-04
-- Description: Sample INSERT statements for testing vehicles and drivers tables

-- Insert 3 Vehicles
INSERT INTO vehicles (vehicle_number, vehicle_type, make, model, year, license_plate, capacity_kg, capacity_volume, fuel_type, status, current_location, last_maintenance_date, next_maintenance_date, notes) VALUES
('VH-001', 'truck', 'Isuzu', 'NPR 75', 2022, 'ABC-1234', 7500.00, 25.50, 'diesel', 'available', 'Main Warehouse - Loading Bay 1', '2025-11-15', '2026-02-15', 'Regular maintenance schedule. Good condition.'),
('VH-002', 'van', 'Mercedes-Benz', 'Sprinter 3500', 2023, 'XYZ-5678', 3500.00, 12.00, 'diesel', 'available', 'Main Warehouse - Loading Bay 2', '2025-12-01', '2026-03-01', 'New vehicle. Excellent for urban deliveries.'),
('VH-003', 'truck', 'Hino', '300 Series', 2021, 'DEF-9012', 10000.00, 35.00, 'diesel', 'in_use', 'In Transit - Route to Warehouse B', '2025-10-20', '2026-01-20', 'Heavy-duty truck for long-distance transport. Currently on delivery route.');

-- Insert 5 Drivers
INSERT INTO drivers (driver_code, full_name, license_number, license_type, license_expiry, phone, email, address, status, hire_date, notes) VALUES
('DRV-001', 'John Michael Santos', 'DL-2020-001234', 'B', '2027-05-15', '+63-912-345-6789', 'john.santos@company.com', '123 Main Street, Quezon City, Metro Manila', 'available', '2020-03-15', 'Experienced driver with 5+ years. Specializes in long-distance routes.'),
('DRV-002', 'Maria Cristina Reyes', 'DL-2019-005678', 'B', '2026-08-20', '+63-917-890-1234', 'maria.reyes@company.com', '456 Rizal Avenue, Makati City, Metro Manila', 'on_duty', '2019-06-01', 'Excellent safety record. Certified for hazardous materials transport.'),
('DRV-003', 'Roberto Dela Cruz', 'DL-2021-009012', 'C', '2028-11-30', '+63-918-234-5678', 'roberto.delacruz@company.com', '789 EDSA, Mandaluyong City, Metro Manila', 'available', '2021-01-10', 'New driver. Currently in training program. Good communication skills.'),
('DRV-004', 'Jennifer Ann Garcia', 'DL-2018-003456', 'B', '2026-03-10', '+63-919-456-7890', 'jennifer.garcia@company.com', '321 Taft Avenue, Manila City', 'off_duty', '2018-09-20', 'Senior driver with 8+ years experience. Team leader for delivery operations.'),
('DRV-005', 'Carlos Manuel Torres', 'DL-2022-007890', 'B', '2027-09-25', '+63-920-678-9012', 'carlos.torres@company.com', '654 Commonwealth Avenue, Quezon City, Metro Manila', 'available', '2022-05-05', 'Reliable driver. Good with GPS navigation and route optimization.');

