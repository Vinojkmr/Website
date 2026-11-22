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
$res = $conn->query("SELECT * FROM products WHERE cat LIKE '%Gift%' OR cat LIKE '%Combo%' ORDER BY id");
if ($res && $res->num_rows === 0) {
    $res = $conn->query("SELECT * FROM products ORDER BY id");
}
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

    $msg = "*{$SHOP_NAME} Combo Estimate ({$order_code})*\n*Name:* {$name}\n*Phone:* {$phone}\n*City:* {$city}\n\n";
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Combo & Gift Boxes | <?php echo $SHOP_NAME; ?></title>
  <style>
    :root { --primary: #d90429; --accent: #ffb703; --bg: #f8f9fa; --border: #e0e0e0; }
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: sans-serif; }
    body { background: var(--bg); padding-bottom: 70px; }
    .top-bar { background: #1d1e22; color: #fff; font-size: 12px; padding: 8px 4%; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 6px; }
    .top-bar strong { color: var(--accent); }
    header { background: linear-gradient(90deg, #780015, #d90429); color: #fff; padding: 12px 4%; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
    .logo-wrap { display: flex; align-items: center; gap: 10px; text-decoration: none; color: #fff; }
    .logo-wrap h1 { font-size: 20px; color: var(--accent); }
    .nav-bar { background: #2b0509; padding: 10px 4%; display: flex; gap: 16px; overflow-x: auto; }
    .nav-bar a { color: #fff; text-decoration: none; font-size: 13px; font-weight: bold; white-space: nowrap; }
    .nav-bar a:hover, .nav-bar a.active { color: var(--accent); }
    .cart-btn { background: var(--accent); color: #000; border: none; padding: 9px 14px; border-radius: 6px; font-weight: bold; cursor: pointer; }
    .container { max-width: 1100px; margin: 18px auto; padding: 0 4%; }
    .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 14px; }
    .card { background: #fff; border: 1px solid var(--border); border-radius: 10px; padding: 14px; position: relative; display: flex; flex-direction: column; justify-content: space-between; }
    .tag { position: absolute; top: 10px; left: 10px; background: #2b9348; color: #fff; font-size: 11px; font-weight: bold; padding: 3px 7px; border-radius: 4px; }
    .icon { height: 80px; background: #fff3f3; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 38px; margin-bottom: 8px; }
    .net { font-size: 18px; font-weight: bold; color: var(--primary); }
    .mrp { font-size: 13px; text-decoration: line-through; color: #888; margin-left: 6px; }
    .qty { display: flex; justify-content: space-between; align-items: center; border: 1px solid var(--border); border-radius: 6px; margin-top: 10px; }
    .qty button { background: #f1f3f5; border: none; width: 36px; height: 34px; font-size: 18px; font-weight: bold; cursor: pointer; }
    .overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: none; z-index: 200; }
    .drawer { position: fixed; top: 0; right: -100%; width: 100%; max-width: 380px; height: 100%; background: #fff; z-index: 201; transition: 0.3s; display: flex; flex-direction: column; }
    .drawer.open { right: 0; }
    .d-head { background: var(--primary); color: #fff; padding: 14px; display: flex; justify-content: space-between; align-items: center; }
    .d-body { flex: 1; overflow-y: auto; padding: 14px; }
    .c-item { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--border); font-size: 13px; }
    .d-foot { padding: 14px; border-top: 1px solid var(--border); background: #f8f9fa; }
    .warn { background: #ffe3e3; color: #c92a2a; padding: 8px; border-radius: 5px; font-size: 12px; margin-bottom: 8px; font-weight: bold; }
    .inp { width: 100%; padding: 8px; margin-bottom: 8px; border: 1px solid var(--border); border-radius: 5px; }
    .sub-btn { width: 100%; background: var(--primary); color: #fff; border: none; padding: 11px; border-radius: 6px; font-size: 15px; font-weight: bold; cursor: pointer; margin-bottom: 6px; }
    .sub-btn:disabled { background: #adb5bd; }
    .pdf-btn { width: 100%; background: #1d1e22; color: #fff; border: none; padding: 9px; border-radius: 6px; font-size: 14px; font-weight: bold; cursor: pointer; }
  </style>
</head>
<body>

  <div class="top-bar">
    <span>📞 Phone: <strong>+91 79047 85247</strong></span>
    <span>🚚 Min Order: <strong>₹<?php echo number_format($MIN_ORDER); ?>/-</strong></span>
  </div>

  <header>
    <a href="index.php" class="logo-wrap">
      <div><h1><?php echo $SHOP_NAME; ?></h1><small>Family Gift Boxes & Combos</small></div>
    </a>
    <button class="cart-btn" onclick="toggleCart()">🛒 (<span id="cnt">0</span>) ₹<span id="htot">0</span></button>
  </header>

  <nav class="nav-bar">
    <a href="index.php">Home</a>
    <a href="quick-shop.php">Quick Shopping</a>
    <a href="combo.php" class="active">Combo Products</a>
    <a href="track-order.php">Track Order</a>
    <a href="about.php">About Us</a>
    <a href="contact.php">Contact</a>
  </nav>

  <main class="container">
    <h2 style="margin-bottom:14px;color:#2b0509;">🎁 Diwali Gift Boxes & Combos (Flat <?php echo $DISC_PCT; ?>% OFF)</h2>
    <div class="grid">
      <?php foreach ($products as $p): $off = $DISC_PCT > 0 ? $DISC_PCT : ($p['mrp'] > 0 ? round((($p['mrp'] - $p['price']) / $p['mrp']) * 100) : 0); ?>
      <div class="card">
        <span class="tag"><?php echo $off; ?>% OFF</span>
        <div>
          <div class="icon"><?php echo $p['icon']; ?></div>
          <small style="color:#666;font-weight:bold;"><?php echo strtoupper(htmlspecialchars($p['cat'])); ?></small>
          <h3 style="font-size:15px;margin:4px 0 8px;"><?php echo htmlspecialchars($p['name']); ?></h3>
        </div>
        <div>
          <div><span class="net">₹<?php echo $p['price']; ?></span><span class="mrp">₹<?php echo $p['mrp']; ?></span></div>
          <div class="qty">
            <button type="button" onclick="qty(<?php echo $p['id']; ?>, -1)">−</button>
            <span id="q-<?php echo $p['id']; ?>">0</span>
            <button type="button" onclick="qty(<?php echo $p['id']; ?>, 1)">+</button>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </main>

  <div class="overlay" id="ov" onclick="toggleCart()"></div>
  <aside class="drawer" id="drw">
    <div class="d-head">
      <h3>Combo Estimate</h3>
      <button type="button" onclick="toggleCart()" style="background:none;border:none;color:#fff;font-size:24px;">×</button>
    </div>
    <div class="d-body" id="citems"></div>
    <form method="POST" class="d-foot">
      <div style="display:flex;justify-content:space-between;font-weight:bold;font-size:17px;margin-bottom:8px;">
        <span>Total:</span><span style="color:var(--primary);">₹<span id="dtot">0</span></span>
      </div>
      <div id="warn" class="warn">⚠️ Min order ₹<?php echo $MIN_ORDER; ?>. Add ₹<span id="rem"><?php echo $MIN_ORDER; ?></span> more.</div>
      <input type="hidden" name="order_data" id="order_data" />
      <input type="text" name="cust_name" id="cname" class="inp" placeholder="Your Name *" required />
      <input type="tel" name="cust_phone" id="cphone" class="inp" placeholder="Mobile Number (for Tracking) *" required />
      <input type="text" name="cust_city" id="ccity" class="inp" placeholder="Delivery City *" required />
      <button type="submit" id="sbtn" class="sub-btn" disabled>Submit Order</button>
      <button type="button" class="pdf-btn" onclick="printDraftPDF()">📄 Save / Print PDF Bill</button>
    </form>
  </aside>

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
    const MIN = <?php echo $MIN_ORDER; ?>;
    const DISC_PCT = <?php echo $DISC_PCT; ?>;
    const items = <?php echo json_encode($products); ?>;
    const CFG = <?php echo json_encode($cfg); ?>;
    const savedOrder = <?php echo json_encode($submitted_order); ?>;
    let cart = {};

    function qty(id, d) {
      cart[id] = (cart[id] || 0) + d;
      if (cart[id] <= 0) delete cart[id];
      document.getElementById("q-" + id).innerText = cart[id] || 0;
      updateCart();
    }

    function updateCart() {
      let c = 0, t = 0, box = document.getElementById("citems");
      box.innerHTML = "";
      Object.keys(cart).forEach(id => {
        const p = items.find(x => x.id == id), sub = p.price * cart[id];
        c += cart[id]; t += sub;
        box.innerHTML += `<div class="c-item"><div><strong>${p.name}</strong><br><small>₹${p.price} × ${cart[id]}</small></div><b>₹${sub}</b></div>`;
      });
      if (!c) box.innerHTML = `<p style="text-align:center;color:#777;margin-top:30px;">Estimate list is empty.</p>`;
      document.getElementById("cnt").innerText = c;
      document.getElementById("htot").innerText = t;
      document.getElementById("dtot").innerText = t;
      document.getElementById("warn").style.display = t >= MIN ? "none" : "block";
      document.getElementById("rem").innerText = MIN - t;
      document.getElementById("sbtn").disabled = t < MIN;
      document.getElementById("order_data").value = JSON.stringify(cart);
    }

    function toggleCart() {
      const d = document.getElementById("drw"), o = document.getElementById("ov");
      o.style.display = d.classList.toggle("open") ? "block" : "none";
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
    updateCart();
  </script>
</body>
</html>
