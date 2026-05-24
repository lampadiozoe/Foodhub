-- FoodHub database structure
DROP DATABASE IF EXISTS foodhub;
CREATE DATABASE foodhub;
USE foodhub;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','user') NOT NULL DEFAULT 'user',
    phone VARCHAR(25) NULL,
    address TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO users (name, email, password, role) VALUES
('Admin', 'admin@foodhub.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');

CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    stock INT NOT NULL,
    description TEXT,
    image VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO products (name, price, stock, description, image) VALUES
('Chicken Adobo', 150.00, 50, 'Tender chicken marinated in soy sauce, vinegar, garlic, and bay leaves.', 'adobo.jpg'),
('Pork Sinigang', 180.00, 40, 'Sour tamarind-based soup with pork and local vegetables.', 'sinigang.jpg'),
('Beef Tapa', 160.00, 30, 'Garlicky cured beef fried to savory perfection.', 'tapa.jpg'),
('Pancit Canton', 120.00, 60, 'Stir-fried egg noodles with meat and vegetables.', 'pancit.jpg'),
('Lumpiang Shanghai', 100.00, 70, 'Crispy spring rolls stuffed with seasoned pork and veggies.', 'lumpia.jpg'),
('Halo-Halo', 80.00, 25, 'Cooling tropical dessert with shaved ice, fruits and sweet beans.', 'halohalo.jpg');

CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    total DECIMAL(12,2) NOT NULL,
    status ENUM('pending','serving','ready','completed') DEFAULT 'pending',
    order_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);
