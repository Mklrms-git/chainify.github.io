-- Sample Data: Korean Goods and Related Data
-- Run this file after importing schema.sql to add Korean products and sample data

USE supply_chain_db;

-- Insert Korean Suppliers
INSERT INTO suppliers (company_name, contact_person, email, phone, address, products_supplied, performance_rating) VALUES
('Seoul Electronics Co.', 'Kim Min-jun', 'minjun@seoulelectronics.co.kr', '+639123456789', '123 Gangnam-daero, Gangnam-gu, Seoul, South Korea', 'Korean Electronics, Smartphones, Tablets', 4.75),
('Busan Trading Ltd.', 'Park So-young', 'soyoung@busantrading.co.kr', '+639234567890', '456 Haeundae Beach Road, Busan, South Korea', 'Korean Cosmetics, Skincare Products', 4.60),
('Incheon Food Export', 'Lee Jae-hoon', 'jaehoon@incheonfood.co.kr', '+639345678901', '789 Songdo-dong, Incheon, South Korea', 'Korean Food Products, Kimchi, Instant Noodles', 4.85),
('Gyeonggi Textiles Inc.', 'Choi Hye-jin', 'hyejin@gyeonggitextiles.co.kr', '+639456789012', '321 Suwon-si, Gyeonggi-do, South Korea', 'Korean Textiles, Clothing, Fabrics', 4.40),
('Daegu Tech Solutions', 'Jung Woo-sung', 'woosung@daegutech.co.kr', '+639567890123', '654 Dalseo-gu, Daegu, South Korea', 'Korean Tech Components, Semiconductors', 4.70);

-- Insert Korean Products
INSERT INTO products (sku, name, description, category, unit_price, min_stock_level, unit) VALUES
-- Korean Electronics
('KR-ELEC-001', 'Samsung Galaxy Smartphone', 'Latest Samsung Galaxy smartphone with advanced features', 'Electronics', 899.99, 20, 'pcs'),
('KR-ELEC-002', 'LG OLED TV 55"', 'Premium LG OLED television 55 inches', 'Electronics', 1299.99, 15, 'pcs'),
('KR-ELEC-003', 'Samsung Tablet', 'Samsung Galaxy Tab tablet computer', 'Electronics', 499.99, 25, 'pcs'),
('KR-ELEC-004', 'LG Refrigerator', 'Energy-efficient LG double door refrigerator', 'Electronics', 1599.99, 10, 'pcs'),
('KR-ELEC-005', 'Samsung Washing Machine', 'Samsung front-loading washing machine', 'Electronics', 799.99, 12, 'pcs'),

-- Korean Cosmetics & Skincare
('KR-COSM-001', 'Sulwhasoo Essential Set', 'Premium Korean skincare set with ginseng', 'Cosmetics', 89.99, 30, 'sets'),
('KR-COSM-002', 'Laneige Water Bank', 'Korean hydrating skincare water bank cream', 'Cosmetics', 34.99, 50, 'pcs'),
('KR-COSM-003', 'Innisfree Green Tea Serum', 'Natural Korean green tea serum', 'Cosmetics', 24.99, 60, 'pcs'),
('KR-COSM-004', 'Etude House Lip Tint', 'Korean lip tint in various colors', 'Cosmetics', 8.99, 100, 'pcs'),
('KR-COSM-005', 'COSRX Snail Mucin', 'Korean snail mucin essence', 'Cosmetics', 19.99, 40, 'pcs'),
('KR-COSM-006', 'Missha BB Cream', 'Korean BB cream with SPF', 'Cosmetics', 12.99, 80, 'pcs'),

-- Korean Food Products
('KR-FOOD-001', 'Kimchi Premium', 'Traditional Korean fermented kimchi 500g', 'Food Products', 12.99, 100, 'jars'),
('KR-FOOD-002', 'Shin Ramyun Noodles', 'Spicy Korean instant noodles 5-pack', 'Food Products', 6.99, 200, 'packs'),
('KR-FOOD-003', 'Gochujang Paste', 'Korean red pepper paste 500g', 'Food Products', 8.99, 80, 'jars'),
('KR-FOOD-004', 'Doenjang Paste', 'Korean soybean paste 500g', 'Food Products', 7.99, 80, 'jars'),
('KR-FOOD-005', 'Korean Seaweed Snacks', 'Roasted seaweed snacks 10-pack', 'Food Products', 9.99, 150, 'packs'),
('KR-FOOD-006', 'Bulgogi Sauce', 'Korean BBQ bulgogi marinade sauce', 'Food Products', 5.99, 120, 'bottles'),
('KR-FOOD-007', 'Korean Rice Cakes', 'Tteokbokki rice cakes 1kg', 'Food Products', 11.99, 60, 'packs'),
('KR-FOOD-008', 'Korean Honey Citron Tea', 'Yuja tea honey citron 1kg', 'Food Products', 15.99, 50, 'jars'),

