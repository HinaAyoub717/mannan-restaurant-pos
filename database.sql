-- Mannan Restaurant Management System
-- NOTE: this recreates the database from scratch (sample data only).
DROP DATABASE IF EXISTS mannan_restaurant;
CREATE DATABASE mannan_restaurant CHARACTER SET utf8mb4;
USE mannan_restaurant;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,  -- bcrypt hash, never plain text
    role ENUM('Admin', 'Manager', 'Cashier', 'Waiter', 'Kitchen') NOT NULL
);

CREATE TABLE dining_tables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    table_number VARCHAR(20) NOT NULL,
    capacity INT NOT NULL,
    status ENUM('Available', 'Occupied', 'Reserved', 'Needs Cleaning', 'Out of Service') DEFAULT 'Available'
);

CREATE TABLE menu_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    category VARCHAR(50) NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    is_available BOOLEAN DEFAULT TRUE
);

CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(30) UNIQUE NOT NULL,
    client_token VARCHAR(64) NULL UNIQUE,       -- blocks duplicate submissions
    table_id INT NULL,
    order_type ENUM('Dine-In', 'Takeaway', 'Delivery') NOT NULL,
    status ENUM('New', 'Confirmed', 'Preparing', 'Ready', 'Served', 'Completed', 'Cancelled') DEFAULT 'Confirmed',
    subtotal DECIMAL(10, 2) NOT NULL,
    discount DECIMAL(10, 2) DEFAULT 0.00,
    total_amount DECIMAL(10, 2) NOT NULL,
    payment_status ENUM('Unpaid', 'Paid') DEFAULT 'Unpaid',
    payment_method VARCHAR(50) DEFAULT 'Cash',
    cancel_reason VARCHAR(255) NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (table_id) REFERENCES dining_tables(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    menu_item_id INT NOT NULL,
    quantity INT NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    notes VARCHAR(255),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id)
);

CREATE TABLE password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,      -- only a hash of the token is stored
    expires_at DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Sample data. Demo passwords: admin123, manager123, cashier123, waiter123, kitchen123
INSERT INTO users (name, email, password, role) VALUES
('Mannan Owner', 'admin@mannan.com', '$2b$12$sJQHQqP4D9J.U63qKxfLvO.bMg/AVX7mkYTSxLKq0V5ZPwZ/lQK6W', 'Admin'),
('Hassan Manager', 'manager@mannan.com', '$2b$12$loPtHuTJ2VOovm2LSDIcRu9pxsa88UhLyq4RoeEUyrGfzQEf6C.Ey', 'Manager'),
('Ali Cashier', 'cashier@mannan.com', '$2b$12$.ElOSSmpspJ9GYnC7bmQOOcVVwPh2aNiTDEL8KpH2jBqdQ63AfQ9e', 'Cashier'),
('Usman Waiter', 'waiter@mannan.com', '$2b$12$drrQW6MBW270MVBon9YT2erTngi3rKP9vko2EYZOuHr9qppQRvSpC', 'Waiter'),
('Chef Bilal', 'kitchen@mannan.com', '$2b$12$chBTKP3VM4D.0VqpYVaAxuZQR4Yw5ov58OwKVXu8itEyvL7H28dKq', 'Kitchen');

INSERT INTO dining_tables (table_number, capacity, status) VALUES
('Table 01', 4, 'Available'),
('Table 02', 2, 'Available'),
('Table 03', 6, 'Available'),
('Table 04', 4, 'Reserved'),
('Table 05', 8, 'Needs Cleaning'),
('Table 06', 4, 'Available');

INSERT INTO menu_items (name, category, price, is_available) VALUES
('Chicken Biryani (Full)', 'Rice & Biryani', 450.00, TRUE),
('Mutton Karahi (Half)', 'Karahi & Handi', 1650.00, TRUE),
('Chicken Tikka Chest', 'BBQ', 420.00, TRUE),
('Roghni Naan', 'Tandoor', 60.00, TRUE),
('Mint Raita', 'Sides', 80.00, TRUE),
('Fresh Lime Soda', 'Beverages', 150.00, TRUE);