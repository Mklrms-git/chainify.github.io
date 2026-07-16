-- Sample Users for Supply Chain Management System
-- Run this file after importing schema.sql to add additional users

USE supply_chain_db;

-- Insert Procurement Staff User
-- Username: procurement_staff
-- Password: procurement123
INSERT INTO users (username, email, password, role, full_name, status) VALUES
('procurement_staff', 'procurement@supplychain.com', '$2y$10$hLUKn9FW6nT42DW/c60jxuRReFuTG7Wy2Jxh5zSArj8vEl6ow3kra', 'procurement_staff', 'Procurement Staff', 'active');

-- Insert Warehouse Officer User
-- Username: warehouse_officer
-- Password: warehouse123
INSERT INTO users (username, email, password, role, full_name, status) VALUES
('warehouse_officer', 'warehouse@supplychain.com', '$2y$10$QxK1nH4armJrh45fjwdJRe7zRpVDxcmlwnPCQ9KURxYV3I5t5Crru', 'warehouse_officer', 'Warehouse Officer', 'active');

-- Insert Logistics Manager User
-- Username: logistics_manager
-- Password: logistics123
INSERT INTO users (username, email, password, role, full_name, status) VALUES
('logistics_manager', 'logistics@supplychain.com', '$2y$10$d6IHZgaFiBa4vAdzmfqrOeFpNZ5QNp8dylCF6qv6Q4UIrP2zsiFDW', 'logistics_manager', 'Logistics Manager', 'active');

-- Insert Supplier User
-- Username: supplier1
-- Password: supplier123
INSERT INTO users (username, email, password, role, full_name, status) VALUES
('supplier1', 'supplier1@supplychain.com', '$2y$10$wFnFAhT4by7j.5./MuTCt.CKQvJZIhqvP/SixwVQLQNWOVB9CSqAO', 'supplier', 'Supplier User', 'active');

