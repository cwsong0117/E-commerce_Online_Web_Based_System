-- Migration 001: add user columns the application code expects.
-- Run this ONCE on a database that was created from an older insertTable.sql.
-- (A fresh import of the current insertTable.sql already includes these columns.)
-- If a column already exists, MySQL reports "Duplicate column name" for that line;
-- that is harmless, just run the remaining lines.
USE ecommerce_db;

ALTER TABLE users ADD COLUMN reset_otp VARCHAR(6) NULL;
ALTER TABLE users ADD COLUMN reset_otp_expires_at DATETIME NULL;
ALTER TABLE users ADD COLUMN remember_token VARCHAR(255) NULL;
ALTER TABLE users ADD COLUMN remember_token_expires_at DATETIME NULL;
