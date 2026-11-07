CREATE DATABASE IF NOT EXISTS ecommerce_db;
USE ecommerce_db;

-- Users table
CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    gender ENUM('Male','Female','Other') DEFAULT 'Other',
    contact_no VARCHAR(20),
    email VARCHAR(100) NOT NULL UNIQUE,
    profile_photo MEDIUMBLOB,
    DateOfBirth DATE NOT NULL,
    password VARCHAR(255) NOT NULL,
    security_question VARCHAR(255),
    security_answer VARCHAR(255),
    reset_otp VARCHAR(6) NULL,
    reset_otp_expires_at DATETIME NULL,
    remember_token VARCHAR(255) NULL,
    remember_token_expires_at DATETIME NULL,
    role ENUM('member','admin') DEFAULT 'member',
    status ENUM('active','inactive','banned','blocked') DEFAULT 'active',
    failed_login_attempts INT DEFAULT 0,
    last_failed_login DATETIME NULL,
    last_login_at DATETIME NULL,
    email_verified BOOLEAN DEFAULT FALSE,
    verification_token VARCHAR(255) NULL,
    token_expires_at DATETIME NULL,
    two_factor_secret VARCHAR(255) NULL,
    two_factor_enabled BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO users (
    username,
    full_name,
    email,
    password,
    role,
    status,
    email_verified,
    DateOfBirth,
    gender,
    contact_no
)
SELECT 
    'system' AS username,
    'System' AS full_name,
    'system@ngear.com' AS email,
    '$2y$10$1.aE.FvO6cSM7iGJkCe2x.FGt9rKvyJJQdO1sQ0AC2Yu6/Karyd36' AS password,
    'admin' AS role,
    'active' AS status,
    1 AS email_verified,
    '2000-01-01' AS DateOfBirth,
    'Other' AS gender,
    NULL AS contact_no
WHERE NOT EXISTS (
    SELECT 1 FROM users WHERE username = 'system' AND role = 'admin'
);

