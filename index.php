<?php
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();
require 'db.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$default_cfg = [
    'ac_name' => 'VK Crackers',
    'ac_no' => '000000000000',
    'ac_type' => 'Current',
    'bank_name' => 'Indian Overseas Bank',
    'ifsc' => 'IOBA0000000',
    'upi' => 'vkcrackers@upi',
    'email' => $EMAIL ?? 'vkcrackers@gmail.com',
    'address' => $ADDRESS ?? 'Sivakasi Main Road, Sivakasi, Tamil Nadu - 626189',
    'discount_pct' => 90,
    'min_order' => (isset($MIN_ORDER) ? (int)$MIN_ORDER : 3000),
    'disabled_ids' => []
];

$loaded_cfg = file_exists('settings.json') ? json_decode(file_get_contents('settings.json'), true) : [];
$cfg = is_array($loaded_cfg) ? array_merge($default_cfg, $loaded_cfg) : $default_cfg;
$MIN_ORDER = (int)$cfg['min_order'];
$DISC_PCT = (float)$cfg['discount_pct'];
$disabled_list = isset($cfg['disabled_ids']) && is_array($cfg['disabled_ids'])
    ? array_map('intval', $cfg['disabled_ids'])
    : [];
$default_banners = [
    [
        'pill' => '🎆 Sivakasi Factory Direct • {DISC}% OFF',
        'title' => 'Flat {DISC}% Diwali Factory Offer',
        'desc' => 'Select crackers below or use our Quick Price List to generate an instant PDF estimate & Order Tracking ID.',
        'btn_text' => '⚡ Open Quick Price List →',
        'btn_link' => 'quick-shop.php'
    ],
    [
        'pill' => '🎁 Ready-to-Celebrate Packs',
        'title' => 'Family Gift Boxes & Diwali Combos',
        'desc' => 'Curated 35-item & 50-item cracker assortments packed safely for families, kids, and corporate gifting at {DISC}% factory discount.',
        'btn_text' => '🎁 Explore Combo Packs →',
        'btn_link' => 'combo.php'
    ],
    [
        'pill' => '🚚 Min Order ₹{MIN}/- • Fast Dispatch',
        'title' => 'Instant Estimate & Live Lorry Tracking',
        'desc' => 'Submit your estimate in 60 seconds, get a phone confirmation within 2 hours, and track your transport LR details anytime.',
        'btn_text' => '📦 Track Your Order →',
        'btn_link' => 'track-order.php'
    ]
];
$banners = (!empty($cfg['banners']) && is_array($cfg['banners'])) ? $cfg['banners'] : $default_banners;
$banner_ms = max(2, min(30, (int)($cfg['banner_interval'] ?? 4))) * 1000;

function format_banner_txt(string $str, float $disc, int $min): string {
    return str_replace(['{DISC}', '{MIN}'], [(string)$disc, number_format($min)], $str);
}

$products = [];
$categories = [];
$res = $conn->query("SELECT * FROM products ORDER BY cat, id");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        if (in_array($row['id'], $disabled_list, true)) {
            continue;
        }
        $row['mrp'] = (int)$row['mrp'];
        $row['price'] = $DISC_PCT > 0
            ? (int)round($row['mrp'] - ($row['mrp'] * $DISC_PCT / 100))
            : (int)$row['price'];
        $products[] = $row;
        if (!in_array($row['cat'], $categories, true)) {
            $categories[] = $row['cat'];
        }
    }
}

