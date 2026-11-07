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