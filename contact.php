<?php
require 'db.php';
$sent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['msg_submit'])) {
    $n = htmlspecialchars(trim($_POST['name']));
    $p = htmlspecialchars(trim($_POST['phone']));
    $m = htmlspecialchars(trim($_POST['message']));
    $txt = "*Contact Enquiry - {$SHOP_NAME}*\n*Name:* {$n}\n*Phone:* {$p}\n*Message:* {$m}";
    header("Location: https://wa.me/{$WHATSAPP_NUM}?text=" . urlencode($txt));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Contact Us | <?php echo $SHOP_NAME; ?></title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', sans-serif; }
    body { background: #f8f9fa; color: #1d1e22; }
    header { background: linear-gradient(90deg, #780015, #d90429); color: #fff; padding: 14px 4%; display: flex; justify-content: space-between; align-items: center; }
    header h1 { font-size: 20px; color: #ffb703; }
    .nav-bar { background: #fff; border-bottom: 1px solid #ddd; padding: 10px 4%; display: flex; gap: 18px; overflow-x: auto; font-size: 14px; font-weight: 600; }
    .nav-bar a { text-decoration: none; color: #1d1e22; white-space: nowrap; }
    .nav-bar a.active { color: #d90429; }
    .wrap { max-width: 900px; margin: 24px auto; padding: 0 4%; display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; }
    .card { background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; padding: 20px; }
    .card h2 { color: #d90429; margin-bottom: 12px; font-size: 20px; }
    .inp { width: 100%; padding: 10px; margin-bottom: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; }
    .btn { width: 100%; background: #25D366; color: #fff; border: none; padding: 12px; border-radius: 6px; font-size: 15px; font-weight: bold; cursor: pointer; }
  </style>
</head>
<body>
  <header>
    <h1><?php echo $SHOP_NAME; ?></h1>
    <span style="font-size:13px;">📞 +91 79047 85247</span>
  </header>
  <nav class="nav-bar">
    <a href="index.php">Home</a>
    <a href="quick-shop.php">Quick Shopping</a>
    <a href="combo.php">Combo Products</a>
    <a href="track-order.php">Track Your Order</a>
    <a href="about.php">About Us</a>
    <a href="contact.php" class="active">Contact Us</a>
  </nav>
  <div class="wrap">
    <div class="card">
      <h2>Get in Touch</h2>
      <p style="line-height:1.8;font-size:14px;">
        <strong>Shop Name:</strong> <?php echo $SHOP_NAME; ?><br>
        <strong>Address:</strong> Sivakasi Main Road, Sivakasi, Virudhunagar District, Tamil Nadu - 626189<br>
        <strong>Phone & WhatsApp:</strong> +91 79047 85247<br>
        <strong>Working Hours:</strong> 9:00 AM – 9:30 PM (All Days)
      </p>
    </div>
    <div class="card">
      <h2>Send Quick Enquiry</h2>
      <form method="POST">
        <input type="text" name="name" class="inp" placeholder="Your Name *" required>
        <input type="tel" name="phone" class="inp" placeholder="Your Mobile Number *" required>
        <textarea name="message" rows="4" class="inp" placeholder="Ask about wholesale rates, gift boxes, or transport..." required></textarea>
        <button type="submit" name="msg_submit" class="btn">📲 Chat on WhatsApp</button>
      </form>
    </div>
  </div>
</body>
</html>