-- Korean Textiles & Clothing
('KR-TEXT-001', 'Korean Hanbok Set', 'Traditional Korean hanbok clothing set', 'Textiles', 299.99, 10, 'sets'),
('KR-TEXT-002', 'Korean Silk Scarf', 'Premium Korean silk scarf', 'Textiles', 45.99, 30, 'pcs'),
('KR-TEXT-003', 'Korean Cotton Fabric', 'High-quality Korean cotton fabric 1m', 'Textiles', 12.99, 100, 'meters'),
('KR-TEXT-004', 'Korean Linen Material', 'Korean linen fabric material 1m', 'Textiles', 18.99, 80, 'meters'),

-- Korean Tech Components
('KR-TECH-001', 'Samsung Memory Chip', 'Samsung DDR4 memory chip 8GB', 'Tech Components', 49.99, 50, 'pcs'),
('KR-TECH-002', 'LG Display Panel', 'LG LCD display panel 32 inch', 'Tech Components', 199.99, 20, 'pcs'),
('KR-TECH-003', 'Samsung SSD Drive', 'Samsung SSD 1TB internal drive', 'Tech Components', 89.99, 40, 'pcs'),
('KR-TECH-004', 'SK Hynix RAM Module', 'SK Hynix RAM module 16GB', 'Tech Components', 79.99, 35, 'pcs');

-- Add Korean products to inventory (distribute across warehouses)
-- Main Warehouse
INSERT INTO inventory (product_id, warehouse_id, quantity) VALUES
((SELECT id FROM products WHERE sku = 'KR-ELEC-001'), 1, 25),
((SELECT id FROM products WHERE sku = 'KR-ELEC-002'), 1, 18),
((SELECT id FROM products WHERE sku = 'KR-ELEC-003'), 1, 30),
((SELECT id FROM products WHERE sku = 'KR-COSM-001'), 1, 35),
((SELECT id FROM products WHERE sku = 'KR-COSM-002'), 1, 55),
((SELECT id FROM products WHERE sku = 'KR-FOOD-001'), 1, 120),
((SELECT id FROM products WHERE sku = 'KR-FOOD-002'), 1, 250),
((SELECT id FROM products WHERE sku = 'KR-FOOD-003'), 1, 90),
((SELECT id FROM products WHERE sku = 'KR-TEXT-001'), 1, 12),
((SELECT id FROM products WHERE sku = 'KR-TECH-001'), 1, 60);

-- North Distribution Center
INSERT INTO inventory (product_id, warehouse_id, quantity) VALUES
((SELECT id FROM products WHERE sku = 'KR-ELEC-004'), 2, 12),
((SELECT id FROM products WHERE sku = 'KR-ELEC-005'), 2, 15),
((SELECT id FROM products WHERE sku = 'KR-COSM-003'), 2, 65),
((SELECT id FROM products WHERE sku = 'KR-COSM-004'), 2, 110),
((SELECT id FROM products WHERE sku = 'KR-COSM-005'), 2, 45),
((SELECT id FROM products WHERE sku = 'KR-FOOD-004'), 2, 85),
((SELECT id FROM products WHERE sku = 'KR-FOOD-005'), 2, 160),
((SELECT id FROM products WHERE sku = 'KR-FOOD-006'), 2, 130),
((SELECT id FROM products WHERE sku = 'KR-TEXT-002'), 2, 35),
((SELECT id FROM products WHERE sku = 'KR-TECH-002'), 2, 22);

