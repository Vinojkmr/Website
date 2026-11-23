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
        if (in_array($pid, $disabled_ids, true)) {
            continue;
        }
        $row['id'] = $pid;
        $row['mrp'] = (int)$row['mrp'];
        $row['price'] = $DISC_PCT > 0 ? (int)round($row['mrp'] - ($row['mrp'] * $DISC_PCT / 100)) : (int)$row['price'];
        $products[] = $row;
    }
}

$submitted_order = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_data'])) {
    $name    = htmlspecialchars(trim($_POST['cust_name']));
    $phone   = preg_replace('/[^0-9]/', '', trim($_POST['cust_phone']));
    $email   = htmlspecialchars(trim($_POST['cust_email'] ?? ''));
    $state   = htmlspecialchars(trim($_POST['cust_state'] ?? 'Tamil Nadu'));
    $city    = htmlspecialchars(trim($_POST['cust_city']));
    $address = htmlspecialchars(trim($_POST['cust_address'] ?? ''));
    $cart    = json_decode($_POST['order_data'], true);

    $yr = date("y");
    $prefix = "VK" . $yr;
    $cnt_res = $conn->query("SELECT COUNT(*) AS total FROM orders WHERE order_code LIKE '{$prefix}%'");
    $next_num = ($cnt_res && ($r = $cnt_res->fetch_assoc())) ? ((int)$r['total'] + 1) : 1;
    $order_code = $prefix . str_pad($next_num, 4, '0', STR_PAD_LEFT);

    $full_location = trim("{$city}, {$state}" . ($address ? " - {$address}" : ""));
    $msg = "*{$SHOP_NAME} Quick Estimate ({$order_code})*\n*Name:* {$name}\n*Phone:* {$phone}\n*Location:* {$full_location}\n\n";
    $items_summary = "";
    $pdf_items = [];
    $sub_total = 0;
    $mrp_total = 0;
    $i = 1;

    foreach ($products as $p) {
        $id = (string)$p['id'];
        if (!empty($cart[$id]) && (int)$cart[$id] > 0) {
            $q = (int)$cart[$id];
            $sub = $p['price'] * $q;
            $sub_total += $sub;
            $mrp_total += ($p['mrp'] * $q);
            $line = "{$i}. {$p['name']} x {$q} = ₹{$sub}\n";
            $msg .= $line;
            $items_summary .= $line;
            $pdf_items[] = [
                'id'    => $p['id'],
                'name'  => $p['name'],
                'mrp'   => $p['mrp'],
                'price' => $p['price'],
                'qty'   => $q,
                'sub'   => $sub
            ];
            $i++;
        }
    }

    $packing_chg = round($sub_total * 0.015, 2);
    $raw_total   = $sub_total + $packing_chg;
    $final_total = (int)round($raw_total);
    $round_off   = round($final_total - $raw_total, 2);

    $items_summary .= "Packing Charges (1.5%): +₹" . number_format($packing_chg, 2) . "\n";
    if ($round_off != 0) {
        $items_summary .= "Round Off: " . ($round_off >= 0 ? "+" : "") . number_format($round_off, 2) . "\n";
    }
    $msg .= "\n*Sub Total: ₹{$sub_total}*\n*Packing (1.5%): ₹{$packing_chg}*\n*Overall Total: ₹{$final_total}*\nTrack Order ID: *{$order_code}*";

    $stmt = $conn->prepare("INSERT INTO orders (order_code, cust_name, cust_phone, cust_city, items_text, total_amount, status, tracking_info) VALUES (?, ?, ?, ?, ?, ?, 'Pending Confirmation', 'Awaiting phone confirmation')");
    if ($stmt) {
        $stmt->bind_param("sssssi", $order_code, $name, $phone, $full_location, $items_summary, $final_total);
        $stmt->execute();
    }

    file_put_contents("enquiries.txt", "[" . date("Y-m-d H:i:s") . "] {$order_code} - {$name} ({$phone}, {$full_location}) ₹{$final_total}\n{$msg}\n---\n", FILE_APPEND);

    $submitted_order = [
        'code'        => $order_code,
        'date'        => date("d-m-Y"),
        'name'        => $name,
        'phone'       => $phone,
        'city'        => $full_location,
        'items'       => $pdf_items,
        'sub_total'   => $sub_total,
        'packing_chg' => $packing_chg,
        'round_off'   => $round_off,
        'total'       => $final_total
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
  <title>Quick Price List | <?php echo $SHOP_NAME; ?></title>
  <style>
    :root { --primary: #d90429; --dark: #1d1e22; --accent: #ffb703; --bg: #f4f6f9; --border: #dee2e6; }
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Arial, sans-serif; }
    body { background: var(--bg); padding-bottom: 90px; color: #222; }
    .top-bar { background: var(--dark); color: #fff; font-size: 12px; padding: 7px 4%; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 6px; }
    .top-bar strong { color: var(--accent); }
    header { background: linear-gradient(90deg, #780015, #d90429); color: #fff; padding: 12px 4%; display: flex; justify-content: space-between; align-items: center; }
    .logo-wrap { text-decoration: none; color: #fff; }
    .logo-wrap h1 { font-size: 20px; color: var(--accent); }
    .nav-bar { background: #2b0509; padding: 10px 4%; display: flex; gap: 16px; overflow-x: auto; }
    .nav-bar a { color: #fff; text-decoration: none; font-size: 13px; font-weight: 600; white-space: nowrap; }
    .nav-bar a:hover, .nav-bar a.active { color: var(--accent); }

    /* Sticky Live Summary Bar */
    .sticky-summary { position: sticky; top: 0; z-index: 120; background: #fff; border-bottom: 2px solid var(--primary); box-shadow: 0 3px 10px rgba(0,0,0,0.1); padding: 8px 4%; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
    .sum-pills { display: flex; gap: 14px; flex-wrap: wrap; font-size: 13px; }
    .sum-box { background: #f8f9fa; border: 1px solid var(--border); padding: 5px 10px; border-radius: 6px; }
    .sum-box span { color: #555; font-size: 11px; display: block; }
    .sum-box strong { font-size: 15px; }
    .save-txt { color: #2b8a3e; }
    .tot-txt { color: var(--primary); }

    /* Search & Category Jump Bar */
    .filter-bar { max-width: 1100px; margin: 14px auto 6px; padding: 0 3%; }
    .search-inp { width: 100%; padding: 10px 12px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; margin-bottom: 10px; }
    .cat-pills { display: flex; gap: 6px; overflow-x: auto; padding-bottom: 6px; }
    .cat-pill { background: #fff; border: 1px solid #ccc; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; text-decoration: none; color: #333; white-space: nowrap; }
    .cat-pill:hover { background: var(--primary); color: #fff; border-color: var(--primary); }

    .container { max-width: 1100px; margin: 0 auto; padding: 0 3%; }
    .cat-banner { background: linear-gradient(90deg, #2b0509, #780015); color: #fff; padding: 10px 14px; font-weight: bold; font-size: 14px; border-radius: 6px 6px 0 0; margin-top: 18px; display: flex; justify-content: space-between; align-items: center; scroll-margin-top: 75px; }
    .disc-badge { background: var(--accent); color: #000; padding: 2px 8px; border-radius: 4px; font-size: 12px; }

    table.qtable { width: 100%; border-collapse: collapse; background: #fff; font-size: 13px; box-shadow: 0 1px 4px rgba(0,0,0,0.05); }
    table.qtable th, table.qtable td { border: 1px solid var(--border); padding: 8px 6px; }
    table.qtable th { background: #f1f3f5; font-size: 12px; text-align: center; }
    .center { text-align: center; }
    .right { text-align: right; }
    .mrp { text-decoration: line-through; color: #888; font-size: 12px; }
    .price { color: var(--primary); font-weight: bold; font-size: 14px; }
    .qty-inp { width: 58px; padding: 6px; border: 1.5px solid #adb5bd; border-radius: 4px; text-align: center; font-weight: bold; font-size: 14px; }
    .qty-inp:focus { border-color: var(--primary); outline: none; background: #fff5f5; }

    .sticky-foot { position: fixed; bottom: 0; left: 0; right: 0; background: var(--dark); color: #fff; padding: 10px 4%; display: flex; justify-content: space-between; align-items: center; z-index: 150; }
    .btn-order { background: var(--primary); color: #fff; border: none; padding: 10px 18px; border-radius: 6px; font-weight: bold; font-size: 14px; cursor: pointer; }
    .btn-order:disabled { background: #6c757d; cursor: not-allowed; }

    /* Modal & OTP */
    .modal { position: fixed; inset: 0; background: rgba(0,0,0,0.65); display: none; align-items: center; justify-content: center; z-index: 250; padding: 14px; overflow-y: auto; }
    .modal-box { background: #fff; padding: 20px; border-radius: 10px; width: 100%; max-width: 440px; }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .inp { width: 100%; padding: 9px; margin-bottom: 8px; border: 1px solid #ccc; border-radius: 5px; font-size: 13px; }
    .bill-break { background: #f8f9fa; border: 1px solid var(--border); border-radius: 6px; padding: 10px; margin: 8px 0 12px; font-size: 13px; }
    .bill-row { display: flex; justify-content: space-between; margin-bottom: 4px; }
    .bill-row.grand { border-top: 1px dashed #aaa; padding-top: 6px; margin-top: 6px; font-weight: bold; font-size: 15px; color: var(--primary); }
    .legal-note { margin-top: 22px; background: #fff8e6; border: 1px solid #ffe082; padding: 14px; border-radius: 8px; font-size: 12px; line-height: 1.5; color: #5c4500; }
  </style>
</head>
<body>

  <div class="top-bar">
    <span>📞 Enquiry & WhatsApp: <strong><?php echo $PHONE_DISPLAY ?? '+91 79047 85247'; ?></strong></span>
    <span>🚚 Sivakasi Direct Dispatch • License: <strong><?php echo $LICENSE_NO ?? '2026'; ?></strong></span>
  </div>

  <header>
    <a href="index.php" class="logo-wrap">
      <h1><?php echo $SHOP_NAME; ?></h1>
      <small>Sivakasi Direct Quick Price List</small>
    </a>
    <span class="disc-badge">FLAT <?php echo $DISC_PCT; ?>% DISCOUNT</span>
  </header>

  <nav class="nav-bar">
    <a href="index.php">Home</a>
    <a href="quick-shop.php" class="active">Quick Shopping</a>
    <a href="combo.php">Combo Products</a>
    <a href="track-order.php">Track Order</a>
    <a href="about.php">About Us</a>
    <a href="contact.php">Contact</a>
  </nav>

  <div class="sticky-summary">
    <div class="sum-pills">
      <div class="sum-box"><span>Items</span><strong id="top-items">0</strong></div>
      <div class="sum-box"><span>Net Total (MRP)</span><strong>₹<span id="top-mrp">0</span></strong></div>
      <div class="sum-box"><span>You Save</span><strong class="save-txt">₹<span id="top-save">0</span></strong></div>
      <div class="sum-box"><span>Overall Total</span><strong class="tot-txt">₹<span id="top-net">0</span></strong></div>
    </div>
    <button class="btn-order" onclick="openCheckout()">Confirm Estimate →</button>
  </div>

  <div class="filter-bar">
    <input type="text" id="srchBox" class="search-inp" placeholder="🔍 Search cracker name or code..." onkeyup="filterTable()">
    <div class="cat-pills">
      <?php foreach (array_keys($grouped) as $idx => $cat): ?>
        <a href="#cat-<?php echo $idx; ?>" class="cat-pill"><?php echo htmlspecialchars($cat); ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="container">
    <?php $c_idx = 0; foreach ($grouped as $cat => $items): ?>
      <div class="cat-section" id="cat-<?php echo $c_idx++; ?>">
        <div class="cat-banner">
          <span><?php echo htmlspecialchars($cat); ?></span>
          <span class="disc-badge"><?php echo $DISC_PCT; ?>% Discount</span>
        </div>
        <table class="qtable">
          <thead>
            <tr>
              <th style="width:45px;">Icon</th>
              <th style="width:55px;">Code</th>
              <th style="text-align:left;">Product Name</th>
              <th style="width:80px;">Actual Price</th>
              <th style="width:80px;">Offer Price</th>
              <th style="width:70px;">Quantity</th>
              <th style="width:85px;">Total</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $p): ?>
              <tr class="prod-row" data-search="<?php echo strtolower(htmlspecialchars($p['id'].' '.$p['name'].' '.$cat)); ?>">
                <td class="center" style="font-size:20px;"><?php echo $p['icon']; ?></td>
                <td class="center"><strong>#<?php echo $p['id']; ?></strong></td>
                <td><strong><?php echo htmlspecialchars($p['name']); ?></strong></td>
                <td class="right"><span class="mrp">₹<?php echo $p['mrp']; ?></span></td>
                <td class="right"><span class="price">₹<?php echo $p['price']; ?></span></td>
                <td class="center">
                  <input type="number" min="0" value="0" class="qty-inp"
                         data-id="<?php echo $p['id']; ?>"
                         data-mrp="<?php echo $p['mrp']; ?>"
                         data-price="<?php echo $p['price']; ?>"
                         oninput="calcTotal()">
                </td>
                <td class="right"><strong id="sub-<?php echo $p['id']; ?>">₹0</strong></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endforeach; ?>

    <div class="legal-note">
      <strong>Legal & Safety Notice:</strong> As per the 2018 Supreme Court order, direct online sale of firecrackers is not permitted. Add your required items and submit an estimate enquiry. Our team will contact you within 24 hours via phone/WhatsApp to confirm stock and legal parcel transport.
    </div>
  </div>

  <div class="sticky-foot">
    <div style="font-size:13px;">
      Min. Order: <strong>₹<span id="foot-min"><?php echo $MIN_ORDER; ?></span></strong> |
      Payable: <strong style="color:var(--accent);font-size:17px;">₹<span id="foot-net">0</span></strong>
    </div>
    <button class="btn-order" onclick="openCheckout()">Confirm Estimate →</button>
  </div>

  <div class="modal" id="checkoutModal">
    <div class="modal-box">
      <h3 style="color:var(--primary);margin-bottom:8px;">Confirm Your Estimate</h3>
      <div id="minWarn" style="background:#ffe3e3;color:#c92a2a;padding:8px;border-radius:5px;font-size:12px;font-weight:bold;margin-bottom:8px;display:none;">
        ⚠️ Minimum order for selected state is ₹<span id="warnMinVal"><?php echo $MIN_ORDER; ?></span>.
      </div>

      <form method="POST" id="estForm">
        <input type="hidden" name="order_data" id="orderData">
        <div class="grid-2">
          <select name="cust_state" id="cstate" class="inp" onchange="recalcModal()" required>
            <option value="Tamil Nadu" data-min="<?php echo $MIN_ORDER; ?>">Tamil Nadu (Min ₹<?php echo $MIN_ORDER; ?>)</option>
            <option value="Pondicherry" data-min="<?php echo $MIN_ORDER; ?>">Pondicherry (Min ₹<?php echo $MIN_ORDER; ?>)</option>
            <option value="Karnataka" data-min="<?php echo max($MIN_ORDER, 5000); ?>">Karnataka (Min ₹<?php echo max($MIN_ORDER, 5000); ?>)</option>
            <option value="Kerala" data-min="<?php echo max($MIN_ORDER, 5000); ?>">Kerala (Min ₹<?php echo max($MIN_ORDER, 5000); ?>)</option>
            <option value="Andhra / Telangana" data-min="<?php echo max($MIN_ORDER, 5000); ?>">Andhra / Telangana (Min ₹<?php echo max($MIN_ORDER, 5000); ?>)</option>
            <option value="Other States" data-min="<?php echo max($MIN_ORDER, 7500); ?>">Other States (Min ₹<?php echo max($MIN_ORDER, 7500); ?>)</option>
          </select>
          <input type="text" name="cust_city" id="ccity" class="inp" placeholder="City / Town *" required>
        </div>
        <div class="grid-2">
          <input type="text" name="cust_name" id="cname" class="inp" placeholder="Full Name *" required>
          <input type="tel" name="cust_phone" id="cphone" class="inp" placeholder="10-Digit Mobile *" pattern="[0-9]{10}" required>
        </div>
        <input type="email" name="cust_email" id="cemail" class="inp" placeholder="Email (Optional)">
        <input type="text" name="cust_address" id="caddr" class="inp" placeholder="Full Delivery Address *" required>

        <div class="bill-break">
          <div class="bill-row"><span>Sub Total:</span><strong>₹<span id="m-sub">0.00</span></strong></div>
          <div class="bill-row"><span>Packing Charges (1.5%):</span><strong>₹<span id="m-pack">0.00</span></strong></div>
          <div class="bill-row"><span>Round Off:</span><strong>₹<span id="m-round">0.00</span></strong></div>
          <div class="bill-row grand"><span>Overall Amount:</span><span>₹<span id="m-grand">0</span></span></div>
        </div>

        <button type="button" id="subBtn" class="btn-order" style="width:100%;margin-bottom:6px;" onclick="triggerOTP()">Verify Mobile & Submit</button>
        <button type="button" onclick="printDraftPDF()" style="width:100%;background:var(--dark);color:#fff;border:none;padding:9px;border-radius:6px;font-weight:bold;cursor:pointer;margin-bottom:6px;">📄 Save / Print PDF Estimate</button>
        <button type="button" onclick="document.getElementById('checkoutModal').style.display='none'" style="width:100%;padding:8px;border:none;background:#eee;border-radius:5px;font-weight:bold;cursor:pointer;">Cancel</button>
      </form>
    </div>
  </div>

  <div class="modal" id="otpModal">
    <div class="modal-box" style="text-align:center;">
      <h3 style="margin-bottom:6px;">📱 Mobile OTP Verification</h3>
      <p style="font-size:13px;color:#555;margin-bottom:10px;">Enter the 4-digit verification code for <strong id="otpPhoneDisp"></strong></p>
      <div id="demoOtpHint" style="background:#e7f5ff;color:#1864ab;padding:6px;border-radius:5px;font-size:12px;margin-bottom:10px;"></div>
      <input type="text" id="otpInput" class="inp" maxlength="4" placeholder="Enter 4-digit OTP" style="text-align:center;font-size:18px;letter-spacing:6px;font-weight:bold;">
      <button type="button" class="btn-order" style="width:100%;margin-bottom:6px;" onclick="verifyAndSubmit()">Confirm & Submit Estimate</button>
      <button type="button" onclick="document.getElementById('otpModal').style.display='none'" style="width:100%;padding:8px;border:none;background:#eee;border-radius:5px;font-weight:bold;">Back</button>
    </div>
  </div>

  <?php if ($submitted_order): ?>
  <div id="successModal" style="position:fixed;inset:0;background:rgba(0,0,0,0.65);display:flex;align-items:center;justify-content:center;z-index:300;padding:16px;">
    <div style="background:#fff;max-width:400px;width:100%;border-radius:12px;padding:24px;text-align:center;box-shadow:0 10px 25px rgba(0,0,0,0.2);">
      <div style="font-size:46px;margin-bottom:8px;">✅</div>
      <h2 style="color:#2b9348;font-size:20px;margin-bottom:8px;">Estimate Submitted! We will contact you soon.</h2>
      <p style="font-size:14px;color:#333;margin-bottom:6px;">Order Tracking ID: <strong style="color:#d90429;font-size:16px;"><?php echo $submitted_order['code']; ?></strong></p>
      <p style="font-size:13px;color:#666;margin-bottom:18px;">Overall Amount: <strong>₹<?php echo number_format($submitted_order['total']); ?></strong></p>
      <button type="button" onclick="printSubmittedPDF()" style="width:100%;background:#1d1e22;color:#fff;border:none;padding:12px;border-radius:6px;font-size:15px;font-weight:bold;cursor:pointer;margin-bottom:8px;">📄 Save / Print Estimate PDF</button>
      <button type="button" onclick="document.getElementById('successModal').style.display='none'" style="width:100%;background:#eee;color:#333;border:none;padding:10px;border-radius:6px;font-size:14px;font-weight:bold;cursor:pointer;">Close</button>
    </div>
  </div>
  <?php endif; ?>

  <script>
    const BASE_MIN = <?php echo $MIN_ORDER; ?>;
    const DISC_PCT = <?php echo $DISC_PCT; ?>;
    const items = <?php echo json_encode($products); ?>;
    const CFG = <?php echo json_encode($cfg); ?>;
    const savedOrder = <?php echo json_encode($submitted_order); ?>;
    let cart = {}, subTotal = 0, mrpTotal = 0, generatedOTP = "";

    function filterTable() {
      const q = document.getElementById('srchBox').value.toLowerCase().trim();
      document.querySelectorAll('.cat-section').forEach(sec => {
        let visible = 0;
        sec.querySelectorAll('.prod-row').forEach(tr => {
          const match = tr.dataset.search.includes(q);
          tr.style.display = match ? '' : 'none';
          if (match) visible++;
        });
        sec.style.display = visible > 0 ? '' : 'none';
      });
    }

    function calcTotal() {
      cart = {}; subTotal = 0; mrpTotal = 0; let count = 0;
      document.querySelectorAll('.qty-inp').forEach(inp => {
        const id = inp.dataset.id;
        const price = parseInt(inp.dataset.price);
        const mrp = parseInt(inp.dataset.mrp);
        const qty = Math.max(0, parseInt(inp.value) || 0);
        const sub = price * qty;
        document.getElementById('sub-' + id).innerText = '₹' + sub;
        if (qty > 0) {
          cart[id] = qty;
          subTotal += sub;
          mrpTotal += (mrp * qty);
          count += qty;
        }
      });
      const savings = Math.max(0, mrpTotal - subTotal);
      document.getElementById('top-items').innerText = count;
      document.getElementById('top-mrp').innerText = mrpTotal;
      document.getElementById('top-save').innerText = savings;
      document.getElementById('top-net').innerText = subTotal;
      document.getElementById('foot-net').innerText = subTotal;
    }

    function recalcModal() {
      const sel = document.getElementById('cstate');
      const stateMin = parseInt(sel.options[sel.selectedIndex].dataset.min) || BASE_MIN;
      const pack = +(subTotal * 0.015).toFixed(2);
      const raw = subTotal + pack;
      const grand = Math.round(raw);
      const rnd = +(grand - raw).toFixed(2);

      document.getElementById('foot-min').innerText = stateMin;
      document.getElementById('warnMinVal').innerText = stateMin;
      document.getElementById('m-sub').innerText = subTotal.toFixed(2);
      document.getElementById('m-pack').innerText = pack.toFixed(2);
      document.getElementById('m-round').innerText = (rnd >= 0 ? '+' : '') + rnd.toFixed(2);
      document.getElementById('m-grand').innerText = grand;

      const warn = document.getElementById('minWarn');
      const btn = document.getElementById('subBtn');
      warn.style.display = subTotal < stateMin ? 'block' : 'none';
      btn.disabled = subTotal < stateMin;
    }

    function openCheckout() {
      document.getElementById('orderData').value = JSON.stringify(cart);
      recalcModal();
      document.getElementById('checkoutModal').style.display = 'flex';
    }

    function triggerOTP() {
      const form = document.getElementById('estForm');
      if (!form.reportValidity()) return;
      const phone = document.getElementById('cphone').value.trim();
      generatedOTP = String(Math.floor(1000 + Math.random() * 9000));
      document.getElementById('otpPhoneDisp').innerText = "+91 " + phone;
      document.getElementById('demoOtpHint').innerText = "Verification Code: " + generatedOTP + " (Connect SMS gateway for live SMS)";
      document.getElementById('otpInput').value = "";
      document.getElementById('otpModal').style.display = 'flex';
    }

    function verifyAndSubmit() {
      if (document.getElementById('otpInput').value.trim() !== generatedOTP) {
        return alert("Invalid OTP! Please check and enter the 4-digit code.");
      }
      document.getElementById('estForm').submit();
    }

    function buildEstimateHTML(meta) {
      let rows = "", subTot = 0;
      meta.items.forEach((it, i) => {
        const disc = DISC_PCT > 0 ? DISC_PCT : (it.mrp > 0 ? Math.round(((it.mrp - it.price) / it.mrp) * 100) : 0);
        subTot += it.sub;
        rows += `<tr><td style="text-align:center;">${i + 1}</td><td style="text-align:center;">${it.id}</td><td>${it.name}</td><td style="text-align:center;">${it.qty}</td><td style="text-align:right;">${it.mrp.toFixed(2)}</td><td style="text-align:center;">${disc}%</td><td style="text-align:right;">${it.price.toFixed(2)}</td><td style="text-align:right;">${it.sub.toFixed(2)}</td></tr>`;
      });
      const pack = parseFloat(meta.packing_chg ?? (subTot * 0.015).toFixed(2));
      const grand = parseInt(meta.total ?? Math.round(subTot + pack));
      const rnd = +(grand - (subTot + pack)).toFixed(2);

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
        <div class="row-flex"><span><b>Mobile : <?php echo $PHONE_DISPLAY ?? '+91 79047 85247'; ?></b></span><span><b>E-mail : ${CFG.email}</b></span></div>
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
            <tr class="tot-row"><td colspan="6">Total Items : ${meta.items.length}</td><td style="text-align:right;">Sub Total</td><td style="text-align:right;">${subTot.toFixed(2)}</td></tr>
            <tr class="tot-row"><td colspan="6"></td><td style="text-align:right;">Packing (1.5%)</td><td style="text-align:right;">${pack.toFixed(2)}</td></tr>
            <tr class="tot-row"><td colspan="6"></td><td style="text-align:right;">Round Off</td><td style="text-align:right;">${rnd.toFixed(2)}</td></tr>
            <tr class="tot-row"><td colspan="6"></td><td style="text-align:right;">Overall Total</td><td style="text-align:right;">${grand.toFixed(2)}</td></tr>
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
      const loc = (document.getElementById("ccity").value || "-") + ", " + document.getElementById("cstate").value;
      openPrintWindow(buildEstimateHTML({
        code: "DRAFT",
        date: dd,
        name: document.getElementById("cname").value || "Customer",
        phone: document.getElementById("cphone").value || "-",
        city: loc,
        items: list
      }));
    }

    function printSubmittedPDF() { if (savedOrder) openPrintWindow(buildEstimateHTML(savedOrder)); }
  </script>
</body>
</html>
