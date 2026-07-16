-- Sample Data for Historical Sales (10 records)
-- This uses the first 5 products from your products table
-- Note: Make sure you have at least 5 products in your products table

SET @product1 = (SELECT id FROM products ORDER BY id LIMIT 1);
SET @product2 = (SELECT id FROM products ORDER BY id LIMIT 1 OFFSET 1);
SET @product3 = (SELECT id FROM products ORDER BY id LIMIT 1 OFFSET 2);
SET @product4 = (SELECT id FROM products ORDER BY id LIMIT 1 OFFSET 3);
SET @product5 = (SELECT id FROM products ORDER BY id LIMIT 1 OFFSET 4);

SET @price1 = (SELECT unit_price FROM products WHERE id = @product1);
SET @price2 = (SELECT unit_price FROM products WHERE id = @product2);
SET @price3 = (SELECT unit_price FROM products WHERE id = @product3);
SET @price4 = (SELECT unit_price FROM products WHERE id = @product4);
SET @price5 = (SELECT unit_price FROM products WHERE id = @product5);

INSERT INTO sales_history (product_id, sale_date, quantity, unit_price) VALUES
(@product1, '2024-01-15', 45, @price1),
(@product1, '2024-02-10', 52, @price1),
(@product2, '2024-01-20', 30, @price2),
(@product2, '2024-02-18', 35, @price2),
(@product3, '2024-01-25', 15, @price3),
(@product3, '2024-02-22', 18, @price3),
(@product4, '2024-02-05', 120, @price4),
(@product4, '2024-02-28', 135, @price4),
(@product5, '2024-02-12', 8, @price5),
(@product5, '2024-03-01', 10, @price5);

-- Sample Data for Forecast Demand (10 records)
INSERT INTO demand_forecasts (product_id, forecast_period, forecasted_quantity, confidence_level, created_by) VALUES
(@product1, '2024-03-15', 50, 85.50, 1),
(@product1, '2024-04-15', 55, 82.00, 1),
(@product2, '2024-03-20', 38, 88.00, 1),
(@product2, '2024-04-20', 40, 85.50, 1),
(@product3, '2024-03-25', 20, 90.00, 1),
(@product3, '2024-04-25', 22, 87.50, 1),
(@product4, '2024-03-10', 140, 80.00, 1),
(@product4, '2024-04-10', 150, 78.50, 1),
(@product5, '2024-03-15', 12, 92.00, 1),
(@product5, '2024-04-15', 14, 89.00, 1);
