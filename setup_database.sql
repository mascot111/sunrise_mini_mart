-- Sunrise Mini Mart Demo Site Database
-- Compatible with XAMPP MariaDB 10.4+ and MySQL 8.0+

CREATE TABLE customers (
    customer_id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL UNIQUE,
    email VARCHAR(100) UNIQUE,
    address VARCHAR(255) NOT NULL
);

CREATE TABLE employees (
    employee_id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    role VARCHAR(40) NOT NULL,
    phone VARCHAR(20) NOT NULL UNIQUE,
    hire_date DATE NOT NULL,
    CONSTRAINT chk_employee_role
        CHECK (role IN ('Manager', 'Cashier', 'Storekeeper', 'Sales Assistant'))
);

CREATE TABLE suppliers (
    supplier_id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_name VARCHAR(120) NOT NULL UNIQUE,
    phone VARCHAR(20) NOT NULL UNIQUE,
    email VARCHAR(100) UNIQUE,
    address VARCHAR(255) NOT NULL
);

CREATE TABLE categories (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(80) NOT NULL UNIQUE,
    description VARCHAR(255)
);

CREATE TABLE products (
    product_id INT AUTO_INCREMENT PRIMARY KEY,
    product_name VARCHAR(120) NOT NULL,
    category_id INT NOT NULL,
    supplier_id INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    quantity_in_stock INT NOT NULL DEFAULT 0,
    reorder_level INT NOT NULL DEFAULT 10,
    CONSTRAINT uq_product_supplier UNIQUE (product_name, supplier_id),
    CONSTRAINT chk_product_unit_price CHECK (unit_price > 0),
    CONSTRAINT chk_product_quantity CHECK (quantity_in_stock >= 0),
    CONSTRAINT chk_product_reorder CHECK (reorder_level >= 0),
    CONSTRAINT fk_product_category
        FOREIGN KEY (category_id)
        REFERENCES categories(category_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT fk_product_supplier
        FOREIGN KEY (supplier_id)
        REFERENCES suppliers(supplier_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);

CREATE TABLE sales (
    sale_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    employee_id INT NOT NULL,
    sale_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    total_amount DECIMAL(10,2) NOT NULL,
    CONSTRAINT chk_sale_total CHECK (total_amount >= 0),
    CONSTRAINT fk_sale_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers(customer_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT fk_sale_employee
        FOREIGN KEY (employee_id)
        REFERENCES employees(employee_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);

CREATE TABLE sale_items (
    sale_item_id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    line_total DECIMAL(10,2) NOT NULL,
    CONSTRAINT uq_sale_product UNIQUE (sale_id, product_id),
    CONSTRAINT chk_sale_item_quantity CHECK (quantity > 0),
    CONSTRAINT chk_sale_item_unit_price CHECK (unit_price > 0),
    CONSTRAINT chk_sale_item_total CHECK (line_total = quantity * unit_price),
    CONSTRAINT fk_sale_item_sale
        FOREIGN KEY (sale_id)
        REFERENCES sales(sale_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_sale_item_product
        FOREIGN KEY (product_id)
        REFERENCES products(product_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);

CREATE TABLE payments (
    payment_id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    payment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    amount_paid DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(30) NOT NULL,
    CONSTRAINT chk_payment_amount CHECK (amount_paid > 0),
    CONSTRAINT chk_payment_method
        CHECK (payment_method IN ('Cash', 'Mobile Money', 'Card', 'Bank Transfer')),
    CONSTRAINT fk_payment_sale
        FOREIGN KEY (sale_id)
        REFERENCES sales(sale_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);

CREATE TABLE purchases (
    purchase_id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT NOT NULL,
    employee_id INT NOT NULL,
    purchase_date DATE NOT NULL,
    total_cost DECIMAL(10,2) NOT NULL,
    CONSTRAINT chk_purchase_total CHECK (total_cost >= 0),
    CONSTRAINT fk_purchase_supplier
        FOREIGN KEY (supplier_id)
        REFERENCES suppliers(supplier_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT fk_purchase_employee
        FOREIGN KEY (employee_id)
        REFERENCES employees(employee_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);

CREATE TABLE purchase_items (
    purchase_item_id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_cost DECIMAL(10,2) NOT NULL,
    line_total DECIMAL(10,2) NOT NULL,
    CONSTRAINT uq_purchase_product UNIQUE (purchase_id, product_id),
    CONSTRAINT chk_purchase_item_quantity CHECK (quantity > 0),
    CONSTRAINT chk_purchase_item_unit_cost CHECK (unit_cost > 0),
    CONSTRAINT chk_purchase_item_total CHECK (line_total = quantity * unit_cost),
    CONSTRAINT fk_purchase_item_purchase
        FOREIGN KEY (purchase_id)
        REFERENCES purchases(purchase_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_purchase_item_product
        FOREIGN KEY (product_id)
        REFERENCES products(product_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);

INSERT INTO customers (full_name, phone, email, address) VALUES
('Joseph Brown', '0241000001', 'joseph.brown@example.com', '12 Market Road'),
('Mary Williams', '0241000002', 'mary.williams@example.com', '45 School Lane'),
('Daniel Smith', '0241000003', 'daniel.smith@example.com', '8 Church Street'),
('Fatima Bello', '0241000004', 'fatima.bello@example.com', '19 Station Avenue');

INSERT INTO employees (full_name, role, phone, hire_date) VALUES
('Amina Yusuf', 'Manager', '0202000001', '2024-03-15'),
('David Mensah', 'Cashier', '0202000002', '2025-01-10'),
('Sarah Johnson', 'Storekeeper', '0202000003', '2024-09-01');

INSERT INTO suppliers (supplier_name, phone, email, address) VALUES
('FreshFlow Distributors', '0303000001', 'orders@freshflow.example.com', '4 Warehouse Road'),
('Golden Grain Supplies', '0303000002', 'sales@goldengrain.example.com', '22 Mill Street'),
('Bright Home Wholesale', '0303000003', 'support@brighthome.example.com', '31 Industrial Area'),
('PureCare Ltd', '0303000004', 'info@purecare.example.com', '7 Health Avenue');

INSERT INTO categories (category_name, description) VALUES
('Beverages', 'Drinks such as water, juice, and milk'),
('Food Staples', 'Basic food items used regularly by households'),
('Snacks', 'Ready-to-eat packaged snacks'),
('Personal Care', 'Personal hygiene and body-care products'),
('Household Supplies', 'Cleaning and home-use products'),
('Dairy', 'Milk and other refrigerated dairy products');

INSERT INTO products
    (product_name, category_id, supplier_id, unit_price, quantity_in_stock, reorder_level)
VALUES
('Bottled Water 500ml', 1, 1, 1.00, 120, 30),
('Orange Juice 1L', 1, 1, 2.50, 50, 15),
('Rice 5kg', 2, 2, 12.00, 40, 10),
('Spaghetti 500g', 2, 2, 1.80, 80, 20),
('Plantain Chips', 3, 2, 1.20, 100, 25),
('Toothpaste 100ml', 4, 4, 3.25, 45, 10),
('Dishwashing Liquid 750ml', 5, 3, 4.75, 35, 8),
('Liquid Milk 1L', 6, 1, 2.10, 60, 20);

INSERT INTO purchases (supplier_id, employee_id, purchase_date, total_cost) VALUES
(1, 3, '2026-06-28', 245.00),
(2, 3, '2026-06-30', 353.00),
(3, 3, '2026-07-01', 108.00);

INSERT INTO purchase_items
    (purchase_id, product_id, quantity, unit_cost, line_total)
VALUES
(1, 1, 100, 0.60, 60.00),
(1, 2, 50, 1.70, 85.00),
(1, 8, 50, 2.00, 100.00),
(2, 3, 20, 9.00, 180.00),
(2, 4, 60, 1.30, 78.00),
(2, 5, 100, 0.95, 95.00),
(3, 7, 30, 3.60, 108.00);

INSERT INTO sales (customer_id, employee_id, sale_date, total_amount) VALUES
(1, 2, '2026-07-02 09:15:00', 17.25),
(2, 2, '2026-07-02 11:40:00', 15.15),
(3, 2, '2026-07-03 15:20:00', 14.40),
(4, 1, '2026-07-04 10:05:00', 40.50);

INSERT INTO sale_items
    (sale_id, product_id, quantity, unit_price, line_total)
VALUES
(1, 3, 1, 12.00, 12.00),
(1, 1, 2, 1.00, 2.00),
(1, 6, 1, 3.25, 3.25),
(2, 2, 2, 2.50, 5.00),
(2, 4, 3, 1.80, 5.40),
(2, 7, 1, 4.75, 4.75),
(3, 8, 4, 2.10, 8.40),
(3, 5, 5, 1.20, 6.00),
(4, 3, 2, 12.00, 24.00),
(4, 6, 2, 3.25, 6.50),
(4, 1, 10, 1.00, 10.00);

INSERT INTO payments (sale_id, payment_date, amount_paid, payment_method) VALUES
(1, '2026-07-02 09:16:00', 17.25, 'Cash'),
(2, '2026-07-02 11:41:00', 15.15, 'Mobile Money'),
(3, '2026-07-03 15:22:00', 10.00, 'Cash'),
(3, '2026-07-03 15:23:00', 4.40, 'Card'),
(4, '2026-07-04 10:07:00', 40.50, 'Bank Transfer');
