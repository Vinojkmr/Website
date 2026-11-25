CREATE DATABASE IF NOT EXISTS `vk_database`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `vk_database`;

-- Drop old tables if they exist with an outdated schema
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `products`;

-- Products Table
CREATE TABLE `products` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `cat` VARCHAR(50) NOT NULL,
    `tag` VARCHAR(30) DEFAULT 'Featured',
    `mrp` INT NOT NULL,
    `price` INT NOT NULL,
    `icon` VARCHAR(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Orders Table
CREATE TABLE `orders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_code` VARCHAR(20) NOT NULL UNIQUE,
    `cust_name` VARCHAR(100) NOT NULL,
    `cust_phone` VARCHAR(20) NOT NULL,
    `cust_city` VARCHAR(150) NOT NULL,
    `items_text` TEXT NOT NULL,
    `total_amount` INT NOT NULL,
    `status` VARCHAR(50) DEFAULT 'Pending Confirmation',
    `tracking_info` VARCHAR(255) DEFAULT 'Awaiting phone confirmation',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Starter Product Seed Data
INSERT INTO `products` (`name`, `cat`, `tag`, `mrp`, `price`, `icon`) VALUES
('12cm Color Sparklers (10 Pcs)', 'Sparklers', 'Featured', 240, 27, '✨'),
('12cm Electric Sparklers (10 Pcs)', 'Sparklers', 'Best Seller', 200, 21, '✨'),
('30cm Electric Sparklers (5 Pcs)', 'Sparklers', 'Featured', 300, 35, '✨'),
('Ground Chakkar Big (10 Pcs)', 'Ground Chakkara', 'New Arrival', 330, 42, '🌀'),
('Ground Chakkar Special (10 Pcs)', 'Ground Chakkara', 'Featured', 600, 95, '🌀'),
('Flower Pots Big (10 Pcs)', 'Fountains', 'Best Seller', 450, 65, '🌋'),
('Color Koti Deluxe (10 Pcs)', 'Fountains', 'New Arrival', 900, 140, '🌋'),
('1½ Chotta Fancy (1 Pc)', 'Aerial Fancy', 'Best Seller', 350, 39, '🎆'),
('7 Shots Multi Color (5 Pcs)', 'Aerial Fancy', 'New Arrival', 1300, 145, '🎆'),
('30 Shot Multi Color Prime (1 Pc)', 'Aerial Fancy', 'Featured', 4500, 440, '🎆'),
('120 Shot Multi Color (1 Pc)', 'Aerial Fancy', 'Featured', 15000, 1520, '🎇'),
('Red Bijili Crackers (100 Pcs)', 'Sound Crackers', 'Best Seller', 200, 32, '🧨'),
('1000 Wala Garland (1 Pc)', 'Repeating Sound', 'New Arrival', 1800, 260, '🧨'),
('7 Star Rocket (10 Pcs)', 'Rockets', 'Featured', 800, 120, '🚀'),
('Hydro Bomb Green (10 Pcs)', 'Bombs', 'Best Seller', 750, 110, '💥'),
('Kids Fun Toy Gun & Rolls', 'Kids Special', 'New Arrival', 250, 99, '🔫'),
('Deepavali Family Combo (35 Items)', 'Combo Products', 'Featured', 4500, 999, '🎁'),
('Super Star Gift Box (50 Items)', 'GiftBox', 'Best Seller', 7500, 1650, '🎁');
