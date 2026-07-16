-- Sample Supplier User for Supply Chain Management System
-- Run this file after running migration_add_supplier_role.sql
-- This creates a sample supplier user and links it to an existing supplier

USE supply_chain_db;

-- Insert Supplier User
-- Username: supplier1
-- Password: supplier123
-- Note: Password hash is for 'supplier123'
INSERT INTO users (username, email, password, role, full_name, status) VALUES
('supplier1', 'supplier1@supplychain.com', '$2y$10$wFnFAhT4by7j.5./MuTCt.CKQvJZIhqvP/SixwVQLQNWOVB9CSqAO', 'supplier', 'Supplier User', 'active')
ON DUPLOAD KEY UPDATE username = username;

-- Link the supplier user to an existing supplier
-- Replace supplier_id with an actual supplier ID from your suppliers table
-- Example: If you have a supplier with id=1, uncomment and run:
-- UPDATE suppliers SET user_id = (SELECT id FROM users WHERE username = 'supplier1') WHERE id = 1;

-- To link to a specific supplier, run this query (replace 1 with your supplier ID):
-- UPDATE suppliers SET user_id = (SELECT id FROM users WHERE username = 'supplier1' LIMIT 1) WHERE id = 1;

