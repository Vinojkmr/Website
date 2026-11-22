<?php
require 'db.php';

$default_cfg = [
    'ac_name' => 'VK Crackers', 'ac_no' => '000000000000', 'ac_type' => 'Current',
    'bank_name' => 'Indian Overseas Bank', 'ifsc' => 'IOBA0000000', 'upi' => 'vkcrackers@upi',
    'email' => 'vkcrackers@gmail.com', 'address' => 'Sivakasi Main Road, Sivakasi, Tamil Nadu - 626189',
    'discount_pct' => 80, 'min_order' => (isset($MIN_ORDER) ? (int)$MIN_ORDER : 3000),
    'disabled_ids' => []
];
$cfg = file_exists('settings.json') ? array_merge($default_cfg, json_decode(file_get_contents('settings.json'), true)) : $default_cfg;
$MIN_ORDER = (int)$cfg['min_order'];
$DISC_PCT = (float)$cfg['discount_pct'];
$disabled_ids = isset($cfg['disabled_ids']) && is_array($cfg['disabled_ids']) ? array_map('intval', $cfg['disabled_ids']) : [];

$products = [];
$res = $conn->query("SELECT * FROM products ORDER BY cat, id");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $pid = (int)$row['id'];
        // Hide out-of-stock / disabled products
        if (in_array($pid, $disabled_ids, true)) {
            continue;
        }
        $row['id'] = $pid;
        $row['mrp'] = (int)$row['mrp'];
        // Calculate discounted selling rate directly from MRP & Store Front Discount %
        $row['price'] = $DISC_PCT > 0 ? (int)round($row['mrp'] - ($row['mrp'] * $DISC_PCT / 100)) : (int)$row['price'];
        $products[] = $row;
    }
}

$submitted_order = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_data'])) {
    $name = htmlspecialchars(trim($_POST['cust_name']));
    $phone = preg_replace('/[^0-9]/', '', trim($_POST['cust_phone']));
    $city = htmlspecialchars(trim($_POST['cust_city']));
    $cart = json_decode($_POST['order_data'], true);

    // Sequential Order ID: VK + 2-digit year + 4-digit count (e.g., VK260001)
    $yr = date("y");
    $prefix = "VK" . $yr;
    $cnt_res = $conn->query("SELECT COUNT(*) AS total FROM orders WHERE order_code LIKE '{$prefix}%'");
    $next_num = ($cnt_res && ($r = $cnt_res->fetch_assoc())) ? ((int)$r['total'] + 1) : 1;
    $order_code = $prefix . str_pad($next_num, 4, '0', STR_PAD_LEFT);

    $msg = "*{$SHOP_NAME} Quick Estimate ({$order_code})*\n*Name:* {$name}\n*Phone:* {$phone}\n*City:* {$city}\n\n";
    $items_summary = "";
    $pdf_items = [];
    $total = 0;
    $i = 1;

    foreach ($products as $p) {
        $id = (string)$p['id'];
        if (!empty($cart[$id]) && $cart[$id] > 0) {
            $q = (int)$cart[$id];
            $sub = $p['price'] * $q;
            $total += $sub;
            $line = "{$i}. {$p['name']} x {$q} = ₹{$sub}\n";
            $msg .= $line;
            $items_summary .= $line;
            $pdf_items[] = [
                'id' => $p['id'],
                'name' => $p['name'],
                'mrp' => $p['mrp'],
                'price' => $p['price'],
                'qty' => $q,
                'sub' => $sub
            ];
            $i++;
        }
    }
    $msg .= "\n*Net Total: ₹{$total}*\nTrack Order ID: *{$order_code}*";

    $stmt = $conn->prepare("INSERT INTO orders (order_code, cust_name, cust_phone, cust_city, items_text, total_amount, status, tracking_info) VALUES (?, ?, ?, ?, ?, ?, 'Pending Confirmation', 'Awaiting phone confirmation')");
    if ($stmt) {
        $stmt->bind_param("sssssi", $order_code, $name, $phone, $city, $items_summary, $total);
        $stmt->execute();
    }

    file_put_contents("enquiries.txt", "[" . date("Y-m-d H:i:s") . "] {$order_code} - {$name} ({$phone}, {$city}) ₹{$total}\n{$msg}\n---\n", FILE_APPEND);

    $submitted_order = [
        'code' => $order_code,
        'date' => date("d-m-Y"),
        'name' => $name,
        'phone' => $phone,
        'city' => $city,
        'items' => $pdf_items,
        'total' => $total
    ];
}