-- South Distribution Center
INSERT INTO inventory (product_id, warehouse_id, quantity) VALUES
((SELECT id FROM products WHERE sku = 'KR-COSM-006'), 3, 85),
((SELECT id FROM products WHERE sku = 'KR-FOOD-007'), 3, 65),
((SELECT id FROM products WHERE sku = 'KR-FOOD-008'), 3, 55),
((SELECT id FROM products WHERE sku = 'KR-TEXT-003'), 3, 105),
((SELECT id FROM products WHERE sku = 'KR-TEXT-004'), 3, 85),
((SELECT id FROM products WHERE sku = 'KR-TECH-003'), 3, 45),
((SELECT id FROM products WHERE sku = 'KR-TECH-004'), 3, 38);

-- Add some sample sales history for forecasting
INSERT INTO sales_history (product_id, sale_date, quantity, unit_price) VALUES
((SELECT id FROM products WHERE sku = 'KR-FOOD-002'), DATE_SUB(CURDATE(), INTERVAL 30 DAY), 45, 6.99),
((SELECT id FROM products WHERE sku = 'KR-FOOD-002'), DATE_SUB(CURDATE(), INTERVAL 25 DAY), 52, 6.99),
((SELECT id FROM products WHERE sku = 'KR-FOOD-002'), DATE_SUB(CURDATE(), INTERVAL 20 DAY), 38, 6.99),
((SELECT id FROM products WHERE sku = 'KR-COSM-002'), DATE_SUB(CURDATE(), INTERVAL 28 DAY), 25, 34.99),
((SELECT id FROM products WHERE sku = 'KR-COSM-002'), DATE_SUB(CURDATE(), INTERVAL 22 DAY), 30, 34.99),
((SELECT id FROM products WHERE sku = 'KR-COSM-002'), DATE_SUB(CURDATE(), INTERVAL 15 DAY), 28, 34.99),
((SELECT id FROM products WHERE sku = 'KR-ELEC-001'), DATE_SUB(CURDATE(), INTERVAL 35 DAY), 8, 899.99),
((SELECT id FROM products WHERE sku = 'KR-ELEC-001'), DATE_SUB(CURDATE(), INTERVAL 20 DAY), 12, 899.99),
((SELECT id FROM products WHERE sku = 'KR-ELEC-001'), DATE_SUB(CURDATE(), INTERVAL 10 DAY), 10, 899.99),
((SELECT id FROM products WHERE sku = 'KR-FOOD-001'), DATE_SUB(CURDATE(), INTERVAL 30 DAY), 35, 12.99),
((SELECT id FROM products WHERE sku = 'KR-FOOD-001'), DATE_SUB(CURDATE(), INTERVAL 20 DAY), 42, 12.99),
((SELECT id FROM products WHERE sku = 'KR-FOOD-001'), DATE_SUB(CURDATE(), INTERVAL 10 DAY), 38, 12.99);

-- Create sample purchase orders for Korean products
INSERT INTO purchase_orders (po_number, supplier_id, created_by, order_date, delivery_date, status, total_amount, notes) VALUES
('PO-KR-001', (SELECT id FROM suppliers WHERE company_name = 'Seoul Electronics Co.'), 1, DATE_SUB(CURDATE(), INTERVAL 15 DAY), DATE_ADD(CURDATE(), INTERVAL 5 DAY), 'approved', 17999.80, 'Korean electronics order'),
('PO-KR-002', (SELECT id FROM suppliers WHERE company_name = 'Busan Trading Ltd.'), 1, DATE_SUB(CURDATE(), INTERVAL 10 DAY), DATE_ADD(CURDATE(), INTERVAL 3 DAY), 'in_transit', 1249.50, 'Korean cosmetics shipment'),
('PO-KR-003', (SELECT id FROM suppliers WHERE company_name = 'Incheon Food Export'), 1, DATE_SUB(CURDATE(), INTERVAL 5 DAY), DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'pending', 899.40, 'Korean food products order');

-- Add items to purchase orders
INSERT INTO purchase_order_items (po_id, product_id, quantity, unit_price, subtotal) VALUES
-- PO-KR-001
((SELECT id FROM purchase_orders WHERE po_number = 'PO-KR-001'), (SELECT id FROM products WHERE sku = 'KR-ELEC-001'), 10, 899.99, 8999.90),
((SELECT id FROM purchase_orders WHERE po_number = 'PO-KR-001'), (SELECT id FROM products WHERE sku = 'KR-ELEC-003'), 18, 499.99, 8999.90),