-- Product table
CREATE TABLE IF NOT EXISTS product (
    product_id INT AUTO_INCREMENT PRIMARY KEY,
    product_name VARCHAR(255) NOT NULL,
    category VARCHAR(255) NOT NULL,
    description TEXT,
    has_size TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Voucher table
CREATE TABLE IF NOT EXISTS voucher (
    voucher_id INT AUTO_INCREMENT PRIMARY KEY,
    description VARCHAR(255),
    code VARCHAR(50) NOT NULL UNIQUE,
    type ENUM('percent', 'fixed', 'freeshipping') NOT NULL,
    discount_value DECIMAL(10,2) NOT NULL,
    min_spend DECIMAL(10,2) NOT NULL DEFAULT 0,
    max_discount DECIMAL(10,2),
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status ENUM('active', 'inactive', 'expired') DEFAULT 'active',
    is_redeemable BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Address table (depends on users)
CREATE TABLE IF NOT EXISTS address (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    address1 VARCHAR(30) NOT NULL,
    address2 VARCHAR(30) NOT NULL,
    city VARCHAR(20) NOT NULL,
    postcode VARCHAR(5) NOT NULL,
    state VARCHAR(20) NOT NULL,
    label ENUM('Home', 'Work', 'Other') NOT NULL DEFAULT 'Home',
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);

-- Shopping cart table (depends on users)
CREATE TABLE IF NOT EXISTS shopping_cart (
    cart_id INT(20) AUTO_INCREMENT PRIMARY KEY,
    user_id INT(20) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);

-- Voucher usage table (depends on users and voucher)
CREATE TABLE IF NOT EXISTS voucher_usage (
    user_id INT NOT NULL,
    voucher_id INT NOT NULL,
    used_at DATETIME NOT NULL,
    
    PRIMARY KEY (user_id, voucher_id), 
    FOREIGN KEY (user_id) REFERENCES users(user_id),
    FOREIGN KEY (voucher_id) REFERENCES voucher(voucher_id)
);

-- Product price table (depends on product)
CREATE TABLE IF NOT EXISTS product_price (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    cost DECIMAL(10,2) NOT NULL,
    original_price DECIMAL(10,2) NOT NULL,
    selling_price DECIMAL(10,2) NOT NULL,

    -- 1-to-1 relationship (each product has only one price)
    CONSTRAINT fk_price_product
        FOREIGN KEY (product_id)
        REFERENCES product(product_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    -- Enforce 1 product = 1 price row
    UNIQUE (product_id)
);

-- Product variant table (depends on product) - MOVED UP
CREATE TABLE IF NOT EXISTS product_variant (
    variant_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    color VARCHAR(50) DEFAULT NULL,
    FOREIGN KEY (product_id) REFERENCES product(product_id)
);

-- Product image table (depends on product and product_variant)
CREATE TABLE IF NOT EXISTS product_image (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    variant_id INT NULL,   
    image_path VARCHAR(255) NOT NULL,
    type ENUM('main', 'gallery') DEFAULT 'gallery',

    FOREIGN KEY (product_id) REFERENCES product(product_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    FOREIGN KEY (variant_id) REFERENCES product_variant(variant_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);

-- Orders table (depends on users and voucher)
CREATE TABLE IF NOT EXISTS orders (
    order_id INT(20) AUTO_INCREMENT PRIMARY KEY,
    user_id INT(20) NOT NULL,
    voucher_id INT(20),
    total_amount DECIMAL(10, 2) NOT NULL CHECK(total_amount >= 0),
    order_status ENUM('pending', 'paid', 'processing', 'shipped', 'delivered', 'refund_requested', 'canceled', 'refunded') DEFAULT 'pending',
    shipping_address TEXT DEFAULT NULL,
    tracking_number VARCHAR(100) DEFAULT NULL,
    create_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(user_id),
    FOREIGN KEY (voucher_id) REFERENCES voucher(voucher_id)
);

-- Cart item table (depends on shopping_cart and product)
CREATE TABLE IF NOT EXISTS cart_item (
    cart_item_id INT(20) AUTO_INCREMENT PRIMARY KEY,
    cart_id INT(20) NOT NULL,
    product_id INT(20) NOT NULL,
    variant_id INT(20) DEFAULT NULL,
    size VARCHAR(20) DEFAULT NULL,
    quantity INT NOT NULL CHECK(quantity > 0),

    FOREIGN KEY (cart_id) REFERENCES shopping_cart(cart_id),
    FOREIGN KEY (product_id) REFERENCES product(product_id),
    FOREIGN KEY (variant_id) REFERENCES product_variant(variant_id)
);

-- Inventory table (depends on product_variant)
CREATE TABLE IF NOT EXISTS inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- Product-level stock (for products without variants)
    product_id INT NULL,

    -- Variant-level stock (for products with color variants)
    variant_id INT NULL,

    -- Size-based stock (NULL = free size / no size)
    size VARCHAR(20) NULL,

    -- Current available stock
    stock_quantity INT NOT NULL DEFAULT 0,

    -- Last updated timestamp
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                 ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign key constraints
    CONSTRAINT fk_inventory_product
        FOREIGN KEY (product_id)
        REFERENCES product(product_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_inventory_variant
        FOREIGN KEY (variant_id)
        REFERENCES product_variant(variant_id)
        ON DELETE CASCADE,

    -- Prevent duplicate stock rows
    UNIQUE KEY uniq_inventory (product_id, variant_id, size)
);

-- Order item table (depends on orders and product)
CREATE TABLE IF NOT EXISTS order_item (
    order_item_id INT(20) AUTO_INCREMENT PRIMARY KEY,
    order_id INT(20) NOT NULL,
    product_id INT(20) NOT NULL,
    variant_id INT(20) DEFAULT NULL,
    size VARCHAR(20) DEFAULT NULL,
    product_name_snapshot VARCHAR(255) NOT NULL,
    product_price_snapshot DECIMAL(10, 2) NOT NULL,
    quantity INT NOT NULL CHECK(quantity > 0),
    subtotal DECIMAL(10, 2) NOT NULL CHECK(subtotal >= 0),

    FOREIGN KEY (order_id) REFERENCES orders(order_id),
    FOREIGN KEY (product_id) REFERENCES product(product_id),
    FOREIGN KEY (variant_id) REFERENCES product_variant(variant_id)
);

-- Payment table (depends on orders)
CREATE TABLE IF NOT EXISTS payment (
    payment_id INT(20) AUTO_INCREMENT PRIMARY KEY,
    order_id INT(20) NOT NULL,
    payment_method ENUM('credit_card', 'online-banking', 'e-wallet') NOT NULL,
    payment_status ENUM('pending', 'paid') DEFAULT 'paid',
    transaction_id VARCHAR(100) UNIQUE,
    paid_amount DECIMAL(10, 2) NOT NULL CHECK(paid_amount >= 0),
    payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (order_id) REFERENCES orders(order_id)
);

CREATE TABLE IF NOT EXISTS voucher_assignment (
    assignment_id INT AUTO_INCREMENT PRIMARY KEY,
    voucher_id INT NOT NULL,
    user_id INT NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    assigned_by INT, -- Admin user_id who assigned this voucher
    
    FOREIGN KEY (voucher_id) REFERENCES voucher(voucher_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(user_id) ON DELETE SET NULL,
    
    -- Prevent duplicate assignments
    UNIQUE KEY unique_assignment (voucher_id, user_id),
    
    INDEX idx_voucher_id (voucher_id),
    INDEX idx_user_id (user_id),
    INDEX idx_assigned_at (assigned_at)
);

CREATE TABLE IF NOT EXISTS chat_room (
    chat_room_id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    admin_id INT NULL,
    status ENUM('open','closed') DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    closed_at TIMESTAMP NULL,

    FOREIGN KEY (member_id) REFERENCES users(user_id),
    FOREIGN KEY (admin_id) REFERENCES users(user_id)
);

CREATE TABLE IF NOT EXISTS chat_message (
    message_id INT AUTO_INCREMENT PRIMARY KEY,
    chat_room_id INT NOT NULL,
    sender_id INT NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (chat_room_id) REFERENCES chat_room(chat_room_id)
        ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(user_id)
);

CREATE TABLE IF NOT EXISTS order_notes (
    note_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    admin_id INT NOT NULL,
    note_text TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
    FOREIGN KEY (admin_id) REFERENCES users(user_id) ON DELETE CASCADE,
    
    INDEX idx_order_id (order_id),
    INDEX idx_admin_id (admin_id),
    INDEX idx_created_at (created_at)
);

CREATE TABLE IF NOT EXISTS product_review (
    review_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    user_id INT NOT NULL,
    order_id INT NOT NULL,
    order_item_id INT NOT NULL,
    rating TINYINT NOT NULL CHECK (rating >= 1 AND rating <= 5),
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (product_id) REFERENCES product(product_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
    FOREIGN KEY (order_item_id) REFERENCES order_item(order_item_id) ON DELETE CASCADE,
    
    -- Prevent duplicate reviews for the same order item
    UNIQUE KEY unique_user_order_item (user_id, order_item_id),
    
    -- Index for faster product review queries
    INDEX idx_product_id (product_id),
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at)
);

CREATE TABLE IF NOT EXISTS wishlist (
    wishlist_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    variant_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES product(product_id) ON DELETE CASCADE,
    FOREIGN KEY (variant_id) REFERENCES product_variant(variant_id) ON DELETE CASCADE,
    
    -- Prevent duplicate entries (same user, product, and variant combination)
    UNIQUE KEY unique_user_product_variant (user_id, product_id, variant_id),
    
    INDEX idx_user_id (user_id),
    INDEX idx_product_id (product_id),
    INDEX idx_variant_id (variant_id),
    INDEX idx_created_at (created_at)
);

CREATE TABLE IF NOT EXISTS admin_activity_log (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    action_type VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT NULL,
    action_description TEXT NOT NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (admin_id) REFERENCES users(user_id) ON DELETE CASCADE,
    
    INDEX idx_admin_id (admin_id),
    INDEX idx_action_type (action_type),
    INDEX idx_entity_type (entity_type),
    INDEX idx_entity_id (entity_id),
    INDEX idx_created_at (created_at),
    INDEX idx_admin_action (admin_id, action_type),
    INDEX idx_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


