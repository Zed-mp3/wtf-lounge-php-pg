-- =========================================================
-- db_shop — WTF Lounge edition
-- Café ordering schema: categories/products/customers/orders/
-- orderdetails, with account login (password_hash) and an
-- is_admin flag for the admin dashboard.
--
-- Import: mysql -u root -p < db_shop.sql
-- (or via phpMyAdmin > Import)
--
-- IMPORTANT — creating your first admin account:
-- 1. Import this file, then load the site and Register a
--    normal account through register.php.
-- 2. Promote that account to admin with:
--      UPDATE customers SET is_admin = 1 WHERE phone = 'YOUR-PHONE-HERE';
-- 3. Log out and back in — you'll now see the Admin nav link.
-- =========================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;
SET time_zone = "+00:00";
SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS `db_shop` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `db_shop`;

-- --------------------------------------------------------
-- Table: categories
-- --------------------------------------------------------
DROP TABLE IF EXISTS `categories`;
CREATE TABLE `categories` (
  `categoryID` INT(11) NOT NULL AUTO_INCREMENT,
  `categoryname` VARCHAR(100) NOT NULL,
  PRIMARY KEY (`categoryID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `categories` (`categoryID`, `categoryname`) VALUES
(1, 'Milk Tea'),
(2, 'Frappe'),
(3, 'Pastries'),
(4, 'Merch');

-- --------------------------------------------------------
-- Table: products
-- --------------------------------------------------------
DROP TABLE IF EXISTS `products`;
CREATE TABLE `products` (
  `productID` INT(11) NOT NULL AUTO_INCREMENT,
  `productname` VARCHAR(255) NOT NULL,
  `categoryID` INT(11) DEFAULT NULL,
  `price` DOUBLE NOT NULL DEFAULT 0,
  `image` VARCHAR(500) DEFAULT NULL,
  `rating` DOUBLE DEFAULT 5.0,
  `sold_count` INT(11) DEFAULT 0,
  `stock` INT(11) NOT NULL DEFAULT 0,
  `default_meta` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`productID`),
  KEY `fk_products_category` (`categoryID`),
  KEY `idx_products_name` (`productname`),
  CONSTRAINT `fk_products_category` FOREIGN KEY (`categoryID`)
    REFERENCES `categories` (`categoryID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `products`
  (`productID`, `productname`, `categoryID`, `price`, `image`, `rating`, `sold_count`, `stock`, `default_meta`) VALUES
(1, 'Sakura Milk Tea', 1, 5.50, 'https://images.unsplash.com/photo-1558857563-b371033873b8?q=80&w=600&auto=format&fit=crop', 5.0, 1200, 40, 'Iced, Less Sweet'),
(2, 'Rose Petal Latte', 1, 6.00, 'https://images.unsplash.com/photo-1517701604599-bb29b565090c?q=80&w=600&auto=format&fit=crop', 4.9, 850, 35, 'Hot, Oat Milk'),
(3, 'Floral Cupcake', 3, 4.50, 'https://images.unsplash.com/photo-1519869325930-281384150729?q=80&w=600&auto=format&fit=crop', 4.8, 2100, 25, 'Vanilla Bean'),
(4, 'Lavender Frappe', 2, 6.50, 'https://images.unsplash.com/photo-1461023058943-07fcbe16d735?q=80&w=600&auto=format&fit=crop', 5.0, 940, 30, 'Iced, Oat Milk'),
(5, 'Butter Croissant', 3, 4.50, 'https://images.unsplash.com/photo-1555507036-ab1f4038808a?q=80&w=600&auto=format&fit=crop', 4.9, 1600, 5, 'Fresh Baked'),
(6, 'Iced Matcha Latte', 1, 6.50, 'https://images.unsplash.com/photo-1536013455962-08d6e64f1c04?q=80&w=600&auto=format&fit=crop', 4.9, 1100, 20, 'Oat Milk, Light Ice'),
(7, 'Pink Cloud Bowl', 3, 12.00, 'https://images.unsplash.com/photo-1495214783159-3503fd1b572d?q=80&w=600&auto=format&fit=crop', 4.7, 410, 0, 'Extra Berries'),
(8, 'Botanical Tote', 4, 14.00, 'https://images.unsplash.com/photo-1591561954557-26941169b49e?q=80&w=600&auto=format&fit=crop', 5.0, 260, 12, 'Canvas, One Size');

ALTER TABLE `products` MODIFY `productID` INT(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT = 9;

-- --------------------------------------------------------
-- Table: customers
-- password_hash is NULL for the legacy seed row below — see the
-- note at the top of this file for turning a real registered
-- account into your first admin. Legacy null-password accounts
-- can no longer log in directly (that was a security hole); an
-- admin must set a password for them from Admin > Users.
-- --------------------------------------------------------
DROP TABLE IF EXISTS `customers`;
CREATE TABLE `customers` (
  `customerID` INT(11) NOT NULL AUTO_INCREMENT,
  `fullname` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `password_hash` VARCHAR(255) DEFAULT NULL,
  `is_admin` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`customerID`),
  UNIQUE KEY `uq_customer_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `customers` (`customerID`, `fullname`, `phone`, `address`) VALUES
(1, 'Jane Doe', '0917-123-4567', '12 Bloom St, Quezon City');

ALTER TABLE `customers` MODIFY `customerID` INT(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT = 2;

-- --------------------------------------------------------
-- Table: orders
-- --------------------------------------------------------
DROP TABLE IF EXISTS `orders`;
CREATE TABLE `orders` (
  `orderID` INT(11) NOT NULL AUTO_INCREMENT,
  `order_code` VARCHAR(20) NOT NULL,
  `customerID` INT(11) DEFAULT NULL,
  `order_mode` ENUM('Delivery','Pickup') NOT NULL DEFAULT 'Delivery',
  `payment_method` VARCHAR(50) NOT NULL DEFAULT 'Cash on Pickup',
  `subtotal` DOUBLE NOT NULL DEFAULT 0,
  `delivery_fee` DOUBLE NOT NULL DEFAULT 0,
  `total` DOUBLE NOT NULL DEFAULT 0,
  `status` VARCHAR(30) NOT NULL DEFAULT 'Pending',
  `order_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`orderID`),
  UNIQUE KEY `uq_order_code` (`order_code`),
  KEY `fk_orders_customer` (`customerID`),
  CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customerID`)
    REFERENCES `customers` (`customerID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `orders`
  (`orderID`, `order_code`, `customerID`, `order_mode`, `payment_method`, `subtotal`, `delivery_fee`, `total`, `status`, `order_date`) VALUES
(1, 'WTF-9821', 1, 'Pickup', 'Cash on Pickup', 6.50, 0.00, 6.50, 'Completed', '2024-10-24 10:15:00'),
(2, 'WTF-9755', 1, 'Pickup', 'Cash on Pickup', 4.25, 0.00, 4.25, 'Completed', '2024-10-20 09:40:00'),
(3, 'WTF-8102', 1, 'Delivery', 'GCash', 12.00, 3.00, 15.00, 'Completed', '2024-09-15 14:05:00');

ALTER TABLE `orders` MODIFY `orderID` INT(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT = 4;

-- --------------------------------------------------------
-- Table: orderdetails
-- --------------------------------------------------------
DROP TABLE IF EXISTS `orderdetails`;
CREATE TABLE `orderdetails` (
  `orderdetailID` INT(11) NOT NULL AUTO_INCREMENT,
  `orderID` INT(11) NOT NULL,
  `productID` INT(11) DEFAULT NULL,
  `productname_snapshot` VARCHAR(255) NOT NULL,
  `price` DOUBLE NOT NULL,
  `quantity` INT(11) NOT NULL DEFAULT 1,
  `meta_snapshot` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`orderdetailID`),
  KEY `fk_orderdetails_order` (`orderID`),
  KEY `fk_orderdetails_product` (`productID`),
  CONSTRAINT `fk_orderdetails_order` FOREIGN KEY (`orderID`)
    REFERENCES `orders` (`orderID`) ON DELETE CASCADE,
  CONSTRAINT `fk_orderdetails_product` FOREIGN KEY (`productID`)
    REFERENCES `products` (`productID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `orderdetails`
  (`orderID`, `productID`, `productname_snapshot`, `price`, `quantity`, `meta_snapshot`) VALUES
(1, 6, 'Iced Matcha Latte', 6.50, 1, 'Oat Milk, light ice'),
(2, 5, 'Butter Croissant', 4.25, 1, 'Warm, to go'),
(3, 7, 'Pink Cloud Bowl', 12.00, 1, 'Extra berries');

COMMIT;
SET FOREIGN_KEY_CHECKS = 1;

-- =========================================================
-- Advanced DB feature: TRIGGERS that enforce the "never sell
-- more than you have" business rule at the database layer,
-- independent of whatever the PHP happens to check.
--
-- 1. trg_orderdetails_check_stock (BEFORE INSERT) blocks the
--    insert outright if there isn't enough stock left, via
--    SIGNAL SQLSTATE '45000'. This is a hard backstop even if
--    the app-layer check in checkout.php were ever skipped or
--    raced by a concurrent request.
-- 2. trg_orderdetails_decrement_stock (AFTER INSERT) auto-
--    deducts the ordered quantity from products.stock, so
--    "stock auto-updates on order placement" is guaranteed by
--    the schema, not just by application code.
-- =========================================================
DELIMITER $$

DROP TRIGGER IF EXISTS `trg_orderdetails_check_stock`$$
CREATE TRIGGER `trg_orderdetails_check_stock`
BEFORE INSERT ON `orderdetails`
FOR EACH ROW
BEGIN
  DECLARE available INT DEFAULT NULL;
  IF NEW.productID IS NOT NULL THEN
    SELECT stock INTO available FROM products WHERE productID = NEW.productID;
    IF available IS NOT NULL AND available < NEW.quantity THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Insufficient stock for the requested product.';
    END IF;
  END IF;
END$$

DROP TRIGGER IF EXISTS `trg_orderdetails_decrement_stock`$$
CREATE TRIGGER `trg_orderdetails_decrement_stock`
AFTER INSERT ON `orderdetails`
FOR EACH ROW
BEGIN
  IF NEW.productID IS NOT NULL THEN
    UPDATE products
       SET stock = stock - NEW.quantity,
           sold_count = sold_count + NEW.quantity
     WHERE productID = NEW.productID;
  END IF;
END$$

DELIMITER ;
