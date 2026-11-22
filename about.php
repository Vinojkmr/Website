<?php require 'db.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>About Us | <?php echo $SHOP_NAME; ?></title>
  <style>
    :root { --primary: #d90429; --accent: #ffb703; --bg: #f8f9fa; }
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: sans-serif; }
    body { background: var(--bg); line-height: 1.6; }
    header { background: linear-gradient(90deg, #780015, #d90429); color: #fff; padding: 14px 4%; }
    nav { background: #2b0509; padding: 10px 4%; display: flex; gap: 16px; overflow-x: auto; }
    nav a { color: #fff; text-decoration: none; font-size: 13px; font-weight: bold; white-space: nowrap; }
    nav a.active { color: var(--accent); }
    .container { max-width: 900px; margin: 24px auto; padding: 0 4%; }
    .card { background: #fff; padding: 22px; border-radius: 10px; margin-bottom: 16px; box-shadow: 0 2px 6px rgba(0,0,0,0.05); }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-top: 14px; }
    .feat { background: #fff3f3; padding: 14px; border-radius: 8px; border-left: 4px solid var(--primary); }
  </style>
</head>
<body>
  <header><h2 style="color:var(--accent);"><?php echo $SHOP_NAME; ?></h2><small>Sivakasi Direct Wholesale & Retail Outlet</small></header>
  <nav>
    <a href="index.php">Home</a>
    <a href="quick-shop.php">Quick Shopping</a>
    <a href="combo.php">Combo Products</a>
    <a href="track-order.php">Track Order</a>
    <a href="about.php" class="active">About Us</a>
    <a href="contact.php">Contact Us</a>
  </nav>
  <div class="container">
    <div class="card">
      <h2>About <?php echo $SHOP_NAME; ?></h2>
      <p style="margin-top:10px;">Welcome to <strong><?php echo $SHOP_NAME; ?></strong>, your trusted direct factory outlet in Sivakasi. We supply 100% genuine, safety-tested green crackers at factory-direct prices with 80% to 90% discounts for families, apartments, and wholesale buyers.</p>
      <div class="grid">
        <div class="feat"><strong>✅ Quality Assured</strong><br><small>Made from top-grade raw materials in Sivakasi.</small></div>
        <div class="feat"><strong>🛡️ Safety Tested</strong><br><small>Every batch undergoes strict quality checks.</small></div>
        <div class="feat"><strong>🚛 Timely Delivery</strong><br><small>Dispatched via registered parcel lorry services.</small></div>
        <div class="feat"><strong>💰 Minimum Order ₹<?php echo $MIN_ORDER; ?></strong><br><small>Assured delivery before Deepavali.</small></div>
      </div>
    </div>
    <div class="card" style="background:#fff8e6;border:1px solid #ffe082;">
      <h3>⚖️ Statutory & Legal Compliance</h3>
      <p style="font-size:13px;margin-top:8px;">As per the 2018 Supreme Court Order, direct online sale of firecrackers is not permitted. Customers can select items on our Estimate page and submit their enquiry. Our team contacts you within 2 hours to confirm the order by phone. Shop License No: <strong><?php echo $LICENSE_NO; ?></strong>.</p>
    </div>
  </div>
</body>
</html>