$grouped = [];
foreach ($products as $p) {
    $grouped[$p['cat']][] = $p;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Quick Shopping | <?php echo $SHOP_NAME; ?></title>
  <style>
    :root { --primary: #d90429; --accent: #ffb703; --bg: #f8f9fa; --border: #e0e0e0; }
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: sans-serif; }
    body { background: var(--bg); padding-bottom: 90px; }
    .top-bar { background: #1d1e22; color: #fff; font-size: 12px; padding: 8px 4%; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 6px; }
    .top-bar strong { color: var(--accent); }
    header { background: linear-gradient(90deg, #780015, #d90429); color: #fff; padding: 12px 4%; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
    .logo-wrap { display: flex; align-items: center; gap: 10px; text-decoration: none; color: #fff; }
    .logo-wrap h1 { font-size: 20px; color: var(--accent); }
    .nav-bar { background: #2b0509; padding: 10px 4%; display: flex; gap: 16px; overflow-x: auto; }
    .nav-bar a { color: #fff; text-decoration: none; font-size: 13px; font-weight: bold; white-space: nowrap; }
    .nav-bar a:hover, .nav-bar a.active { color: var(--accent); }
    .container { max-width: 1000px; margin: 16px auto; padding: 0 3%; }
    .cat-header { background: #2b0509; color: var(--accent); padding: 10px 14px; font-weight: bold; font-size: 15px; border-radius: 6px 6px 0 0; margin-top: 18px; }
    table.qtable { width: 100%; border-collapse: collapse; background: #fff; font-size: 13px; }
    table.qtable th, table.qtable td { border: 1px solid var(--border); padding: 8px 6px; text-align: left; }
    table.qtable th { background: #f1f3f5; font-size: 12px; }
    .mrp { text-decoration: line-through; color: #888; font-size: 11px; display: block; }
    .price { color: var(--primary); font-weight: bold; }
    .qty-inp { width: 54px; padding: 6px; border: 1px solid #aaa; border-radius: 4px; text-align: center; font-weight: bold; }
    .sticky-foot { position: fixed; bottom: 0; left: 0; right: 0; background: #1d1e22; color: #fff; padding: 12px 4%; display: flex; justify-content: space-between; align-items: center; z-index: 150; }
    .btn-order { background: var(--primary); color: #fff; border: none; padding: 10px 18px; border-radius: 6px; font-weight: bold; cursor: pointer; }
    .modal { position: fixed; inset: 0; background: rgba(0,0,0,0.6); display: none; align-items: center; justify-content: center; z-index: 200; padding: 16px; }
    .modal-box { background: #fff; padding: 20px; border-radius: 10px; width: 100%; max-width: 380px; }
    .inp { width: 100%; padding: 9px; margin-bottom: 10px; border: 1px solid var(--border); border-radius: 5px; }
  </style>
</head>
<body>

  <div class="top-bar">
    <span>📞 Phone: <strong>+91 79047 85247</strong></span>
    <span>🚚 Min Order: <strong>₹<?php echo number_format($MIN_ORDER); ?>/-</strong></span>
  </div>

  <header>
    <a href="index.php" class="logo-wrap">
      <div><h1><?php echo $SHOP_NAME; ?></h1><small>Quick Price List (<?php echo $DISC_PCT; ?>% OFF)</small></div>
    </a>
    <span style="font-size:12px;font-weight:bold;color:var(--accent);">Flat <?php echo $DISC_PCT; ?>% OFF</span>
  </header>

  <nav class="nav-bar">
    <a href="index.php">Home</a>
    <a href="quick-shop.php" class="active">Quick Shopping</a>
    <a href="combo.php">Combo Products</a>
    <a href="track-order.php">Track Order</a>
    <a href="about.php">About Us</a>
    <a href="contact.php">Contact</a>
  </nav>

  <div class="container">
    <?php foreach ($grouped as $cat => $items): ?>
      <div class="cat-header"><?php echo htmlspecialchars($cat); ?></div>
      <table class="qtable">
        <thead>
          <tr>
            <th>Cracker Name</th>
            <th>Rate (<?php echo $DISC_PCT; ?>% OFF)</th>
            <th style="width:65px;">Qty</th>
            <th style="width:75px;">Total</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $p): ?>
            <tr>
              <td><strong><?php echo htmlspecialchars($p['name']); ?></strong></td>
              <td>
                <span class="mrp">₹<?php echo $p['mrp']; ?></span>
                <span class="price">₹<?php echo $p['price']; ?></span>
              </td>
              <td>
                <input type="number" min="0" value="0" class="qty-inp"
                       data-id="<?php echo $p['id']; ?>"
                       data-price="<?php echo $p['price']; ?>"
                       oninput="calcTotal()">
              </td>
              <td id="sub-<?php echo $p['id']; ?>">₹0</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endforeach; ?>
  </div>

  <div class="sticky-foot">
    <div>
      <div style="font-size:12px;color:#ccc;">Items: <strong id="t-items">0</strong></div>
      <div style="font-size:18px;font-weight:bold;color:var(--accent);">Net: ₹<span id="t-amt">0</span></div>
    </div>
    <button class="btn-order" onclick="openCheckout()">Confirm Estimate →</button>
  </div>

  <div class="modal" id="checkoutModal">
    <div class="modal-box">
      <h3 style="margin-bottom:10px;color:var(--primary);">Submit Quick Estimate</h3>
      <p id="minWarn" style="color:#c92a2a;font-size:12px;font-weight:bold;margin-bottom:8px;display:none;">
        Minimum order is ₹<?php echo $MIN_ORDER; ?>/-
      </p>
      <form method="POST" onsubmit="return grandTotal >= MIN_ORDER">
        <input type="hidden" name="order_data" id="orderData">
        <input type="text" name="cust_name" id="cname" class="inp" placeholder="Your Full Name *" required>
        <input type="tel" name="cust_phone" id="cphone" class="inp" placeholder="Mobile Number (for Tracking) *" required>
        <input type="text" name="cust_city" id="ccity" class="inp" placeholder="Delivery City / Town *" required>
        <button type="submit" id="subBtn" class="btn-order" style="width:100%;margin-bottom:6px;">Submit Order</button>
        <button type="button" onclick="printDraftPDF()" style="width:100%;background:#1d1e22;color:#fff;border:none;padding:9px;border-radius:6px;font-weight:bold;cursor:pointer;margin-bottom:6px;">📄 Save / Print PDF Bill</button>
        <button type="button" onclick="document.getElementById('checkoutModal').style.display='none'" style="width:100%;padding:8px;border:none;background:#eee;border-radius:5px;font-weight:bold;">Cancel</button>
      </form>
    </div>
  </div>

  <?php if ($submitted_order): ?>
  <div id="successModal" style="position:fixed;inset:0;background:rgba(0,0,0,0.65);display:flex;align-items:center;justify-content:center;z-index:300;padding:16px;">
    <div style="background:#fff;max-width:400px;width:100%;border-radius:12px;padding:24px;text-align:center;box-shadow:0 10px 25px rgba(0,0,0,0.2);">
      <div style="font-size:46px;margin-bottom:8px;">✅</div>
      <h2 style="color:#2b9348;font-size:20px;margin-bottom:8px;">Your order submitted. We will contact you soon.</h2>
      <p style="font-size:14px;color:#333;margin-bottom:6px;">Order Tracking ID: <strong style="color:#d90429;font-size:16px;"><?php echo $submitted_order['code']; ?></strong></p>
      <p style="font-size:13px;color:#666;margin-bottom:18px;">Total Amount: <strong>₹<?php echo number_format($submitted_order['total']); ?></strong></p>
      <button type="button" onclick="printSubmittedPDF()" style="width:100%;background:#1d1e22;color:#fff;border:none;padding:12px;border-radius:6px;font-size:15px;font-weight:bold;cursor:pointer;margin-bottom:8px;">📄 Save PDF</button>
      <button type="button" onclick="document.getElementById('successModal').style.display='none'" style="width:100%;background:#eee;color:#333;border:none;padding:10px;border-radius:6px;font-size:14px;font-weight:bold;cursor:pointer;">Close</button>
    </div>
  </div>
  <?php endif; ?>

  <script>
    const MIN_ORDER = <?php echo $MIN_ORDER; ?>;
    const DISC_PCT = <?php echo $DISC_PCT; ?>;
    const items = <?php echo json_encode($products); ?>;
    const CFG = <?php echo json_encode($cfg); ?>;
    const savedOrder = <?php echo json_encode($submitted_order); ?>;
    let cart = {}, grandTotal = 0;

    function calcTotal() {
      cart = {}; grandTotal = 0; let count = 0;
      document.querySelectorAll('.qty-inp').forEach(inp => {
        const id = inp.dataset.id, price = parseInt(inp.dataset.price), qty = parseInt(inp.value) || 0, sub = price * qty;
        document.getElementById('sub-' + id).innerText = '₹' + sub;
        if (qty > 0) { cart[id] = qty; grandTotal += sub; count += qty; }
      });
      document.getElementById('t-items').innerText = count;
      document.getElementById('t-amt').innerText = grandTotal;
    }

    function openCheckout() {
      document.getElementById('orderData').value = JSON.stringify(cart);
      const warn = document.getElementById('minWarn'), btn = document.getElementById('subBtn');
      warn.style.display = grandTotal < MIN_ORDER ? 'block' : 'none';
      btn.disabled = grandTotal < MIN_ORDER;
      btn.style.background = grandTotal < MIN_ORDER ? '#aaa' : 'var(--primary)';
      document.getElementById('checkoutModal').style.display = 'flex';
    }

    function buildEstimateHTML(meta) {
      let rows = "", tot = 0;
      meta.items.forEach((it, i) => {
        const disc = DISC_PCT > 0 ? DISC_PCT : (it.mrp > 0 ? Math.round(((it.mrp - it.price) / it.mrp) * 100) : 0);
        tot += it.sub;
        rows += `<tr><td style="text-align:center;">${i + 1}</td><td style="text-align:center;">${it.id}</td><td>${it.name}</td><td style="text-align:center;">${it.qty}</td><td style="text-align:right;">${it.mrp.toFixed(2)}</td><td style="text-align:center;">${disc}%</td><td style="text-align:right;">${it.price.toFixed(2)}</td><td style="text-align:right;">${it.sub.toFixed(2)}</td></tr>`;
      });
      return `<!DOCTYPE html><html><head><title>Estimate ${meta.code}</title>
      <style>
        body { font-family: Arial, sans-serif; padding: 14px; color: #000; font-size: 12px; }
        .wrap { border: 1.5px solid #000; }
        .row-flex { display: flex; justify-content: space-between; padding: 6px 8px; border-bottom: 1px solid #000; }
        .shop-box { text-align: center; padding: 8px; border-bottom: 1px solid #000; }
        .shop-box h2 { margin: 0 0 3px; font-size: 16px; }
        .cust-bank { display: flex; border-bottom: 1px solid #000; }
        .cust-col { width: 52%; padding: 8px; border-right: 1px solid #000; line-height: 1.5; }
        .bank-col { width: 48%; padding: 8px; line-height: 1.5; }
        .bank-table td { padding: 1px 4px; border: none; font-size: 11.5px; }
        table.items { width: 100%; border-collapse: collapse; }
        table.items th, table.items td { border: 1px solid #000; padding: 5px 6px; font-size: 11.5px; }
        table.items th { background: #f2f2f2; }
        .tot-row td { font-weight: bold; }
      </style></head><body>
      <div class="wrap">
        <div class="row-flex"><span><b>Enquiry No :</b> ${meta.code}</span><strong style="font-size:14px;">ESTIMATE</strong><span><b>Date :</b> ${meta.date}</span></div>
        <div class="row-flex"><span><b>Mobile : +91 79047 85247</b></span><span><b>E-mail : ${CFG.email}</b></span></div>
        <div class="shop-box"><h2><?php echo $SHOP_NAME; ?></h2><div>${CFG.address}</div></div>
        <div class="cust-bank">
          <div class="cust-col"><strong>Customer Details</strong><br>${meta.name}<br>${meta.phone}<br>${meta.city}</div>
          <div class="bank-col"><table class="bank-table">
            <tr><td><b>A/C Name</b></td><td>: ${CFG.ac_name}</td></tr>
            <tr><td><b>A/C Number</b></td><td>: ${CFG.ac_no}</td></tr>
            <tr><td><b>A/C Type</b></td><td>: ${CFG.ac_type}</td></tr>
            <tr><td><b>Bank Name</b></td><td>: ${CFG.bank_name}</td></tr>
            <tr><td><b>IFSC Code</b></td><td>: ${CFG.ifsc}</td></tr>
            <tr><td><b>UPI ID</b></td><td>: ${CFG.upi}</td></tr>
          </table></div>
        </div>
        <table class="items">
          <thead><tr><th>S.No</th><th>Code</th><th>Product Name</th><th>Qty</th><th>MRP</th><th>Disc %</th><th>Rate</th><th>Amount</th></tr></thead>
          <tbody>${rows}
            <tr class="tot-row"><td colspan="6">Total Items : ${meta.items.length}</td><td style="text-align:right;">Net Total</td><td style="text-align:right;">${tot.toFixed(2)}</td></tr>
            <tr class="tot-row"><td colspan="6"></td><td style="text-align:right;">Overall Total</td><td style="text-align:right;">${tot.toFixed(2)}</td></tr>
          </tbody>
        </table>
      </div>
      <script>window.onload = function() { window.print(); };<\/script></body></html>`;
    }

    function openPrintWindow(html) { const w = window.open("", "_blank"); w.document.write(html); w.document.close(); }

    function printDraftPDF() {
      const list = [];
      Object.keys(cart).forEach(id => {
        const p = items.find(x => x.id == id);
        list.push({ id: p.id, name: p.name, mrp: p.mrp, price: p.price, qty: cart[id], sub: p.price * cart[id] });
      });
      if (!list.length) return alert("Add items first!");
      const d = new Date(), dd = String(d.getDate()).padStart(2,'0')+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+d.getFullYear();
      openPrintWindow(buildEstimateHTML({ code: "DRAFT", date: dd, name: document.getElementById("cname").value || "Customer", phone: document.getElementById("cphone").value || "-", city: document.getElementById("ccity").value || "-", items: list }));
    }

    function printSubmittedPDF() { if (savedOrder) openPrintWindow(buildEstimateHTML(savedOrder)); }
  </script>
</body>
</html>