-- PO-KR-002
((SELECT id FROM purchase_orders WHERE po_number = 'PO-KR-002'), (SELECT id FROM products WHERE sku = 'KR-COSM-001'), 5, 89.99, 449.95),
((SELECT id FROM purchase_orders WHERE po_number = 'PO-KR-002'), (SELECT id FROM products WHERE sku = 'KR-COSM-002'), 15, 34.99, 524.85),
((SELECT id FROM purchase_orders WHERE po_number = 'PO-KR-002'), (SELECT id FROM products WHERE sku = 'KR-COSM-003'), 10, 24.99, 249.90),
((SELECT id FROM purchase_orders WHERE po_number = 'PO-KR-002'), (SELECT id FROM products WHERE sku = 'KR-COSM-004'), 5, 8.99, 44.95),

-- PO-KR-003
((SELECT id FROM purchase_orders WHERE po_number = 'PO-KR-003'), (SELECT id FROM products WHERE sku = 'KR-FOOD-001'), 30, 12.99, 389.70),
((SELECT id FROM purchase_orders WHERE po_number = 'PO-KR-003'), (SELECT id FROM products WHERE sku = 'KR-FOOD-002'), 50, 6.99, 349.50),
((SELECT id FROM purchase_orders WHERE po_number = 'PO-KR-003'), (SELECT id FROM products WHERE sku = 'KR-FOOD-003'), 20, 8.99, 179.80);

-- Create sample shipments for Korean products
INSERT INTO shipments (shipment_number, po_id, type, origin_warehouse_id, destination_warehouse_id, supplier_id, vehicle_id, driver_id, status, scheduled_date, tracking_number, transport_cost, created_by) VALUES
('SHIP-KR-001', (SELECT id FROM purchase_orders WHERE po_number = 'PO-KR-001'), 'inbound', NULL, 1, (SELECT id FROM suppliers WHERE company_name = 'Seoul Electronics Co.'), (SELECT id FROM vehicles WHERE vehicle_number = 'VH-001' LIMIT 1), (SELECT id FROM drivers WHERE driver_code = 'DRV-001' LIMIT 1), 'in_transit', DATE_SUB(CURDATE(), INTERVAL 5 DAY), 'KR123456789', 250.00, 1),
('SHIP-KR-002', (SELECT id FROM purchase_orders WHERE po_number = 'PO-KR-002'), 'inbound', NULL, 2, (SELECT id FROM suppliers WHERE company_name = 'Busan Trading Ltd.'), (SELECT id FROM vehicles WHERE vehicle_number = 'VH-002' LIMIT 1), (SELECT id FROM drivers WHERE driver_code = 'DRV-002' LIMIT 1), 'scheduled', DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'KR987654321', 150.00, 1);

-- Add items to shipments
INSERT INTO shipment_items (shipment_id, product_id, quantity) VALUES
((SELECT id FROM shipments WHERE shipment_number = 'SHIP-KR-001'), (SELECT id FROM products WHERE sku = 'KR-ELEC-001'), 10),
((SELECT id FROM shipments WHERE shipment_number = 'SHIP-KR-001'), (SELECT id FROM products WHERE sku = 'KR-ELEC-003'), 18),
((SELECT id FROM shipments WHERE shipment_number = 'SHIP-KR-002'), (SELECT id FROM products WHERE sku = 'KR-COSM-001'), 5),
((SELECT id FROM shipments WHERE shipment_number = 'SHIP-KR-002'), (SELECT id FROM products WHERE sku = 'KR-COSM-002'), 15);

-- Add sample stock movements
INSERT INTO stock_movements (product_id, warehouse_id, movement_type, quantity, reference_type, reference_id, notes, created_by) VALUES
((SELECT id FROM products WHERE sku = 'KR-ELEC-001'), 1, 'in', 25, 'purchase_order', (SELECT id FROM purchase_orders WHERE po_number = 'PO-KR-001'), 'Initial stock from Korean supplier', 1),
((SELECT id FROM products WHERE sku = 'KR-COSM-002'), 2, 'in', 55, 'purchase_order', (SELECT id FROM purchase_orders WHERE po_number = 'PO-KR-002'), 'Korean cosmetics stock received', 1),
((SELECT id FROM products WHERE sku = 'KR-FOOD-002'), 1, 'out', 45, 'sale', NULL, 'Sale of Korean instant noodles', 1);

