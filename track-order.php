<?php
require 'db.php';

$results = [];
$searched = false;
$query_val = "";
$search_err = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['q'])) {
    $searched = true;
    $query_val = trim((string)($_POST['query'] ?? $_GET['q'] ?? ''));

    if ($query_val !== '') {
        $clean = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $query_val));
        $digits = preg_replace('/[^0-9]/', '', $query_val);

        // Require either a valid Order ID (min 4 chars) OR a full 10-digit mobile number
        if (strlen($digits) === 10) {
            $like_end = "%" . $digits;
            $stmt = $conn->prepare("SELECT * FROM orders WHERE cust_phone = ? OR cust_phone LIKE ? ORDER BY id DESC LIMIT 20");
            if ($stmt) {
                $stmt->bind_param("ss", $digits, $like_end);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $results[] = $row;
                }
                $stmt->close();
            }
        } elseif (strlen($clean) >= 4) {
            $stmt = $conn->prepare("SELECT * FROM orders WHERE order_code = ? ORDER BY id DESC LIMIT 5");
            if ($stmt) {
                $stmt->bind_param("s", $clean);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $results[] = $row;
                }
                $stmt->close();
            }
        } else {
            $search_err = "Please enter a valid Order ID (e.g., VK260001) or a full 10-digit Mobile Number.";
        }
    }
}

