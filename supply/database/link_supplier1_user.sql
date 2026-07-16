-- Quick fix script to link supplier1 user account to a supplier record
-- Run this script if supplier1 user is not linked to any supplier

-- First, check if supplier1 user exists
SELECT id, username, role FROM users WHERE username = 'supplier1';

-- Link supplier1 to the first available supplier (or a specific supplier)
-- Option 1: Link to first supplier without a user account
UPDATE suppliers 
SET user_id = (SELECT id FROM users WHERE username = 'supplier1' LIMIT 1)
WHERE id = (SELECT id FROM suppliers WHERE user_id IS NULL AND status = 'active' LIMIT 1);

-- Option 2: Link to a specific supplier (replace SUPPLIER_ID with actual supplier ID)
-- UPDATE suppliers 
-- SET user_id = (SELECT id FROM users WHERE username = 'supplier1' LIMIT 1)
-- WHERE id = SUPPLIER_ID;

-- Verify the link
SELECT u.id as user_id, u.username, s.id as supplier_id, s.company_name
FROM users u
JOIN suppliers s ON u.id = s.user_id
WHERE u.username = 'supplier1';