$submitted_order = null;
$order_err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_data'])) {
    $token_ok = isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token']);

    if (!$token_ok) {
        $order_err = "Session expired. Please refresh the page and submit again.";
    } elseif (isset($_SESSION['last_order_time']) && (time() - $_SESSION['last_order_time']) < 15) {
        $order_err = "Please wait a few seconds before submitting another estimate.";
    } else {
        $name  = trim((string)($_POST['cust_name'] ?? ''));
        $phone = preg_replace('/[^0-9]/', '', trim((string)($_POST['cust_phone'] ?? '')));
        $city  = trim((string)($_POST['cust_city'] ?? ''));
        $cart  = json_decode((string)$_POST['order_data'], true);

        if ($name === '' || strlen($phone) !== 10 || $city === '' || !is_array($cart)) {
            $order_err = "Please enter a valid name, 10-digit mobile number, and delivery city.";
        } else {
            $items_summary = "";
            $pdf_items = [];
            $sub_total = 0;
            $i = 1;

            foreach ($products as $p) {
                $id = (string)$p['id'];
                if (!empty($cart[$id]) && (int)$cart[$id] > 0) {
                    $q = min(999, (int)$cart[$id]);
                    $sub = $p['price'] * $q;
                    $sub_total += $sub;
                    $items_summary .= "{$i}. {$p['name']} x {$q} = ₹{$sub}\n";
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

            if (empty($pdf_items) || $sub_total < $MIN_ORDER) {
                $order_err = "Minimum order amount is ₹" . number_format($MIN_ORDER) . ". Please add more items.";
            } else {
                $yr = date("y");
                $prefix = "VK" . $yr;
                $max_res = $conn->query("SELECT COALESCE(MAX(id), 0) AS max_id FROM orders");
                $next_num = ($max_res && ($r = $max_res->fetch_assoc())) ? ((int)$r['max_id'] + 1) : 1;
                $order_code = $prefix . str_pad((string)$next_num, 4, '0', STR_PAD_LEFT);

                $packing_chg = round($sub_total * 0.015, 2);
                $raw_total   = $sub_total + $packing_chg;
                $final_total = (int)round($raw_total);
                $round_off   = round($final_total - $raw_total, 2);

                $items_summary .= "Packing Charges (1.5%): +₹" . number_format($packing_chg, 2, '.', '') . "\n";
                if ($round_off != 0) {
                    $items_summary .= "Round Off: " . ($round_off >= 0 ? "+" : "") . number_format($round_off, 2, '.', '') . "\n";
                }

                $stmt = $conn->prepare("INSERT INTO orders (order_code, cust_name, cust_phone, cust_city, items_text, total_amount, status, tracking_info) VALUES (?, ?, ?, ?, ?, ?, 'Pending Confirmation', 'Awaiting phone confirmation')");
                if ($stmt) {
                    $stmt->bind_param("sssssi", $order_code, $name, $phone, $city, $items_summary, $final_total);
                    $stmt->execute();
                    $stmt->close();
                }

                $_SESSION['last_order_time'] = time();
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                $submitted_order = [
                    'code'        => $order_code,
                    'date'        => date("d-m-Y"),
                    'name'        => $name,
                    'phone'       => $phone,
                    'city'        => $city,
                    'items'       => $pdf_items,
                    'sub_total'   => $sub_total,
                    'packing_chg' => $packing_chg,
                    'round_off'   => $round_off,
                    'total'       => $final_total
                ];
            }
        }
    }
}

$json_flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($SHOP_NAME); ?> | Sivakasi Direct Store</title>
  <style>
    :root { --primary: #d90429; --accent: #ffb703; --bg: #f8f9fa; --border: #e0e0e0; }
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Arial, sans-serif; }
    body { background: var(--bg); padding-bottom: 70px; color: #222; }
    .top-bar { background: #1d1e22; color: #fff; font-size: 12px; padding: 8px 4%; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 6px; }
    .top-bar strong { color: var(--accent); }
    header { background: linear-gradient(90deg, #780015, #d90429); color: #fff; padding: 12px 4%; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
    .logo-wrap { display: flex; align-items: center; gap: 10px; text-decoration: none; color: #fff; }
    .logo-wrap h1 { font-size: 20px; color: var(--accent); letter-spacing: 0.5px; }
    .nav-bar { background: #2b0509; padding: 10px 4%; display: flex; gap: 18px; overflow-x: auto; }
    .nav-bar a { color: #fff; text-decoration: none; font-size: 13px; font-weight: 600; white-space: nowrap; }
    .nav-bar a:hover, .nav-bar a.active { color: var(--accent); }
    .cart-btn { background: var(--accent); color: #000; border: none; padding: 9px 14px; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 13px; }
    .err-banner { background: #ffe3e3; color: #c92a2a; padding: 10px 4%; text-align: center; font-weight: bold; font-size: 13px; border-bottom: 1px solid #ffa8a8; }
   /* .hero { background: linear-gradient(135deg, #3a000a, #780015); color: #fff; text-align: center; padding: 30px 4%; }
    .hero h2 { font-size: 26px; color: var(--accent); margin-bottom: 6px; }
    .hero p { font-size: 14px; color: #f1f3f5; max-width: 650px; margin: 0 auto 14px; }
    .hero-cta { display: inline-block; background: var(--accent); color: #000; padding: 9px 18px; border-radius: 6px; font-weight: bold; font-size: 13px; text-decoration: none; } */  
       /* Interactive Banner Widget */
    .banner-widget { position: relative; overflow: hidden; background: #2b0509; color: #fff; }
    .banner-track { display: flex; transition: transform 0.45s ease-in-out; }
    .banner-slide { min-width: 100%; padding: 34px 10%; text-align: center; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 220px; background-size: cover; background-position: center; background-repeat: no-repeat; }
    .slide-1 { background: radial-gradient(circle at top right, #9e001c, #3a000a 75%); }
    .slide-2 { background: radial-gradient(circle at top left, #780015, #1d1e22 80%); }
    .slide-3 { background: radial-gradient(circle at bottom right, #590d22, #2b0509 75%); }
    .banner-pill { background: rgba(255, 183, 3, 0.18); border: 1px solid var(--accent); color: var(--accent); font-size: 11px; font-weight: bold; padding: 4px 12px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 10px; }
    .banner-slide h2 { font-size: 26px; color: var(--accent); margin-bottom: 8px; line-height: 1.25; }
    .banner-slide p { font-size: 14px; color: #f1f3f5; max-width: 640px; margin: 0 auto 16px; line-height: 1.5; }
    .banner-actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
    .hero-cta { display: inline-block; background: var(--accent); color: #000; padding: 10px 18px; border-radius: 6px; font-weight: bold; font-size: 13px; text-decoration: none; transition: transform 0.15s; }
    .hero-cta:hover { transform: translateY(-1px); }
    .hero-cta.sec { background: rgba(255,255,255,0.12); color: #fff; border: 1px solid rgba(255,255,255,0.35); }
    .b-nav { position: absolute; top: 50%; transform: translateY(-50%); background: rgba(0,0,0,0.45); color: #fff; border: 1px solid rgba(255,255,255,0.2); width: 34px; height: 34px; border-radius: 50%; font-size: 18px; cursor: pointer; z-index: 10; display: flex; align-items: center; justify-content: center; }
    .b-nav:hover { background: var(--accent); color: #000; }
    .b-prev { left: 12px; }
    .b-next { right: 12px; }
    .b-dots { position: absolute; bottom: 10px; left: 50%; transform: translateX(-50%); display: flex; gap: 7px; z-index: 10; }
    .b-dot { width: 9px; height: 9px; border-radius: 50%; background: rgba(255,255,255,0.4); border: none; cursor: pointer; padding: 0; }
    .b-progress { position: absolute; bottom: 0; left: 0; height: 3px; background: var(--accent); width: 0%; z-index: 11; transition: width 0.05s linear; }
    .b-dot.active { background: var(--accent); width: 22px; border-radius: 5px; }
    @media (max-width: 600px) { .banner-slide { padding: 28px 12%; min-height: 200px; } .banner-slide h2 { font-size: 21px; } }
    .controls { max-width: 1100px; margin: 16px auto; padding: 0 4%; }
    .search { width: 100%; padding: 11px 14px; border: 1px solid var(--border); border-radius: 8px; margin-bottom: 10px; font-size: 14px; }
    .cats { display: flex; gap: 8px; overflow-x: auto; padding-bottom: 6px; }
    .cat-btn { background: #fff; border: 1px solid var(--border); padding: 7px 14px; border-radius: 18px; font-size: 13px; font-weight: 600; white-space: nowrap; cursor: pointer; }
    .cat-btn.active { background: var(--primary); color: #fff; border-color: var(--primary); }
    .container { max-width: 1100px; margin: 0 auto; padding: 0 4%; }
    .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; }
    .card { background: #fff; border: 1px solid var(--border); border-radius: 10px; padding: 14px; position: relative; display: flex; flex-direction: column; justify-content: space-between; transition: transform 0.15s; }
    .card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
    .tag { position: absolute; top: 10px; left: 10px; background: #2b9348; color: #fff; font-size: 11px; font-weight: bold; padding: 3px 7px; border-radius: 4px; }
    .icon { height: 80px; background: #fff3f3; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 36px; margin-bottom: 8px; }
    .net { font-size: 18px; font-weight: bold; color: var(--primary); }
    .mrp { font-size: 13px; text-decoration: line-through; color: #888; margin-left: 6px; }
    .disc-amt { font-size: 11.5px; color: #2b8a3e; font-weight: bold; margin-top: 2px; }
    .qty { display: flex; justify-content: space-between; align-items: center; border: 1px solid var(--border); border-radius: 6px; margin-top: 10px; overflow: hidden; }
    .qty button { background: #f1f3f5; border: none; width: 36px; height: 34px; font-size: 18px; font-weight: bold; cursor: pointer; }
    .qty button:hover { background: #e9ecef; }
    .legal { margin: 28px 0 15px; padding: 14px; background: #fff8e6; border: 1px solid #ffe082; border-radius: 8px; font-size: 12px; line-height: 1.5; color: #5c4500; }
    footer { background: #1d1e22; color: #ccc; padding: 24px 4%; font-size: 13px; line-height: 1.6; }
    .overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: none; z-index: 200; }
    .drawer { position: fixed; top: 0; right: -100%; width: 100%; max-width: 390px; height: 100%; background: #fff; z-index: 201; transition: 0.3s; display: flex; flex-direction: column; }
    .drawer.open { right: 0; }
    .d-head { background: var(--primary); color: #fff; padding: 14px; display: flex; justify-content: space-between; align-items: center; }
    .d-body { flex: 1; overflow-y: auto; padding: 14px; }
    .c-item { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid var(--border); font-size: 13px; }
    .d-foot { padding: 14px; border-top: 1px solid var(--border); background: #f8f9fa; }
    .warn { background: #ffe3e3; color: #c92a2a; padding: 8px; border-radius: 5px; font-size: 12px; margin-bottom: 8px; font-weight: bold; }
    .inp { width: 100%; padding: 9px; margin-bottom: 8px; border: 1px solid var(--border); border-radius: 5px; font-size: 13px; }
    .sub-btn { width: 100%; background: var(--primary); color: #fff; border: none; padding: 11px; border-radius: 6px; font-size: 15px; font-weight: bold; cursor: pointer; margin-bottom: 6px; }
    .sub-btn:disabled { background: #adb5bd; cursor: not-allowed; }
    .pdf-btn { width: 100%; background: #1d1e22; color: #fff; border: none; padding: 9px; border-radius: 6px; font-size: 14px; font-weight: bold; cursor: pointer; }
  </style>
</head>
<body>

  <div class="top-bar">
    <span>📞 Phone & WhatsApp: <strong><?php echo htmlspecialchars($PHONE_DISPLAY ?? '+91 79047 85247'); ?></strong></span>
    <span>🚚 Min Order: <strong>₹<?php echo number_format($MIN_ORDER); ?>/-</strong> • License: <strong><?php echo htmlspecialchars($LICENSE_NO ?? '2026'); ?></strong></span>
  </div>

  <header>
    <a href="index.php" class="logo-wrap">
      <svg width="42" height="42" viewBox="0 0 100 100">
        <circle cx="50" cy="50" r="46" fill="#240006" stroke="#ffb703" stroke-width="5"/>
        <text x="50" y="58" text-anchor="middle" font-family="serif" font-weight="bold" font-size="34" fill="#ffb703">VK</text>
        <text x="50" y="75" text-anchor="middle" font-family="sans-serif" font-weight="bold" font-size="10" fill="#fff">CRACKERS</text>
      </svg>
      <div>
        <h1><?php echo htmlspecialchars($SHOP_NAME); ?></h1>
        <small>Sivakasi Direct Store</small>
      </div>
    </a>
    <button class="cart-btn" onclick="toggleCart()">🛒 (<span id="cnt">0</span>) ₹<span id="htot">0</span></button>
  </header>

  <nav class="nav-bar">
    <a href="index.php" class="active">Home</a>
    <a href="quick-shop.php">Quick Shopping</a>
    <a href="combo.php">Combo Products</a>
    <a href="track-order.php">Track Order</a>
    <a href="about.php">About Us</a>
    <a href="contact.php">Contact</a>
  </nav>

  <?php if ($order_err !== ''): ?>
    <div class="err-banner">⚠️ <?php echo htmlspecialchars($order_err); ?></div>
  <?php endif; ?>

  <!--section class="hero">
    <h2>Flat <?php echo $DISC_PCT; ?>% Diwali Factory Offer</h2>
    <p>Select crackers below or use our Quick Price List to generate an instant estimate & Order Tracking ID from <?php echo htmlspecialchars($SHOP_NAME); ?>.</p>
    <a href="quick-shop.php" class="hero-cta">⚡ Open Table Quick Price List →</a>
  </section-->
  
    <section class="banner-widget" id="bannerWidget" onmouseenter="pauseBanner()" onmouseleave="resumeBanner()">
    <?php if (count($banners) > 1): ?>
      <button type="button" class="b-nav b-prev" onclick="moveBanner(-1)" aria-label="Previous Banner">‹</button>
      <button type="button" class="b-nav b-next" onclick="moveBanner(1)" aria-label="Next Banner">›</button>
    <?php endif; ?>

    <div class="banner-track" id="bannerTrack">
      <?php foreach ($banners as $idx => $bn):
        $slide_cls = 'slide-' . (($idx % 3) + 1);
        $pill = format_banner_txt((string)($bn['pill'] ?? ''), $DISC_PCT, $MIN_ORDER);
        $title = format_banner_txt((string)($bn['title'] ?? ''), $DISC_PCT, $MIN_ORDER);
        $desc = format_banner_txt((string)($bn['desc'] ?? ''), $DISC_PCT, $MIN_ORDER);
        $btn_txt = format_banner_txt((string)($bn['btn_text'] ?? 'Shop Now →'), $DISC_PCT, $MIN_ORDER);
        $btn_lnk = (string)($bn['btn_link'] ?? 'quick-shop.php');
        $bg_img  = trim((string)($bn['bg_image'] ?? ''));
        $bg_style = $bg_img !== ''
          ? ' style="background-image: linear-gradient(rgba(25, 0, 5, 0.68), rgba(25, 0, 5, 0.78)), url(\'' . htmlspecialchars($bg_img, ENT_QUOTES) . '\');"'
          : '';
      ?>
      <div class="banner-slide <?php echo $slide_cls; ?>"<?php echo $bg_style; ?>>
        <?php if ($pill !== ''): ?>
          <span class="banner-pill"><?php echo htmlspecialchars($pill); ?></span>
        <?php endif; ?>
        <h2><?php echo htmlspecialchars($title); ?></h2>
        <?php if ($desc !== ''): ?>
          <p><?php echo htmlspecialchars($desc); ?></p>
        <?php endif; ?>
        <div class="banner-actions">
          <a href="<?php echo htmlspecialchars($btn_lnk); ?>" class="hero-cta"><?php echo htmlspecialchars($btn_txt); ?></a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if (count($banners) > 1): ?>
      <div class="b-dots" id="bannerDots">
        <?php foreach ($banners as $idx => $bn): ?>
          <button type="button" class="b-dot <?php echo $idx === 0 ? 'active' : ''; ?>" onclick="goBanner(<?php echo $idx; ?>)" aria-label="Slide <?php echo $idx + 1; ?>"></button>
        <?php endforeach; ?>
      </div>
      <div class="b-progress" id="bannerProgress"></div>
    <?php endif; ?>
  </section>

  <div class="controls">
    <input type="text" id="srch" class="search" placeholder="🔍 Search crackers by name or category..." onkeyup="filterGrid()" />
    <div class="cats">
      <button class="cat-btn active" onclick="setCat('All', this)">All (<?php echo count($products); ?>)</button>
      <?php foreach ($categories as $catName): ?>
        <button class="cat-btn" onclick="setCat(<?php echo htmlspecialchars(json_encode($catName), ENT_QUOTES); ?>, this)"><?php echo htmlspecialchars($catName); ?></button>
      <?php endforeach; ?>
    </div>
  </div>
  <main class="container">
    <div class="grid">
      <?php foreach ($products as $p):
        $off = $DISC_PCT > 0 ? $DISC_PCT : ($p['mrp'] > 0 ? round((($p['mrp'] - $p['price']) / $p['mrp']) * 100) : 0);
        $disc_amt = max(0, $p['mrp'] - $p['price']);
      ?>
      <div class="card prod-card" data-cat="<?php echo htmlspecialchars($p['cat']); ?>" data-name="<?php echo strtolower(htmlspecialchars($p['name'].' '.$p['cat'])); ?>">
        <span class="tag"><?php echo $off; ?>% OFF</span>
        <div>
          <div class="icon"><?php echo htmlspecialchars($p['icon']); ?></div>
          <small style="color:#666;font-weight:bold;"><?php echo strtoupper(htmlspecialchars($p['cat'])); ?></small>
          <h3 style="font-size:15px;margin:4px 0 8px;"><?php echo htmlspecialchars($p['name']); ?></h3>
        </div>
        <div>
          <div><span class="net">₹<?php echo $p['price']; ?></span><span class="mrp">₹<?php echo $p['mrp']; ?></span></div>
          <div class="disc-amt">You Save: ₹<?php echo $disc_amt; ?> (<?php echo $off; ?>%)</div>
          <div class="qty">
            <button type="button" onclick="qty(<?php echo $p['id']; ?>, -1)">−</button>
            <span id="q-<?php echo $p['id']; ?>">0</span>
            <button type="button" onclick="qty(<?php echo $p['id']; ?>, 1)">+</button>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="legal">
      <strong>Legal Notice:</strong> As per the 2018 Supreme Court Order, direct online sale of firecrackers is restricted. Submit your estimate to <strong><?php echo htmlspecialchars($SHOP_NAME); ?></strong> and our team will call you within 2 hours to confirm stock and parcel transport.
    </div>
  </main>

  <footer>
    <strong><?php echo htmlspecialchars($SHOP_NAME); ?></strong> • <?php echo htmlspecialchars($cfg['address']); ?><br>
    Phone: <?php echo htmlspecialchars($PHONE_DISPLAY ?? '+91 79047 85247'); ?> | <a href="track-order.php" style="color:#ffb703;">Track Your Order</a>
  </footer>

  <div class="overlay" id="ov" onclick="toggleCart()"></div>
  <aside class="drawer" id="drw">
    <div class="d-head">
      <h3><?php echo htmlspecialchars($SHOP_NAME); ?> Estimate</h3>
      <button type="button" onclick="toggleCart()" style="background:none;border:none;color:#fff;font-size:24px;cursor:pointer;">×</button>
    </div>
    <div class="d-body" id="citems"></div>
    <form method="POST" class="d-foot">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>" />
      <div style="font-size:13px;margin-bottom:8px;line-height:1.6;">
        <div style="display:flex;justify-content:space-between;"><span>Sub Total:</span><span>₹<span id="dsub">0.00</span></span></div>
        <div style="display:flex;justify-content:space-between;"><span>Packing Charges (1.5%):</span><span>₹<span id="dpack">0.00</span></span></div>
        <div style="display:flex;justify-content:space-between;font-weight:bold;font-size:16px;border-top:1px dashed #ccc;padding-top:4px;margin-top:4px;">
          <span>Overall Total:</span><span style="color:var(--primary);">₹<span id="dtot">0</span></span>
        </div>
      </div>
      <div id="warn" class="warn">⚠️ Min order ₹<?php echo $MIN_ORDER; ?>. Add ₹<span id="rem"><?php echo $MIN_ORDER; ?></span> more.</div>
      <input type="hidden" name="order_data" id="order_data" />
      <input type="text" name="cust_name" id="cname" class="inp" placeholder="Your Name *" maxlength="100" required />
      <input type="tel" name="cust_phone" id="cphone" class="inp" placeholder="10-Digit Mobile Number *" pattern="[0-9]{10}" maxlength="10" required />
      <input type="text" name="cust_city" id="ccity" class="inp" placeholder="Delivery City *" maxlength="100" required />
      <button type="submit" id="sbtn" class="sub-btn" disabled>Submit Estimate Order</button>
      <button type="button" class="pdf-btn" onclick="printDraftPDF()">📄 Save / Print PDF Bill</button>
    </form>
  </aside>

  <?php if ($submitted_order): ?>
  <div id="successModal" style="position:fixed;inset:0;background:rgba(0,0,0,0.65);display:flex;align-items:center;justify-content:center;z-index:300;padding:16px;">
    <div style="background:#fff;max-width:400px;width:100%;border-radius:12px;padding:24px;text-align:center;box-shadow:0 10px 25px rgba(0,0,0,0.2);">
      <div style="font-size:46px;margin-bottom:8px;">✅</div>
      <h2 style="color:#2b9348;font-size:20px;margin-bottom:8px;">Your order submitted. We will contact you soon.</h2>
      <p style="font-size:14px;color:#333;margin-bottom:6px;">Order Tracking ID: <strong style="color:#d90429;font-size:16px;"><?php echo htmlspecialchars($submitted_order['code']); ?></strong></p>
      <p style="font-size:13px;color:#666;margin-bottom:18px;">Overall Amount: <strong>₹<?php echo number_format($submitted_order['total']); ?></strong></p>
      <button type="button" onclick="printSubmittedPDF()" style="width:100%;background:#1d1e22;color:#fff;border:none;padding:12px;border-radius:6px;font-size:15px;font-weight:bold;cursor:pointer;margin-bottom:8px;">
        📄 Save / Print PDF
      </button>
      <button type="button" onclick="document.getElementById('successModal').style.display='none'" style="width:100%;background:#eee;color:#333;border:none;padding:10px;border-radius:6px;font-size:14px;font-weight:bold;cursor:pointer;">
        Close
      </button>
    </div>
  </div>
  <?php endif; ?>

  <script>
    const MIN = <?php echo $MIN_ORDER; ?>;
    const DISC_PCT = <?php echo $DISC_PCT; ?>;
    const items = <?php echo json_encode($products, $json_flags); ?>;
    const CFG = <?php echo json_encode($cfg, $json_flags); ?>;
    const savedOrder = <?php echo json_encode($submitted_order, $json_flags); ?>;
    const SHOP_NAME_JS = <?php echo json_encode($SHOP_NAME, $json_flags); ?>;
    const PHONE_JS = <?php echo json_encode($PHONE_DISPLAY ?? '+91 79047 85247', $json_flags); ?>;
    let cart = {}, curCat = "All";

    function esc(str) {
      return String(str ?? '').replace(/[&<>"']/g, s => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[s]));
    }

    function setCat(c, btn) {
      curCat = c;
      document.querySelectorAll(".cat-btn").forEach(b => b.classList.remove("active"));
      btn.classList.add("active");
      filterGrid();
    }

    function filterGrid() {
      const q = document.getElementById("srch").value.toLowerCase();
      document.querySelectorAll(".prod-card").forEach(card => {
        card.style.display = ((curCat === "All" || card.dataset.cat === curCat) && card.dataset.name.includes(q)) ? "flex" : "none";
      });
    }

    function qty(id, d) {
      cart[id] = (cart[id] || 0) + d;
      if (cart[id] <= 0) delete cart[id];
      if (cart[id] > 999) cart[id] = 999;
      document.getElementById("q-" + id).innerText = cart[id] || 0;
      updateCart();
    }

    function updateCart() {
      let c = 0, t = 0, box = document.getElementById("citems");
      box.innerHTML = "";
      Object.keys(cart).forEach(id => {
        const p = items.find(x => x.id == id);
        if (!p) return;
        const sub = p.price * cart[id];
        c += cart[id]; t += sub;
        box.innerHTML += `<div class="c-item"><div><strong>${esc(p.name)}</strong><br><small>₹${p.price} × ${cart[id]}</small></div><b>₹${sub}</b></div>`;
      });
      if (!c) box.innerHTML = `<p style="text-align:center;color:#777;margin-top:30px;">Estimate list is empty.</p>`;
      const pack = +(t * 0.015).toFixed(2);
      const grand = Math.round(t + pack);
      document.getElementById("cnt").innerText = c;
      document.getElementById("htot").innerText = t;
      document.getElementById("dsub").innerText = t.toFixed(2);
      document.getElementById("dpack").innerText = pack.toFixed(2);
      document.getElementById("dtot").innerText = grand;
      document.getElementById("warn").style.display = t >= MIN ? "none" : "block";
      document.getElementById("rem").innerText = Math.max(0, MIN - t);
      document.getElementById("sbtn").disabled = t < MIN;
      document.getElementById("order_data").value = JSON.stringify(cart);
    }

    function toggleCart() {
      const d = document.getElementById("drw"), o = document.getElementById("ov");
      o.style.display = d.classList.toggle("open") ? "block" : "none";
    }
    function buildEstimateHTML(meta) {
      let rows = "", subTot = 0, totalQty = 0, minDisc = 100, maxDisc = 0;
      meta.items.forEach((it, i) => {
        const discPct = DISC_PCT > 0 ? DISC_PCT : (it.mrp > 0 ? Math.round(((it.mrp - it.price) / it.mrp) * 100) : 0);
        if (discPct < minDisc) minDisc = discPct;
        if (discPct > maxDisc) maxDisc = discPct;
        const unitDiscAmt = Math.max(0, it.mrp - it.price);
        subTot += it.sub;
        totalQty += parseInt(it.qty);
        rows += `<tr>
          <td style="text-align:center;">${i + 1}</td>
          <td style="text-align:center;">${esc(it.id)}</td>
          <td>${esc(it.name)}</td>
          <td style="text-align:center;">${parseInt(it.qty)}</td>
          <td style="text-align:right;">${parseFloat(it.mrp).toFixed(2)}</td>
          <td style="text-align:right;">${unitDiscAmt.toFixed(2)}</td>
          <td style="text-align:right;">${parseFloat(it.price).toFixed(2)}</td>
          <td style="text-align:right;">${parseFloat(it.sub).toFixed(2)}</td>
        </tr>`;
      });

      const discRange = meta.items.length
        ? (minDisc === maxDisc ? `${minDisc}%` : `${minDisc}% - ${maxDisc}%`)
        : `${DISC_PCT}%`;
      const pack = parseFloat(meta.packing_chg ?? (subTot * 0.015).toFixed(2));
      const grand = parseInt(meta.total ?? Math.round(subTot + pack));
      const rnd = +(grand - (subTot + pack)).toFixed(2);

      return `<!DOCTYPE html>
      <html>
      <head>
        <title>${esc(SHOP_NAME_JS)} - Estimate ${esc(meta.code)}</title>
        <style>
          @page { size: A4 portrait; margin: 8mm; }
          * { box-sizing: border-box; }
          body { font-family: Arial, sans-serif; margin: 0; padding: 0; color: #000; font-size: 11.5px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
          .wrap { border: 1.5px solid #000; width: 100%; min-height: 277mm; display: flex; flex-direction: column; justify-content: space-between; }
          .row-flex { display: flex; justify-content: space-between; padding: 5px 8px; border-bottom: 1px solid #000; }
          .shop-box { text-align: center; padding: 8px; border-bottom: 1px solid #000; line-height: 1.45; }
          .shop-box h2 { margin: 0 0 2px; font-size: 16px; }
          .shop-contact { margin-top: 3px; font-size: 11.5px; font-weight: bold; }
          .cust-bank { display: flex; border-bottom: 1px solid #000; }
          .cust-col { width: 52%; padding: 7px 8px; border-right: 1px solid #000; line-height: 1.45; }
          .bank-col { width: 48%; padding: 7px 8px; line-height: 1.45; }
          .bank-table td { padding: 1px 4px; border: none; font-size: 11px; }
          table.items { width: 100%; border-collapse: collapse; }
          table.items th, table.items td { border: 1px solid #000; padding: 4px 6px; font-size: 11px; }
          table.items th { background: #f2f2f2; }
          .bottom-section { margin-top: auto; width: 100%; }
          table.summary { width: 100%; border-collapse: collapse; border-top: 1.5px solid #000; }
          table.summary td { border: 1px solid #000; padding: 5px 8px; font-size: 11.5px; }
          .no-wrap { white-space: nowrap; }
          .overall-row td { background-color: #dcdcdc !important; font-weight: bold; font-size: 12.5px; }
        </style>
      </head>
      <body>
        <div class="wrap">
          <div class="top-section">
            <div class="row-flex">
              <span><b>Enquiry No :</b> ${esc(meta.code)}</span>
              <strong style="font-size:14px;">ESTIMATE</strong>
              <span><b>Date :</b> ${esc(meta.date)}</span>
            </div>
            <div class="shop-box">
              <h2>${esc(SHOP_NAME_JS)}</h2>
              <div>${esc(CFG.address)}</div>
              <div class="shop-contact">Mobile : ${esc(PHONE_JS)} &nbsp;|&nbsp; E-mail : ${esc(CFG.email)}</div>
            </div>
            <div class="cust-bank">
              <div class="cust-col">
                <strong>Customer Details</strong><br>
                ${esc(meta.name)}<br>
                ${esc(meta.phone)}<br>
                ${esc(meta.city)}
              </div>
              <div class="bank-col">
                <table class="bank-table">
                  <tr><td><b>A/C Name</b></td><td>: ${esc(CFG.ac_name)}</td></tr>
                  <tr><td><b>A/C Number</b></td><td>: ${esc(CFG.ac_no)}</td></tr>
                  <tr><td><b>A/C Type</b></td><td>: ${esc(CFG.ac_type)}</td></tr>
                  <tr><td><b>Bank Name</b></td><td>: ${esc(CFG.bank_name)}</td></tr>
                  <tr><td><b>IFSC Code</b></td><td>: ${esc(CFG.ifsc)}</td></tr>
                  <tr><td><b>UPI ID</b></td><td>: ${esc(CFG.upi)}</td></tr>
                </table>
              </div>
            </div>
            <table class="items">
              <thead>
                <tr>
                  <th style="width:36px;">S.No</th>
                  <th style="width:44px;">Code</th>
                  <th>Product Name</th>
                  <th style="width:42px;">Qty</th>
                  <th style="width:65px;">MRP</th>
                  <th style="width:82px;">Disc (${esc(discRange)})</th>
                  <th style="width:65px;">Rate</th>
                  <th style="width:78px;">Amount</th>
                </tr>
              </thead>
              <tbody>
                ${rows}
              </tbody>
            </table>
          </div>

          <div class="bottom-section">
            <table class="summary">
              <tr>
                <td rowspan="4" style="width:52%; vertical-align:top; line-height:1.6;">
                  <strong>Total Items :</strong> ${meta.items.length} &nbsp;&nbsp;|&nbsp;&nbsp; <strong>Total Qty :</strong> ${totalQty}<br>
                  <strong>Discount Applied :</strong> ${esc(discRange)}
                </td>
                <td class="no-wrap" style="width:30%; text-align:right; font-weight:bold;">Sub Total</td>
                <td class="no-wrap" style="width:18%; text-align:right; font-weight:bold;">${subTot.toFixed(2)}</td>
              </tr>
              <tr>
                <td class="no-wrap" style="text-align:right; font-weight:bold;">Packing Charges (1.5%)</td>
                <td class="no-wrap" style="text-align:right; font-weight:bold;">${pack.toFixed(2)}</td>
              </tr>
              <tr>
                <td class="no-wrap" style="text-align:right; font-weight:bold;">Round Off</td>
                <td class="no-wrap" style="text-align:right; font-weight:bold;">${(rnd >= 0 ? '+' : '') + rnd.toFixed(2)}</td>
              </tr>
              <tr class="overall-row">
                <td class="no-wrap" style="text-align:right;">Overall Amount</td>
                <td class="no-wrap" style="text-align:right;">₹ ${grand.toFixed(2)}</td>
              </tr>
            </table>
          </div>
        </div>
        <script>window.onload = function() { window.print(); };<\/script>
      </body>
      </html>`;
    }

    function openPrintWindow(html) {
      const win = window.open("", "_blank");
      if (win) { win.document.write(html); win.document.close(); }
    }

    function printDraftPDF() {
      const list = [];
      Object.keys(cart).forEach(id => {
        const p = items.find(x => x.id == id);
        if (p) list.push({ id: p.id, name: p.name, mrp: p.mrp, price: p.price, qty: cart[id], sub: p.price * cart[id] });
      });
      if (!list.length) return alert("Add items first!");
      const today = new Date();
      const dd = String(today.getDate()).padStart(2, '0') + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + today.getFullYear();
      openPrintWindow(buildEstimateHTML({
        code: "DRAFT",
        date: dd,
        name: document.getElementById("cname").value || "Customer",
        phone: document.getElementById("cphone").value || "-",
        city: document.getElementById("ccity").value || "-",
        items: list
      }));
    }

    function printSubmittedPDF() {
      if (!savedOrder) return;
      openPrintWindow(buildEstimateHTML(savedOrder));
    }
    
     let bannerIdx = 0;
    const totalBanners = <?php echo count($banners); ?>;
    const SLIDE_DURATION = <?php echo $banner_ms; ?>;
    let elapsed = 0;
    let isBannerPaused = false;

    function renderBanner() {
      const track = document.getElementById("bannerTrack");
      const dots = document.querySelectorAll(".b-dot");
      if (!track) return;
      track.style.transform = `translateX(-${bannerIdx * 100}%)`;
      dots.forEach((d, i) => d.classList.toggle("active", i === bannerIdx));
      elapsed = 0;
      const bar = document.getElementById("bannerProgress");
      if (bar) bar.style.width = "0%";
    }

    function moveBanner(step) {
      bannerIdx = (bannerIdx + step + totalBanners) % totalBanners;
      renderBanner();
    }

    function goBanner(idx) {
      bannerIdx = idx;
      renderBanner();
    }

    function pauseBanner() { isBannerPaused = true; }
    function resumeBanner() { isBannerPaused = false; }

    if (totalBanners > 1) {
      setInterval(() => {
        if (isBannerPaused || document.hidden) return;
        elapsed += 50;
        const bar = document.getElementById("bannerProgress");
        if (bar) bar.style.width = Math.min(100, (elapsed / SLIDE_DURATION) * 100) + "%";
        if (elapsed >= SLIDE_DURATION) {
          moveBanner(1);
        }
      }, 50);
    }


    updateCart();
  </script>
</body>
</html>
