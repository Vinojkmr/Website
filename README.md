# VK Crackers - Sivakasi Direct Store

A mobile-friendly PHP & MySQL e-commerce estimate catalog and admin management system designed for local deployment on Android (KSWEB) or standard LAMP/WAMP servers.

## Features
- **Store Front Catalog (`index.php`, `quick-shop.php`, `combo.php`):** Category filtering, live search, automatic selling rate calculation based on global discount %, and out-of-stock item hiding.
- **Sequential Order Tracking (`VK26xxxx`):** Generates yearly sequential order IDs and allows customers to check order status and lorry LR details via `track-order.php`.
- **Sivakasi ESTIMATE PDF Generator:** Print-ready HTML/PDF estimate invoices displaying customer details, Bank/UPI payment info, MRP, discount %, and net rates.
- **Admin Dashboard (`admin.php`):**
  - Manage customer orders, update lorry tracking info, and print customer bills.
  - Configure global Store Front Discount % and minimum order limits.
  - Edit Bank Account, UPI ID, and shop contact details (`settings.json`).
  - Add/update product MRPs and toggle stock visibility.
  - Generate walk-in counter bills with optional extra discounts and packing charges.

## Setup Instructions
1. Import `database.sql` into MySQL / phpMyAdmin to create the `products` and `orders` tables.
2. Create `db.php` with your MySQL credentials (`$conn`), `$SHOP_NAME`, `$ADMIN_PASS`, and `$MIN_ORDER`.
3. Place all files inside your server's `htdocs` directory and log in to `admin.php` to configure your Store Front discount and Bank/UPI details.
