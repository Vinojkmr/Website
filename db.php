<?php
// Database Configuration
$host   = "localhost";
$user   = "root";
$pass   = "";
$dbname = "vk_database";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($host, $user, $pass);
    $conn->set_charset("utf8mb4");

    // Auto-create database if it does not exist
    $conn->query("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $conn->select_db($dbname);

    // Products table
    $conn->query("CREATE TABLE IF NOT EXISTS products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        cat VARCHAR(50) NOT NULL,
        tag VARCHAR(30) DEFAULT 'Featured',
        mrp INT NOT NULL,
        price INT NOT NULL,
        icon VARCHAR(20) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Orders table (synchronized with index.php, quick-shop.php, and admin.php)
    $conn->query("CREATE TABLE IF NOT EXISTS orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_code VARCHAR(20) NOT NULL UNIQUE,
        cust_name VARCHAR(100) NOT NULL,
        cust_phone VARCHAR(20) NOT NULL,
        cust_city VARCHAR(150) NOT NULL,
        items_text TEXT NOT NULL,
        total_amount INT NOT NULL,
        status VARCHAR(50) DEFAULT 'Pending Confirmation',
        tracking_info VARCHAR(255) DEFAULT 'Awaiting phone confirmation',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Auto-migrate existing orders table if tracking_info column is missing
    $col_chk = $conn->query("SHOW COLUMNS FROM orders LIKE 'tracking_info'");
    if ($col_chk && $col_chk->num_rows === 0) {
        $conn->query("ALTER TABLE orders ADD COLUMN tracking_info VARCHAR(255) DEFAULT 'Awaiting phone confirmation' AFTER status");
    }

    // Seed starter products if table is empty
    $chk = $conn->query("SELECT COUNT(*) AS c FROM products")->fetch_assoc();
    if ((int)$chk['c'] === 0) {
        $conn->query("INSERT INTO products (name, cat, tag, mrp, price, icon) VALUES
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
        ('Super Star Gift Box (50 Items)', 'GiftBox', 'Best Seller', 7500, 1650, '🎁')");
    }
} catch (mysqli_sql_exception $e) {
    error_log("Database Error: " . $e->getMessage());
    die("Service temporarily unavailable. Please try again later.");
}

// Store Configuration Constants
$SHOP_NAME     = "VK CRACKERS";
$WHATSAPP_NUM  = "917904785247";
$PHONE_DISPLAY = "+91 79047 85247";
$EMAIL         = "sales@vkcrackers.in";
$ADDRESS       = "Sivakasi Main Road, Sivakasi, Virudhunagar District, Tamil Nadu - 626189";
$LICENSE_NO    = "XXX/2026";
$MIN_ORDER     = 3000;
$ADMIN_PASS    = "vk1234"; // Change this to a strong password before deploying live
?>