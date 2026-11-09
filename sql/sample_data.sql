-- Sample data for local testing. Run ONCE, after insertTable.sql, on an empty shop.
--   E:\xampp\mysql\bin\mysql.exe -u root < sql\sample_data.sql
-- (or use phpMyAdmin > Import)
--
-- Sample member (email already verified, so you can log in straight away):
--   username: testmember     password: Member@12345
-- Sample vouchers: WELCOME10 (10% off), RM20OFF (RM20 off), FREESHIP (free shipping)
-- Product images are the files already in web/images/products/.
-- Prices: selling_price = original_price, because checkout charges original_price.
USE ecommerce_db;

-- ---------- Products ----------
INSERT IGNORE INTO product (product_id, product_name, category, description, has_size) VALUES
(1, 'Nike Air Max 90',                 'Shoes',       'Classic cushioned everyday sneaker with a visible Air unit.', 1),
(2, 'New Balance 530',                 'Shoes',       'Retro running-inspired sneaker with ABZORB cushioning.', 1),
(3, 'Adidas Ultraboost',               'Shoes',       'Responsive running shoe with Boost midsole.', 1),
(4, 'Adidas Own the Run T-Shirt',      'Apparel',     'Lightweight, moisture-absorbing running tee.', 1),
(5, 'Adidas Essentials Shorts',        'Apparel',     'Soft French terry shorts with 3-Stripes.', 1),
(6, 'Nike Dri-FIT Club Cap',           'Accessories', 'Unstructured, adjustable cap with sweat-wicking fabric.', 0),
(7, 'Nike Bucket Hat',                 'Accessories', 'Lightweight bucket hat for sun protection.', 0),
(8, 'Nike Academy Dri-FIT Drill Top',  'Apparel',     'Football training top that keeps you dry.', 1);

INSERT IGNORE INTO product_price (product_id, cost, original_price, selling_price) VALUES
(1, 350.00, 549.00, 549.00),
(2, 280.00, 459.00, 459.00),
(3, 430.00, 699.00, 699.00),
(4,  70.00, 129.00, 129.00),
(5,  85.00, 149.00, 149.00),
(6,  40.00,  79.00,  79.00),
(7,  45.00,  89.00,  89.00),
(8,  90.00, 159.00, 159.00);

-- ---------- Colour variants ----------
INSERT IGNORE INTO product_variant (variant_id, product_id, color) VALUES
(1, 1, 'Black'),  (2, 1, 'Beige'),        (3, 1, 'Grey'),
(4, 2, 'White'),  (5, 2, 'Pink'),         (6, 2, 'Red'),
(7, 3, 'Black'),  (8, 3, 'Green'),
(9, 4, 'Black'),  (10, 4, 'Silver Violet'),
(11, 5, 'Black'), (12, 5, 'Grey'),
(13, 6, 'Black'), (14, 6, 'Red'),
(15, 7, 'Black'), (16, 7, 'White'),
(17, 8, 'Black'), (18, 8, 'White');