$steps = ['Pending Confirmation', 'Confirmed', 'Packed', 'Dispatched', 'Delivered'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Track Your Order | <?php echo htmlspecialchars($SHOP_NAME ?? 'VK CRACKERS'); ?></title>
  <style>
    :root { --primary: #d90429; --accent: #ffb703; --bg: #f8f9fa; --text: #1d1e22; --border: #e0e0e0; }
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', sans-serif; }
    body { background: var(--bg); color: var(--text); }
    .top-bar { background: #1d1e22; color: #fff; font-size: 12px; padding: 8px 4%; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 6px; }
    .top-bar strong { color: var(--accent); }
    header { background: linear-gradient(90deg, #780015, #d90429); color: #fff; padding: 12px 4%; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
    .logo-wrap { display: flex; align-items: center; gap: 10px; text-decoration: none; color: #fff; }
    .logo-wrap h1 { font-size: 20px; color: var(--accent); }
    nav { background: #2b0509; padding: 10px 4%; display: flex; gap: 16px; overflow-x: auto; }
    nav a { color: #fff; text-decoration: none; font-size: 14px; font-weight: 600; white-space: nowrap; }
    nav a:hover, nav a.active { color: var(--accent); }
    .container { max-width: 720px; margin: 30px auto; padding: 0 4%; }
    .card { background: #fff; border: 1px solid var(--border); border-radius: 10px; padding: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 20px; }
    .card h2 { color: var(--primary); margin-bottom: 8px; }
    .form-row { display: flex; gap: 10px; margin-top: 16px; flex-wrap: wrap; }
    .form-row input { flex: 1; min-width: 200px; padding: 12px; border: 1px solid #ccc; border-radius: 6px; font-size: 15px; }
    .form-row button { background: var(--primary); color: #fff; border: none; padding: 12px 24px; border-radius: 6px; font-weight: bold; font-size: 15px; cursor: pointer; }
    .status-badge { display: inline-block; background: #e6fcf5; color: #0ca678; border: 1px solid #96f2d7; padding: 4px 12px; border-radius: 20px; font-weight: bold; font-size: 13px; }
    .timeline { display: flex; justify-content: space-between; margin: 22px 0; position: relative; }
    .timeline::before { content: ""; position: absolute; top: 12px; left: 5%; right: 5%; height: 3px; background: #e9ecef; z-index: 1; }
    .step { position: relative; z-index: 2; text-align: center; width: 20%; font-size: 11px; color: #868e96; }
    .dot { width: 26px; height: 26px; border-radius: 50%; background: #dee2e6; color: #fff; display: flex; align-items: center; justify-content: center; margin: 0 auto 6px; font-weight: bold; }
    .step.done .dot { background: #2b9348; }
    .step.done { color: #1d1e22; font-weight: bold; }
    .lr-box { background: #fff8e6; border: 1px solid #ffe082; padding: 12px; border-radius: 6px; margin-top: 12px; font-size: 14px; }
    .items-box { background: #f8f9fa; padding: 12px; border-radius: 6px; margin-top: 12px; font-size: 13px; white-space: pre-line; border: 1px solid var(--border); }
    .wa-help { display: inline-block; margin-top: 14px; background: #25D366; color: #fff; text-decoration: none; padding: 10px 16px; border-radius: 6px; font-weight: bold; font-size: 14px; }
    footer { background: #1d1e22; color: #ccc; text-align: center; padding: 20px; margin-top: 40px; font-size: 13px; }
  </style>
</head>
<body>

  <div class="top-bar">
    <span>📞 WhatsApp & Support: <strong><?php echo htmlspecialchars($PHONE_DISPLAY ?? '+91 79047 85247'); ?></strong></span>
    <span>🚚 Sivakasi Direct Dispatch</span>
  </div>

  <header>
    <a href="index.php" class="logo-wrap">
      <svg width="42" height="42" viewBox="0 0 100 100">
        <circle cx="50" cy="50" r="46" fill="#240006" stroke="#ffb703" stroke-width="5"/>
        <text x="50" y="58" text-anchor="middle" font-family="serif" font-weight="bold" font-size="34" fill="#ffb703">VK</text>
        <text x="50" y="75" text-anchor="middle" font-family="sans-serif" font-weight="bold" font-size="10" fill="#fff">CRACKERS</text>
      </svg>
      <div>
        <h1><?php echo htmlspecialchars($SHOP_NAME ?? 'VK CRACKERS'); ?></h1>
        <small>Sivakasi Direct Factory Outlet</small>
      </div>
    </a>
  </header>

  <nav>
    <a href="index.php">Home</a>
    <a href="quick-shop.php">Quick Shopping</a>
    <a href="combo.php">Combo Products</a>
    <a href="track-order.php" class="active">Track Your Order</a>
    <a href="about.php">About Us</a>
    <a href="contact.php">Contact Us</a>
  </nav>

  <div class="container">
    <div class="card">
      <h2>🚚 Track Your Crackers Order</h2>
      <p style="color:#555;font-size:14px;">Enter your <strong>Order ID</strong> (e.g., <code>VK260001</code>) or your <strong>10-digit Mobile Number</strong> to check packing and lorry transport status.</p>
      <form method="POST" class="form-row">
        <input type="text" name="query" value="<?php echo htmlspecialchars($query_val); ?>" placeholder="Enter Order ID or 10-Digit Mobile Number..." maxlength="30" required>
        <button type="submit">Track Order</button>
      </form>
    </div>

    <?php if ($search_err !== ''): ?>
      <div class="card" style="text-align:center;">
        <p style="color:#c92a2a;font-weight:bold;font-size:14px;">⚠️ <?php echo htmlspecialchars($search_err); ?></p>
      </div>
    <?php elseif ($searched && empty($results)): ?>
      <div class="card" style="text-align:center;">
        <h3 style="color:#c92a2a;margin-bottom:8px;">No Order Found</h3>
        <p style="font-size:14px;color:#555;">We could not find an enquiry matching <strong><?php echo htmlspecialchars($query_val); ?></strong>. Please check the number or contact us on WhatsApp.</p>
        <a href="https://wa.me<?php echo htmlspecialchars($WHATSAPP_NUM ?? '917904785247'); ?>?text=<?php echo urlencode('Hi VK Crackers, I want to check my order status for: ' . $query_val); ?>" target="_blank" class="wa-help">📲 Ask on WhatsApp</a>
      </div>
    <?php endif; ?>
    <?php foreach ($results as $ord):
      $current_idx = array_search($ord['status'], $steps, true);
      if ($current_idx === false) $current_idx = 0;
    ?>
      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;border-bottom:1px solid var(--border);padding-bottom:12px;">
          <div>
            <h3 style="font-size:18px;">Order #<?php echo htmlspecialchars($ord['order_code']); ?></h3>
            <small style="color:#666;">Customer: <strong><?php echo htmlspecialchars($ord['cust_name']); ?></strong> (<?php echo htmlspecialchars($ord['cust_city']); ?>) • Date: <?php echo date("d M Y", strtotime($ord['created_at'])); ?></small>
          </div>
          <span class="status-badge"><?php echo htmlspecialchars($ord['status']); ?></span>
        </div>

        <div class="timeline">
          <?php foreach ($steps as $i => $label): $done = ($i <= $current_idx) ? 'done' : ''; ?>
            <div class="step <?php echo $done; ?>">
              <div class="dot"><?php echo ($i <= $current_idx) ? '✓' : ($i + 1); ?></div>
              <?php echo htmlspecialchars($label); ?>
            </div>
          <?php endforeach; ?>
        </div>

        <?php if (!empty($ord['tracking_info'])): ?>
          <div class="lr-box">
            <strong>🚛 Transport / LR Tracking Details:</strong><br>
            <?php echo htmlspecialchars($ord['tracking_info']); ?>
          </div>
        <?php endif; ?>

        <div class="items-box">
          <strong>Order Summary (Total: ₹<?php echo number_format((float)$ord['total_amount']); ?>):</strong><br>
          <?php echo htmlspecialchars($ord['items_text']); ?>
        </div>

        <a href="https://wa.me<?php echo htmlspecialchars($WHATSAPP_NUM ?? '917904785247'); ?>?text=<?php echo urlencode('Hi VK Crackers, enquiring about Order #' . $ord['order_code']); ?>" target="_blank" class="wa-help">📲 Support on WhatsApp</a>
      </div>
    <?php endforeach; ?>
  </div>

  <footer>
    <strong><?php echo htmlspecialchars($SHOP_NAME ?? 'VK CRACKERS'); ?></strong> • Sivakasi, Tamil Nadu • WhatsApp: <?php echo htmlspecialchars($PHONE_DISPLAY ?? '+91 79047 85247'); ?>
  </footer>

</body>
</html>