-- ---------- Images (one per colour) ----------
INSERT IGNORE INTO product_image (id, product_id, variant_id, image_path, type) VALUES
(1, 1, 1,  'web/images/products/Nike_Air_Max_90_black.webp', 'main'),
(2, 1, 2,  'web/images/products/Nike_Air_Max_90_beige.webp', 'gallery'),
(3, 1, 3,  'web/images/products/Nike_Air_Max_90_grey.webp',  'gallery'),
(4, 2, 4,  'web/images/products/New_Balance_530_White.webp', 'main'),
(5, 2, 5,  'web/images/products/New_Balance_530_Pink.webp',  'gallery'),
(6, 2, 6,  'web/images/products/New_Balance_530_Red.jpeg',   'gallery'),
(7, 3, 7,  'web/images/products/adidas_ultraboost_black.webp', 'main'),
(8, 3, 8,  'web/images/products/adidas_ultraboost_green.webp', 'gallery'),
(9, 4, 9,  'web/images/products/Adidas_Own_the_Run_3-Stripes_Mens_Performance_T-Shirt_Black.webp', 'main'),
(10, 4, 10, 'web/images/products/Adidas_Own_the_Run_3-Stripes_Mens_Performance_T-Shirt_Silver_Violet.webp', 'gallery'),
(11, 5, 11, 'web/images/products/Adidas_Essentials_French_Terry_3_Stripes_Mens_Shorts_Black.webp', 'main'),
(12, 5, 12, 'web/images/products/Adidas_Essentials_French_Terry_3_Stripes_Mens_Shorts_Grey.webp', 'gallery'),
(13, 6, 13, 'web/images/products/Nike_Dri-FIT_Club_Cap_Black.webp', 'main'),
(14, 6, 14, 'web/images/products/Nike_Dri-FIT_Club_Cap_red.webp',   'gallery'),
(15, 7, 15, 'web/images/products/Nike_Bucket_Hat_Black.webp', 'main'),
(16, 7, 16, 'web/images/products/Nike_Bucket_Hat_White.webp', 'gallery'),
(17, 8, 17, 'web/images/products/Nike_Academy_Mens_Dri-FIT_Football_Drill_Top_Black.webp', 'main'),
(18, 8, 18, 'web/images/products/Nike_Academy_Mens_Dri-FIT_Football_Drill_Top_White.webp', 'gallery');

-- ---------- Stock (10 of every size / colour to start) ----------
-- Shoes: UK sizes
INSERT INTO inventory (product_id, variant_id, size, stock_quantity)
SELECT v.product_id, v.variant_id, s.size, 10
FROM product_variant v
JOIN (SELECT 'UK-7' AS size UNION ALL SELECT 'UK-8' UNION ALL SELECT 'UK-9' UNION ALL SELECT 'UK-10') s
WHERE v.product_id IN (1, 2, 3);

-- Apparel: letter sizes
INSERT INTO inventory (product_id, variant_id, size, stock_quantity)
SELECT v.product_id, v.variant_id, s.size, 10
FROM product_variant v
JOIN (SELECT 'S' AS size UNION ALL SELECT 'M' UNION ALL SELECT 'L' UNION ALL SELECT 'XL') s
WHERE v.product_id IN (4, 5, 8);

-- Caps and hats: no size
INSERT INTO inventory (product_id, variant_id, size, stock_quantity)
SELECT product_id, variant_id, NULL, 25
FROM product_variant
WHERE product_id IN (6, 7);

-- Edge cases for testing the stock limits
UPDATE inventory SET stock_quantity = 2 WHERE product_id = 1 AND variant_id = 1 AND size = 'UK-10';  -- only 2 left
UPDATE inventory SET stock_quantity = 0 WHERE product_id = 2 AND variant_id = 5 AND size = 'UK-7';   -- sold out

-- ---------- Vouchers ----------
INSERT IGNORE INTO voucher (voucher_id, description, code, type, discount_value, min_spend, max_discount, start_date, end_date, status, is_redeemable) VALUES
(1, '10% off, up to RM30',        'WELCOME10', 'percent',      10.00,  50.00, 30.00, '2026-01-01', '2027-12-31', 'active', TRUE),
(2, 'RM20 off orders over RM200', 'RM20OFF',   'fixed',        20.00, 200.00, NULL,  '2026-01-01', '2027-12-31', 'active', TRUE),
(3, 'Free shipping',              'FREESHIP',  'freeshipping',  0.00,   0.00, NULL,  '2026-01-01', '2027-12-31', 'active', TRUE);

-- ---------- Sample member ----------
INSERT INTO users (username, full_name, gender, contact_no, email, DateOfBirth, password, role, status, email_verified)
SELECT 'testmember', 'Test Member', 'Other', '0123456789', 'testmember@example.com', '2000-01-01',
       '$2y$12$g7Bg7UcKi3RUfJbzENd/rO9.sShTOBwtzRO5247Hp55pMnn76Rmh6', 'member', 'active', 1
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'testmember');
