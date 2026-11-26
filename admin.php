<?php
// Secure session cookie settings
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();
require 'db.php';

$SHOP_NAME = $SHOP_NAME ?? 'VK Crackers';
$PHONE_DISPLAY = $PHONE_DISPLAY ?? '+91 79047 85247';

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function verify_csrf(): bool {
    return isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token']);
}

// Login with basic rate-limiting & timing-safe check
$err = '';
if (isset($_POST['login'])) {
    $attempts = $_SESSION['login_attempts'] ?? 0;
    $lock_until = $_SESSION['login_lock_until'] ?? 0;

    if (time() < $lock_until) {
        $wait = $lock_until - time();
        $err = "Too many failed attempts. Try again in {$wait}s.";
    } elseif (!verify_csrf()) {
        $err = "Invalid security token. Please refresh and try again.";
    } else {
        $input_pass = (string)($_POST['pass'] ?? '');
        $valid_pass = (string)($ADMIN_PASS ?? '');
        if ($valid_pass !== '' && hash_equals($valid_pass, $input_pass)) {
            session_regenerate_id(true);
            $_SESSION['vk_admin'] = true;
            $_SESSION['login_attempts'] = 0;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            header("Location: admin.php");
            exit;
        } else {
            $_SESSION['login_attempts'] = $attempts + 1;
            if ($_SESSION['login_attempts'] >= 5) {
                $_SESSION['login_lock_until'] = time() + 60;
            }
            $err = "Wrong Password!";
        }
    }
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header("Location: admin.php");
    exit;
}

// Stop execution before exposing any store/customer data if not authenticated
if (empty($_SESSION['vk_admin'])):
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($SHOP_NAME); ?> Admin Login</title>
  <style>
    body { font-family: sans-serif; background: #f4f4f4; padding: 12px; }
    .box { background: #fff; padding: 20px; border-radius: 8px; max-width: 340px; margin: 60px auto; text-align: center; box-shadow: 0 2px 6px rgba(0,0,0,0.1); }
    input, button { padding: 10px; margin: 6px 0; border-radius: 5px; font-size: 14px; width: 100%; box-sizing: border-box; }
    input { border: 1px solid #ccc; }
    button { background: #d90429; color: #fff; border: none; font-weight: bold; cursor: pointer; }
  </style>
</head>
<body>
  <div class="box">
    <h2><?php echo htmlspecialchars($SHOP_NAME); ?> Admin</h2>
    <?php if ($err !== '') echo "<p style='color:#c92a2a;font-weight:bold;'>" . htmlspecialchars($err) . "</p>"; ?>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
      <input type="password" name="pass" placeholder="Enter Admin Password" required autofocus>
      <button type="submit" name="login">Login</button>
    </form>
  </div>
</body>
</html>
<?php
exit;
endif;

// ================= AUTHENTICATED ADMIN AREA =================

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

$default_cfg = [
    'ac_name' => 'VK Crackers', 'ac_no' => '000000000000', 'ac_type' => 'Current',
    'bank_name' => 'Indian Overseas Bank', 'ifsc' => 'IOBA0000000', 'upi' => 'vkcrackers@upi',
    'email' => 'vkcrackers@gmail.com', 'address' => 'Sivakasi Main Road, Sivakasi, Tamil Nadu - 626189',
    'discount_pct' => 80, 'min_order' => (isset($MIN_ORDER) ? (int)$MIN_ORDER : 3000),
    'disabled_ids' => [],
    'banner_interval' => 4,
    'banners' => $default_banners
];

$loaded_cfg = file_exists('settings.json') ? json_decode(file_get_contents('settings.json'), true) : [];
$cfg = is_array($loaded_cfg) ? array_merge($default_cfg, $loaded_cfg) : $default_cfg;
if (!isset($cfg['disabled_ids']) || !is_array($cfg['disabled_ids'])) {
    $cfg['disabled_ids'] = [];
}

function save_settings(array $cfg): bool {
    return file_put_contents('settings.json', json_encode($cfg, JSON_PRETTY_PRINT), LOCK_EX) !== false;
}

$allowed_tabs = ['orders', 'customers', 'walkin', 'store', 'payment', 'products'];
$tab = (isset($_GET['tab']) && in_array($_GET['tab'], $allowed_tabs, true)) ? $_GET['tab'] : 'orders';
$msg = '';
$msg_type = 'ok';
$walkin_saved_order = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $msg = "⚠️ Security token expired. Please try again.";
        $msg_type = 'err';
    } else {
        // 1. Save Walk-in Estimate Bill
        if (isset($_POST['save_walkin'])) {
            $tab = 'walkin';
            $w_name = trim((string)($_POST['w_name'] ?? '')) ?: 'Walk-in Customer';
            $w_phone = preg_replace('/[^0-9]/', '', trim((string)($_POST['w_phone'] ?? ''))) ?: '-';
            $w_city = trim((string)($_POST['w_city'] ?? '')) ?: 'Sivakasi (Direct)';
            $w_cart = json_decode((string)($_POST['w_cart_data'] ?? '{}'), true);

            $extra_disc = max(0, (float)($_POST['extra_disc'] ?? 0));
            $show_extra_disc = isset($_POST['show_extra_disc']);
            $packing_chg = max(0, (float)($_POST['packing_chg'] ?? 0));
            $show_packing_chg = isset($_POST['show_packing_chg']);

            // Collision-safe sequential order code using MAX(id)
            $yr = date("y");
            $prefix = "VK" . $yr;
            $max_res = $conn->query("SELECT COALESCE(MAX(id), 0) AS max_id FROM orders");
            $next_num = ($max_res && ($r = $max_res->fetch_assoc())) ? ((int)$r['max_id'] + 1) : 1;
            $order_code = $prefix . str_pad((string)$next_num, 4, '0', STR_PAD_LEFT);

            $disc_pct = (float)$cfg['discount_pct'];
            $items_summary = "";
            $pdf_items = [];
            $sub_total = 0;
            $i = 1;

            $pres_w = $conn->query("SELECT * FROM products ORDER BY cat, id");
            if ($pres_w && is_array($w_cart)) {
                while ($p = $pres_w->fetch_assoc()) {
                    $id = (string)$p['id'];
                    if (!empty($w_cart[$id]) && (int)$w_cart[$id] > 0) {
                        $q = (int)$w_cart[$id];
                        $mrp = (int)$p['mrp'];
                        $rate = $disc_pct > 0 ? (int)round($mrp - ($mrp * $disc_pct / 100)) : (int)$p['price'];
                        $sub = $rate * $q;
                        $sub_total += $sub;
                        $items_summary .= "{$i}. {$p['name']} x {$q} = ₹{$sub}\n";
                        $pdf_items[] = [
                            'id' => (int)$p['id'],
                            'name' => $p['name'],
                            'mrp' => $mrp,
                            'price' => $rate,
                            'qty' => $q,
                            'sub' => $sub
                        ];
                        $i++;
                    }
                }
            }

            if ($show_extra_disc && $extra_disc > 0) {
                $items_summary .= "Extra Discount: -₹" . number_format($extra_disc, 2, '.', '') . "\n";
            }
            if ($show_packing_chg && $packing_chg > 0) {
                $items_summary .= "Packing Charges: +₹" . number_format($packing_chg, 2, '.', '') . "\n";
            }

            $raw_total = max(0, $sub_total - ($show_extra_disc ? $extra_disc : 0) + ($show_packing_chg ? $packing_chg : 0));
            $final_total = (int)round($raw_total);
            $round_off = round($final_total - $raw_total, 2);
            if ($round_off != 0) {
                $items_summary .= "Round Off: " . ($round_off >= 0 ? "+" : "") . number_format($round_off, 2, '.', '') . "\n";
            }

            if (!empty($pdf_items)) {
                $stmt = $conn->prepare("INSERT INTO orders (order_code, cust_name, cust_phone, cust_city, items_text, total_amount, status, tracking_info) VALUES (?, ?, ?, ?, ?, ?, 'Walk-in Estimate', 'Direct Store Counter')");
                if ($stmt) {
                    $stmt->bind_param("sssssi", $order_code, $w_name, $w_phone, $w_city, $items_summary, $final_total);
                    $stmt->execute();
                    $stmt->close();
                }
                $msg = "✅ Walk-in Estimate #{$order_code} Saved! Opening Print Window...";
                $walkin_saved_order = [
                    'code' => $order_code,
                    'date' => date("d-m-Y"),
                    'name' => $w_name,
                    'phone' => $w_phone,
                    'city' => $w_city,
                    'items' => $pdf_items,
                    'sub_total' => $sub_total,
                    'extra_disc' => $extra_disc,
                    'show_extra_disc' => $show_extra_disc,
                    'packing_chg' => $packing_chg,
                    'show_packing_chg' => $show_packing_chg,
                    'round_off' => $round_off,
                    'total' => $final_total
                ];
            } else {
                $msg = "⚠️ Please select at least one item before saving.";
                $msg_type = 'err';
            }
        }

        // 2. Save Store Front Discount %, Min Order & Dynamic Banners (with Background Image Upload)
        if (isset($_POST['save_store'])) {
            $tab = 'store';
            $new_disc = max(0, min(99, (float)($_POST['discount_pct'] ?? 0)));
            $cfg['discount_pct'] = $new_disc;
            $cfg['min_order'] = max(0, (int)($_POST['min_order'] ?? 0));
            $cfg['banner_interval'] = max(2, min(30, (int)($_POST['banner_interval'] ?? 4)));

            $updated_banners = [];
            $b_pills  = $_POST['b_pill'] ?? [];
            $b_titles = $_POST['b_title'] ?? [];
            $b_descs  = $_POST['b_desc'] ?? [];
            $b_btns   = $_POST['b_btn_text'] ?? [];
            $b_links  = $_POST['b_btn_link'] ?? [];
            $b_imgs   = $_POST['b_bg_image'] ?? [];

            $upload_dir = __DIR__ . '/uploads/banners/';
            $allowed_ext = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

            $slide_count = count($b_titles);
            for ($bi = 0; $bi < $slide_count; $bi++) {
                $title = trim((string)($b_titles[$bi] ?? ''));
                if ($title === '') {
                    continue;
                }

                $bg_url = trim((string)($b_imgs[$bi] ?? ''));

                // Handle optional file upload for this slide
                if (
                    isset($_FILES['b_bg_file']['error'][$bi]) &&
                    $_FILES['b_bg_file']['error'][$bi] === UPLOAD_ERR_OK &&
                    !empty($_FILES['b_bg_file']['tmp_name'][$bi])
                ) {
                    $orig_name = (string)$_FILES['b_bg_file']['name'][$bi];
                    $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
                    $size = (int)$_FILES['b_bg_file']['size'][$bi];
                    if (in_array($ext, $allowed_ext, true) && $size <= 5 * 1024 * 1024) {
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }
                        $new_file = 'banner_' . date('Ymd_His') . '_' . $bi . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                        if (move_uploaded_file($_FILES['b_bg_file']['tmp_name'][$bi], $upload_dir . $new_file)) {
                            $bg_url = 'uploads/banners/' . $new_file;
                        }
                    }
                }

                $updated_banners[] = [
                    'pill'     => trim((string)($b_pills[$bi] ?? '')),
                    'title'    => $title,
                    'desc'     => trim((string)($b_descs[$bi] ?? '')),
                    'btn_text' => trim((string)($b_btns[$bi] ?? 'Shop Now →')) ?: 'Shop Now →',
                    'btn_link' => trim((string)($b_links[$bi] ?? 'quick-shop.php')) ?: 'quick-shop.php',
                    'bg_image' => $bg_url
                ];
            }

            $cfg['banners'] = !empty($updated_banners) ? $updated_banners : $default_banners;
            save_settings($cfg);

            // Sync all product selling prices in DB with the updated discount percentage
            $conn->query("UPDATE products SET price = ROUND(mrp - (mrp * {$new_disc} / 100))");
            $msg = "✅ Store Front Settings, Banners & Rates Saved!";
        }

        // 3. Save Bank & UPI Details
        if (isset($_POST['save_bank'])) {
            $tab = 'payment';
            $cfg['ac_name']   = trim((string)($_POST['ac_name'] ?? ''));
            $cfg['ac_no']     = trim((string)($_POST['ac_no'] ?? ''));
            $cfg['ac_type']   = trim((string)($_POST['ac_type'] ?? ''));
            $cfg['bank_name'] = trim((string)($_POST['bank_name'] ?? ''));
            $cfg['ifsc']      = trim((string)($_POST['ifsc'] ?? ''));
            $cfg['upi']       = trim((string)($_POST['upi'] ?? ''));
            $cfg['email']     = trim((string)($_POST['email'] ?? ''));
            $cfg['address']   = trim((string)($_POST['address'] ?? ''));
            save_settings($cfg);
            $msg = "✅ Payment & Bank Details Saved!";
        }

        // 4. Add Product
        if (isset($_POST['add'])) {
            $tab = 'products';
            $name = trim((string)($_POST['name'] ?? ''));
            $cat = trim((string)($_POST['cat'] ?? ''));
            $icon = trim((string)($_POST['icon'] ?? '🎆')) ?: '🎆';
            $mrp = max(0, (int)($_POST['mrp'] ?? 0));
            $disc = (float)$cfg['discount_pct'];
            $price = $disc > 0 ? (int)round($mrp - ($mrp * $disc / 100)) : $mrp;

            if ($name !== '' && $cat !== '' && $mrp > 0) {
                $stmt = $conn->prepare("INSERT INTO products (name, cat, mrp, price, icon) VALUES (?, ?, ?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param("ssiis", $name, $cat, $mrp, $price, $icon);
                    $stmt->execute();
                    $stmt->close();
                }
                $msg = "✅ Product Added!";
            } else {
                $msg = "⚠️ Please provide valid Name, Category, and MRP.";
                $msg_type = 'err';
            }
        }

        // 5. Update Product MRP
        if (isset($_POST['update'])) {
            $tab = 'products';
            $pid = (int)($_POST['id'] ?? 0);
            $mrp = max(0, (int)($_POST['mrp'] ?? 0));
            $disc = (float)$cfg['discount_pct'];
            $price = $disc > 0 ? (int)round($mrp - ($mrp * $disc / 100)) : $mrp;

            $stmt = $conn->prepare("UPDATE products SET mrp=?, price=? WHERE id=?");
            if ($stmt) {
                $stmt->bind_param("iii", $mrp, $price, $pid);
                $stmt->execute();
                $stmt->close();
            }
            $msg = "✅ Product Price Updated!";
        }

        // 6. Toggle Enable/Disable Product (POST instead of GET)
        if (isset($_POST['toggle_id'])) {
            $tab = 'products';
            $tid = (int)$_POST['toggle_id'];
            $disabled = array_map('intval', $cfg['disabled_ids']);
            if (in_array($tid, $disabled, true)) {
                $disabled = array_values(array_diff($disabled, [$tid]));
                $msg = "✅ Product Enabled (Visible on Store Front)!";
            } else {
                $disabled[] = $tid;
                $msg = "🚫 Product Disabled (Hidden from Store Front)!";
            }
            $cfg['disabled_ids'] = $disabled;
            save_settings($cfg);
        }

        // 7. Delete Product (POST + Prepared Statement)
        if (isset($_POST['del_id'])) {
            $tab = 'products';
            $id = (int)$_POST['del_id'];
            $stmt = $conn->prepare("DELETE FROM products WHERE id=?");
            if ($stmt) {
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->close();
            }
            $cfg['disabled_ids'] = array_values(array_diff(array_map('intval', $cfg['disabled_ids']), [$id]));
            save_settings($cfg);
            $msg = "✅ Product Deleted!";
        }

        // 8. Update Order Status
        if (isset($_POST['update_order'])) {
            $tab = 'orders';
            $allowed_statuses = ['Pending Confirmation', 'Confirmed', 'Packed', 'Dispatched', 'Delivered', 'Walk-in Estimate'];
            $status = in_array($_POST['status'] ?? '', $allowed_statuses, true) ? $_POST['status'] : 'Pending Confirmation';
            $tracking = trim((string)($_POST['tracking_info'] ?? ''));
            $oid = (int)($_POST['order_id'] ?? 0);

            $stmt = $conn->prepare("UPDATE orders SET status=?, tracking_info=? WHERE id=?");
            if ($stmt) {
                $stmt->bind_param("ssi", $status, $tracking, $oid);
                $stmt->execute();
                $stmt->close();
            }
            $msg = "✅ Order Status Updated!";
        }
    }
}

$all_products = [];
$prod_map = [];
$cat_list = [];
$disc_global = (float)$cfg['discount_pct'];
$pres = $conn->query("SELECT * FROM products ORDER BY cat, id");
if ($pres) {
    while ($pr = $pres->fetch_assoc()) {
        $pr['id'] = (int)$pr['id'];
        $pr['mrp'] = (int)$pr['mrp'];
        $pr['calc_rate'] = $disc_global > 0 ? (int)round($pr['mrp'] - ($pr['mrp'] * $disc_global / 100)) : (int)$pr['price'];
        $all_products[] = $pr;
        $prod_map[$pr['name']] = $pr;
        if (!in_array($pr['cat'], $cat_list, true)) {
            $cat_list[] = $pr['cat'];
        }
    }
}
$disabled_ids = array_map('intval', $cfg['disabled_ids']);

// Build Year-wise Customer Database
$available_years = [];
$yr_res = $conn->query("SELECT DISTINCT YEAR(created_at) AS yr FROM orders WHERE created_at IS NOT NULL ORDER BY yr DESC");
if ($yr_res) {
    while ($yr_row = $yr_res->fetch_assoc()) {
        if (!empty($yr_row['yr'])) $available_years[] = (string)$yr_row['yr'];
    }
}
if (!in_array(date('Y'), $available_years, true)) {
    array_unshift($available_years, date('Y'));
}
$selected_year = isset($_GET['year']) ? trim((string)$_GET['year']) : 'ALL';

$customers_db = [];
$cust_sql = "SELECT * FROM orders";
if ($selected_year !== 'ALL' && preg_match('/^\d{4}$/', $selected_year)) {
    $cust_sql .= " WHERE YEAR(created_at) = " . (int)$selected_year;
}
$cust_sql .= " ORDER BY created_at DESC, id DESC";
$cres = $conn->query($cust_sql);
if ($cres) {
    while ($ord = $cres->fetch_assoc()) {
        $raw_phone = preg_replace('/[^0-9]/', '', (string)$ord['cust_phone']);
        $norm_phone = strlen($raw_phone) >= 10 ? substr($raw_phone, -10) : $raw_phone;
        $yr_val = !empty($ord['created_at']) ? date('Y', strtotime($ord['created_at'])) : date('Y');
        $key = ($norm_phone !== '' ? $norm_phone : strtolower(trim((string)$ord['cust_name']))) . '_' . $yr_val;

        if (!isset($customers_db[$key])) {
            $customers_db[$key] = [
                'year' => $yr_val,
                'name' => $ord['cust_name'],
                'phone' => $ord['cust_phone'],
                'city' => $ord['cust_city'],
                'orders_count' => 0,
                'total_spent' => 0,
                'last_order_code' => $ord['order_code'],
                'last_order_date' => !empty($ord['created_at']) ? date('d-m-Y', strtotime($ord['created_at'])) : '-',
                'order_codes' => []
            ];
        }
        $customers_db[$key]['orders_count']++;
        $customers_db[$key]['total_spent'] += (int)$ord['total_amount'];
        $customers_db[$key]['order_codes'][] = $ord['order_code'];
        if (empty($customers_db[$key]['city']) || $customers_db[$key]['city'] === '-') {
            $customers_db[$key]['city'] = $ord['cust_city'];
        }
    }
}
$customers_list = array_values($customers_db);
$json_flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($SHOP_NAME); ?> Admin</title>
  <style>
    body { font-family: sans-serif; background: #f4f4f4; padding: 12px; max-width: 1020px; margin: auto; }
    .box { background: #fff; padding: 16px; border-radius: 8px; margin-bottom: 14px; box-shadow: 0 2px 5px rgba(0,0,0,0.08); }
    .tabs { display: flex; gap: 8px; overflow-x: auto; margin-bottom: 14px; }
    .tab { padding: 10px 14px; background: #fff; border-radius: 6px; text-decoration: none; color: #333; font-weight: bold; font-size: 13px; border: 1px solid #ddd; white-space: nowrap; }
    .tab.active { background: #d90429; color: #fff; border-color: #d90429; }
    input, select, button { padding: 8px; margin: 4px 0; border: 1px solid #ccc; border-radius: 5px; font-size: 13px; }
    button { background: #d90429; color: #fff; border: none; font-weight: bold; cursor: pointer; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th, td { border-bottom: 1px solid #ddd; padding: 8px; text-align: left; vertical-align: top; }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; }
    .row-disabled { background: #fff5f5; opacity: 0.8; }
    .badge-off { background: #ffe3e3; color: #c92a2a; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: bold; }
    .badge-on { background: #d3f9d8; color: #2b8a3e; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: bold; }
    .badge-yr { background: #e7f5ff; color: #1864ab; padding: 2px 7px; border-radius: 4px; font-size: 11px; font-weight: bold; }
    .btn-sm { padding: 6px 10px; font-size: 12px; border-radius: 4px; font-weight: bold; color: #fff; border: none; cursor: pointer; }
    .chg-card { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px; padding: 10px; }
    .chk-label { display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: bold; color: #333; cursor: pointer; margin-top: 4px; }
    .chk-label input { width: 16px; height: 16px; margin: 0; }
    .toolbar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 12px; }
    .stat-row { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
    .stat-pill { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px; padding: 8px 12px; font-size: 12px; }
    .stat-pill strong { display: block; font-size: 16px; color: #d90429; }
    .inline-form { display: inline; margin: 0; }
    @media (max-width: 650px) { .grid-2, .grid-3 { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  <div class="box" style="display:flex;justify-content:space-between;align-items:center;">
    <h3>🎆 <?php echo htmlspecialchars($SHOP_NAME); ?> Admin</h3>
    <div style="font-size:13px;"><a href="index.php" target="_blank">Store</a> | <a href="quick-shop.php" target="_blank">Quick Shop</a> | <a href="track-order.php" target="_blank">Track</a> | <a href="?logout=1" style="color:red;">Logout</a></div>
  </div>

  <div class="tabs">
    <a href="?tab=orders" class="tab <?php echo $tab==='orders'?'active':''; ?>">📦 Orders</a>
    <a href="?tab=customers" class="tab <?php echo $tab==='customers'?'active':''; ?>">👥 Customer Database</a>
    <a href="?tab=walkin" class="tab <?php echo $tab==='walkin'?'active':''; ?>">🧾 Walk-in Bill</a>
    <a href="?tab=store" class="tab <?php echo $tab==='store'?'active':''; ?>">🏪 Store Front</a>
    <a href="?tab=payment" class="tab <?php echo $tab==='payment'?'active':''; ?>">💳 Payment & Bank</a>
    <a href="?tab=products" class="tab <?php echo $tab==='products'?'active':''; ?>">🧨 Products & MRP</a>
  </div>

  <?php if ($msg): ?>
    <div style="background:<?php echo $msg_type==='err'?'#ffe3e3':'#d4edda'; ?>;color:<?php echo $msg_type==='err'?'#c92a2a':'#155724'; ?>;padding:10px;border-radius:6px;margin-bottom:12px;font-weight:bold;font-size:13px;"><?php echo htmlspecialchars($msg); ?></div>
  <?php endif; ?>

  <?php if ($tab === 'orders'): ?>
  <div class="box" style="overflow-x:auto;">
    <h4>📦 Customer Orders & Details</h4>
    <table>
      <tr><th>Order ID</th><th>Customer Details</th><th>Total</th><th>Status, Update & Print</th></tr>
      <?php $ores = $conn->query("SELECT * FROM orders ORDER BY id DESC LIMIT 100");
      if ($ores && $ores->num_rows > 0):
        while ($o = $ores->fetch_assoc()): ?>
        <tr>
          <td><strong><?php echo htmlspecialchars($o['order_code']); ?></strong><br><small><?php echo htmlspecialchars($o['created_at']); ?></small></td>
          <td>
            <strong><?php echo htmlspecialchars($o['cust_name']); ?></strong><br>
            📞 <a href="tel:<?php echo htmlspecialchars($o['cust_phone']); ?>"><?php echo htmlspecialchars($o['cust_phone']); ?></a><br>
            📍 <?php echo htmlspecialchars($o['cust_city']); ?>
            <details style="margin-top:4px;font-size:12px;color:#444;">
              <summary style="cursor:pointer;color:#d90429;font-weight:bold;">View Items</summary>
              <pre style="white-space:pre-wrap;font-family:sans-serif;margin-top:4px;background:#f8f9fa;padding:6px;border-radius:4px;"><?php echo htmlspecialchars($o['items_text']); ?></pre>
            </details>
          </td>
          <td><strong>₹<?php echo number_format((float)$o['total_amount']); ?></strong></td>
          <td>
            <form method="POST">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
              <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">
              <select name="status">
                <?php foreach (['Pending Confirmation', 'Confirmed', 'Packed', 'Dispatched', 'Delivered', 'Walk-in Estimate'] as $st): ?>
                  <option value="<?php echo $st; ?>" <?php if ($o['status'] === $st) echo 'selected'; ?>><?php echo $st; ?></option>
                <?php endforeach; ?>
              </select><br>
              <input type="text" name="tracking_info" value="<?php echo htmlspecialchars($o['tracking_info'] ?? ''); ?>" placeholder="Lorry Name & LR No." style="width:170px;"><br>
              <button type="submit" name="update_order" style="background:#2b9348;">Update</button>
              <button type="button" onclick='printAdminOrder(<?php echo json_encode($o, $json_flags); ?>)' style="background:#1d1e22;">🖨️ Print</button>
            </form>
          </td>
        </tr>
        <?php endwhile;
      else: ?>
        <tr><td colspan="4">No customer orders yet.</td></tr>
      <?php endif; ?>
    </table>
  </div>
  <?php endif; ?>

  <?php if ($tab === 'customers'): ?>
  <div class="box" style="overflow-x:auto;">
    <div class="toolbar">
      <div>
        <h4 style="margin-bottom:2px;">👥 Year-Wise Customer Database</h4>
        <small style="color:#555;">Automatically compiled from customer orders (deduplicated by mobile number & year).</small>
      </div>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <form method="GET" style="display:flex;align-items:center;gap:6px;margin:0;">
          <input type="hidden" name="tab" value="customers">
          <label style="font-size:12px;font-weight:bold;">Year:</label>
          <select name="year" onchange="this.form.submit()" style="margin:0;">
            <option value="ALL" <?php echo $selected_year === 'ALL' ? 'selected' : ''; ?>>All Years</option>
            <?php foreach ($available_years as $yr_opt): ?>
              <option value="<?php echo htmlspecialchars($yr_opt); ?>" <?php echo $selected_year === $yr_opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($yr_opt); ?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <button type="button" onclick="exportCustomersPDF()" style="background:#1d1e22;padding:8px 14px;">📄 Export PDF</button>
      </div>
    </div>

    <?php
      $total_cust_count = count($customers_list);
      $total_cust_orders = array_sum(array_column($customers_list, 'orders_count'));
      $total_cust_revenue = array_sum(array_column($customers_list, 'total_spent'));
    ?>
    <div class="stat-row">
      <div class="stat-pill"><span>Unique Customers (<?php echo htmlspecialchars($selected_year); ?>)</span><strong><?php echo $total_cust_count; ?></strong></div>
      <div class="stat-pill"><span>Total Orders</span><strong><?php echo $total_cust_orders; ?></strong></div>
      <div class="stat-pill"><span>Total Estimate Value</span><strong>₹<?php echo number_format($total_cust_revenue); ?></strong></div>
    </div>

    <div style="margin-bottom:10px;">
      <input type="text" id="cust_search" placeholder="🔍 Filter by customer name, mobile number, city, or order ID..." onkeyup="filterCustomers()" style="width:100%;">
    </div>

    <table>
      <thead>
        <tr>
          <th style="width:45px;">S.No</th>
          <th style="width:65px;">Year</th>
          <th>Customer Name</th>
          <th>Mobile Number</th>
          <th>City / Location</th>
          <th style="text-align:center;">Orders</th>
          <th style="text-align:right;">Total Value</th>
          <th>Last Order</th>
        </tr>
      </thead>
      <tbody id="cust_tbody">
        <?php if (!empty($customers_list)):
          foreach ($customers_list as $idx => $c):
            $search_blob = strtolower($c['year'].' '.$c['name'].' '.$c['phone'].' '.$c['city'].' '.implode(' ', $c['order_codes']));
        ?>
          <tr class="cust-row" data-search="<?php echo htmlspecialchars($search_blob); ?>">
            <td><?php echo $idx + 1; ?></td>
            <td><span class="badge-yr"><?php echo htmlspecialchars($c['year']); ?></span></td>
            <td><strong><?php echo htmlspecialchars($c['name']); ?></strong></td>
            <td><a href="tel:<?php echo htmlspecialchars($c['phone']); ?>"><?php echo htmlspecialchars($c['phone']); ?></a></td>
            <td><?php echo htmlspecialchars($c['city']); ?></td>
            <td style="text-align:center;"><strong><?php echo (int)$c['orders_count']; ?></strong><br><small style="color:#666;"><?php echo htmlspecialchars(implode(', ', $c['order_codes'])); ?></small></td>
            <td style="text-align:right;"><strong style="color:#2b8a3e;">₹<?php echo number_format($c['total_spent']); ?></strong></td>
            <td><small><?php echo htmlspecialchars($c['last_order_date']); ?></small></td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="8" style="text-align:center;color:#666;padding:18px;">No customer records found for the selected year.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php if ($tab === 'walkin'): ?>
  <div class="box">
    <h4>🧾 Direct Store Visitor — Estimate Bill Generator</h4>
    <p style="font-size:12px;color:#555;margin:4px 0 12px;">Create an estimate bill for walk-in customers (no minimum order limit, active discount: <b><?php echo (float)$cfg['discount_pct']; ?>%</b>).</p>
    <form method="POST" id="walkinForm">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
      <div class="grid-3">
        <input type="text" name="w_name" id="w_name" placeholder="Visitor Name (Optional)">
        <input type="tel" name="w_phone" id="w_phone" placeholder="Mobile Number (Optional)">
        <input type="text" name="w_city" id="w_city" placeholder="City / Place (Optional)">
      </div>

      <div class="grid-2" style="margin-top:8px;">
        <div class="chg-card">
          <label style="font-size:12px;font-weight:bold;color:#2b8a3e;">➖ Extra Counter Discount (₹)</label>
          <input type="number" step="1" min="0" name="extra_disc" id="extra_disc" value="0" oninput="calcWalkin()" style="width:100%;">
          <label class="chk-label">
            <input type="checkbox" name="show_extra_disc" id="show_extra_disc" checked onchange="calcWalkin()">
            Show Extra Discount in Bill
          </label>
        </div>
        <div class="chg-card">
          <label style="font-size:12px;font-weight:bold;color:#d90429;">➕ Packing Charges (₹)</label>
          <input type="number" step="1" min="0" name="packing_chg" id="packing_chg" value="0" oninput="calcWalkin()" style="width:100%;">
          <label class="chk-label">
            <input type="checkbox" name="show_packing_chg" id="show_packing_chg" checked onchange="calcWalkin()">
            Show Packing Charges in Bill
          </label>
        </div>
      </div>

      <input type="hidden" name="w_cart_data" id="w_cart_data">

      <div style="margin:12px 0 8px;">
        <input type="text" id="w_search" placeholder="🔍 Search cracker by name or category..." onkeyup="filterWalkin()" style="width:100%;">
      </div>

      <div style="max-height:360px;overflow-y:auto;border:1px solid #ddd;border-radius:6px;">
        <table>
          <thead style="position:sticky;top:0;background:#f1f3f5;">
            <tr><th>Cracker Name</th><th>MRP</th><th>Rate (<?php echo (float)$cfg['discount_pct']; ?>% Off)</th><th style="width:65px;">Qty</th><th style="width:80px;">Total</th></tr>
          </thead>
          <tbody>
            <?php foreach ($all_products as $p): ?>
            <tr class="w-row" data-name="<?php echo strtolower(htmlspecialchars($p['name'].' '.$p['cat'])); ?>">
              <td><strong><?php echo htmlspecialchars($p['name']); ?></strong><br><small style="color:#666;"><?php echo htmlspecialchars($p['cat']); ?></small></td>
              <td>₹<?php echo (int)$p['mrp']; ?></td>
              <td><strong style="color:#d90429;">₹<?php echo (int)$p['calc_rate']; ?></strong></td>
              <td>
                <input type="number" min="0" value="0" class="w-qty"
                       data-id="<?php echo (int)$p['id']; ?>"
                       data-name="<?php echo htmlspecialchars($p['name']); ?>"
                       data-mrp="<?php echo (int)$p['mrp']; ?>"
                       data-rate="<?php echo (int)$p['calc_rate']; ?>"
                       oninput="calcWalkin()" style="width:55px;text-align:center;">
              </td>
              <td id="wsub-<?php echo (int)$p['id']; ?>">₹0</td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div style="background:#1d1e22;color:#fff;padding:12px;border-radius:6px;margin-top:12px;">
        <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;font-size:13px;color:#ccc;margin-bottom:6px;">
          <span>Items: <strong id="w-cnt" style="color:#fff;">0</strong> | Sub Total: <strong style="color:#fff;">₹<span id="w-subtot">0</span></strong></span>
          <span>
            <span id="w-disc-lbl">Discount: -₹0</span> | 
            <span id="w-pack-lbl">Packing: +₹0</span>
          </span>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;border-top:1px solid #333;padding-top:8px;">
          <div style="font-size:18px;font-weight:bold;color:#ffb703;">
            Net Payable: ₹<span id="w-tot">0</span>
          </div>
          <div>
            <button type="submit" name="save_walkin" onclick="return prepareWalkinSubmit()" style="background:#2b9348;padding:10px 14px;">💾 Save & Print Estimate PDF</button>
            <button type="button" onclick="previewWalkinPDF()" style="background:#ffb703;color:#000;padding:10px 14px;">👁️ Preview / Print Only</button>
          </div>
        </div>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <?php if ($tab === 'store'):
    $admin_banners = (!empty($cfg['banners']) && is_array($cfg['banners'])) ? $cfg['banners'] : $default_banners;
  ?>
  <div class="box">
    <h4>🏪 Store Front Settings (Discount, Min Order & Auto-Scroll Banners)</h4>
    <p style="font-size:12px;color:#555;margin:6px 0 12px;">Selling Rate is calculated as: <b>MRP − (MRP × Discount % / 100)</b>. Use <b>{DISC}</b> or <b>{MIN}</b> inside banner text to auto-insert live values.</p>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
      <div class="grid-3">
        <div>
          <label style="font-size:12px;font-weight:bold;">Store Front Discount (%)</label><br>
          <input type="number" step="0.01" min="0" max="99" name="discount_pct" value="<?php echo htmlspecialchars((string)$cfg['discount_pct']); ?>" required style="width:100%;">
        </div>
        <div>
          <label style="font-size:12px;font-weight:bold;">Minimum Order Amount (₹)</label><br>
          <input type="number" min="0" name="min_order" value="<?php echo htmlspecialchars((string)$cfg['min_order']); ?>" required style="width:100%;">
        </div>
        <div>
          <label style="font-size:12px;font-weight:bold;">Banner Auto-Scroll (Seconds)</label><br>
          <input type="number" min="2" max="30" name="banner_interval" value="<?php echo (int)($cfg['banner_interval'] ?? 4); ?>" required style="width:100%;">
        </div>
      </div>

      <hr style="border:none;border-top:1px solid #ddd;margin:16px 0;">
      <div class="toolbar">
        <h4 style="margin:0;">🎞️ Home Page Auto-Scroll Banners</h4>
        <button type="button" onclick="addBannerSlide()" style="background:#0077b6;padding:7px 12px;">➕ Add Slide</button>
      </div>

      <div id="slidesContainer">
        <?php foreach ($admin_banners as $bi => $bn): ?>
        <div class="chg-card slide-editor" style="margin-bottom:10px;">
          <div style="display:flex;justify-content:space-between;align-items:center;">
            <strong class="slide-num" style="font-size:13px;color:#d90429;">Slide #<?php echo $bi + 1; ?></strong>
            <button type="button" onclick="removeBannerSlide(this)" class="btn-sm" style="background:#c92a2a;">🗑️ Remove</button>
          </div>
          <div class="grid-2" style="margin-top:6px;">
            <div>
              <label style="font-size:11px;font-weight:bold;">Top Badge / Pill Text</label>
              <input type="text" name="b_pill[]" value="<?php echo htmlspecialchars($bn['pill'] ?? ''); ?>" placeholder="e.g. 🎆 Sivakasi Factory Direct" style="width:100%;">
            </div>
            <div>
              <label style="font-size:11px;font-weight:bold;">Main Banner Heading *</label>
              <input type="text" name="b_title[]" value="<?php echo htmlspecialchars($bn['title'] ?? ''); ?>" placeholder="e.g. Flat {DISC}% Diwali Factory Offer" required style="width:100%;">
            </div>
          </div>
          <div style="margin-top:4px;">
            <label style="font-size:11px;font-weight:bold;">Description Text</label>
            <input type="text" name="b_desc[]" value="<?php echo htmlspecialchars($bn['desc'] ?? ''); ?>" placeholder="Short promotional subtitle..." style="width:100%;">
          </div>
          <div class="grid-2" style="margin-top:4px;">
            <div>
              <label style="font-size:11px;font-weight:bold;">Button Label</label>
              <input type="text" name="b_btn_text[]" value="<?php echo htmlspecialchars($bn['btn_text'] ?? ''); ?>" placeholder="e.g. ⚡ Open Quick Price List →" style="width:100%;">
            </div>
            <div>
              <label style="font-size:11px;font-weight:bold;">Button Link</label>
              <input type="text" name="b_btn_link[]" value="<?php echo htmlspecialchars($bn['btn_link'] ?? ''); ?>" placeholder="e.g. quick-shop.php or combo.php" style="width:100%;">
            </div>
          </div>
          <div class="grid-2" style="margin-top:4px;">
            <div>
              <label style="font-size:11px;font-weight:bold;">Background Image URL (Optional)</label>
              <input type="text" name="b_bg_image[]" value="<?php echo htmlspecialchars($bn['bg_image'] ?? ''); ?>" placeholder="https://... or uploads/banners/img.jpg" style="width:100%;">
            </div>
            <div>
              <label style="font-size:11px;font-weight:bold;">Or Upload Background Image (JPG/PNG/WebP, max 5MB)</label>
              <input type="file" name="b_bg_file[]" accept="image/jpeg,image/png,image/webp,image/gif" style="width:100%;background:#fff;">
            </div>
          </div>
          <?php if (!empty($bn['bg_image'])): ?>
            <div style="margin-top:6px;display:flex;align-items:center;gap:10px;">
              <img src="<?php echo htmlspecialchars($bn['bg_image']); ?>" alt="Banner Preview" style="height:46px;width:90px;object-fit:cover;border-radius:4px;border:1px solid #ccc;">
              <small style="color:#555;">Current: <?php echo htmlspecialchars($bn['bg_image']); ?></small>
            </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>

      <button type="submit" name="save_store" style="background:#2b9348;margin-top:6px;padding:10px 16px;">💾 Save Store Front & Banners</button>
    </form>
  </div>
  <?php endif; ?>

  <?php if ($tab === 'payment'): ?>
  <div class="box">
    <h4>💳 Payment, Bank & UPI Details (Shown on PDF Estimate)</h4>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
      <div class="grid-2">
        <input type="text" name="ac_name" value="<?php echo htmlspecialchars($cfg['ac_name']); ?>" placeholder="Account Name" required>
        <input type="text" name="ac_no" value="<?php echo htmlspecialchars($cfg['ac_no']); ?>" placeholder="Account Number" required>
        <input type="text" name="ac_type" value="<?php echo htmlspecialchars($cfg['ac_type']); ?>" placeholder="A/C Type (Current/Savings)" required>
        <input type="text" name="bank_name" value="<?php echo htmlspecialchars($cfg['bank_name']); ?>" placeholder="Bank Name" required>
        <input type="text" name="ifsc" value="<?php echo htmlspecialchars($cfg['ifsc']); ?>" placeholder="IFSC Code" required>
        <input type="text" name="upi" value="<?php echo htmlspecialchars($cfg['upi']); ?>" placeholder="UPI ID (e.g. name@okaxis)" required>
        <input type="email" name="email" value="<?php echo htmlspecialchars($cfg['email']); ?>" placeholder="Shop Email" required>
        <input type="text" name="address" value="<?php echo htmlspecialchars($cfg['address']); ?>" placeholder="Shop Address" required>
      </div>
      <button type="submit" name="save_bank" style="background:#1d1e22;margin-top:8px;">Save Payment Details</button>
    </form>
  </div>
  <?php endif; ?>

  <?php if ($tab === 'products'): ?>
  <div class="box">
    <h4>+ Add New Cracker (Active Discount: <?php echo (float)$cfg['discount_pct']; ?>%)</h4>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
      <input type="text" name="name" placeholder="Product Name" required style="width:100%">
      <input type="text" name="cat" list="catOptions" placeholder="Category (e.g. Sparklers, Fountains)" required style="width:190px">
      <datalist id="catOptions">
        <?php foreach ($cat_list as $c_opt): ?>
          <option value="<?php echo htmlspecialchars($c_opt); ?>">
        <?php endforeach; ?>
      </datalist>
      <input type="text" name="icon" value="🎆" style="width:50px" placeholder="Icon">
      <input type="number" min="1" name="mrp" placeholder="MRP ₹" required style="width:110px">
      <button type="submit" name="add">Add Product</button>
    </form>
  </div>
  
  <div class="box" style="overflow-x:auto;">
    <h4>Manage Price List & Stock Status</h4>
    <table>
      <tr><th>Item</th><th>Status</th><th>MRP ₹</th><th>Calc. Rate</th><th>Action</th></tr>
      <?php foreach ($all_products as $r):
          $is_disabled = in_array($r['id'], $disabled_ids, true);
      ?>
        <tr class="<?php echo $is_disabled ? 'row-disabled' : ''; ?>">
          <td>
            <?php echo htmlspecialchars($r['icon'])." <strong>".htmlspecialchars($r['name'])."</strong>"; ?><br>
            <small style="color:#666;"><?php echo htmlspecialchars($r['cat']); ?></small>
          </td>
          <td>
            <?php if ($is_disabled): ?>
              <span class="badge-off">OUT OF STOCK</span>
            <?php else: ?>
              <span class="badge-on">IN STOCK</span>
            <?php endif; ?>
          </td>
          <td>
            <form method="POST" class="inline-form">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
              <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
              <input type="number" min="1" name="mrp" value="<?php echo (int)$r['mrp']; ?>" style="width:70px">
              <button type="submit" name="update" style="background:#2b9348;">Save</button>
            </form>
          </td>
          <td><strong style="color:#2b9348;">₹<?php echo (int)$r['calc_rate']; ?></strong></td>
          <td>
            <form method="POST" class="inline-form">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
              <input type="hidden" name="toggle_id" value="<?php echo (int)$r['id']; ?>">
              <?php if ($is_disabled): ?>
                <button type="submit" class="btn-sm" style="background:#0077b6;">Enable</button>
              <?php else: ?>
                <button type="submit" class="btn-sm" style="background:#e85d04;">Disable</button>
              <?php endif; ?>
            </form>
            <form method="POST" class="inline-form" onsubmit="return confirm('Delete item permanently?')">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
              <input type="hidden" name="del_id" value="<?php echo (int)$r['id']; ?>">
              <button type="submit" class="btn-sm" style="background:#c92a2a;margin-left:4px;">✖</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>

  <script>
    const CFG = <?php echo json_encode($cfg, $json_flags); ?>;
    const PROD_MAP = <?php echo json_encode($prod_map, $json_flags); ?>;
    const WALKIN_SAVED = <?php echo json_encode($walkin_saved_order, $json_flags); ?>;
    const CUSTOMERS_DATA = <?php echo json_encode($customers_list, $json_flags); ?>;
    const SELECTED_YEAR = <?php echo json_encode($selected_year, $json_flags); ?>;
    const SHOP_NAME_JS = <?php echo json_encode($SHOP_NAME, $json_flags); ?>;
    const PHONE_JS = <?php echo json_encode($PHONE_DISPLAY, $json_flags); ?>;

    function esc(str) {
      return String(str ?? '').replace(/[&<>"']/g, function(s) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[s];
      });
    }

    function buildEstimateHTML(meta) {
      let rows = "", subTot = 0, totalQty = 0;
      const defaultDisc = parseFloat(CFG.discount_pct) || 0;
      let minDisc = null, maxDisc = null;

      meta.items.forEach(function(it, i) {
        const mrp = parseFloat(it.mrp) || 0;
        const rate = parseFloat(it.price) || 0;
        const qty = parseInt(it.qty) || 0;
        const sub = parseFloat(it.sub) || 0;
        const pct = mrp > 0 ? Math.round(((mrp - rate) / mrp) * 100) : defaultDisc;
        if (minDisc === null || pct < minDisc) minDisc = pct;
        if (maxDisc === null || pct > maxDisc) maxDisc = pct;

        const discAmt = Math.max(0, (mrp - rate) * qty);
        subTot += sub;
        totalQty += qty;

        rows += '<tr>' +
          '<td style="text-align:center;">' + (i + 1) + '</td>' +
          '<td style="text-align:center;">' + esc(it.id) + '</td>' +
          '<td>' + esc(it.name) + '</td>' +
          '<td style="text-align:center;">' + qty + '</td>' +
          '<td style="text-align:right;">' + mrp.toFixed(2) + '</td>' +
          '<td style="text-align:right;">' + discAmt.toFixed(2) + '</td>' +
          '<td style="text-align:right;">' + rate.toFixed(2) + '</td>' +
          '<td style="text-align:right;">' + sub.toFixed(2) + '</td>' +
        '</tr>';
      });

      const discRangeText = (minDisc === null)
        ? (defaultDisc + '%')
        : (minDisc === maxDisc ? (minDisc + '%') : (minDisc + '% - ' + maxDisc + '%'));

      const showDisc = meta.show_extra_disc && parseFloat(meta.extra_disc) > 0;
      const showPack = meta.show_packing_chg && parseFloat(meta.packing_chg) > 0;
      const extraDiscVal = showDisc ? parseFloat(meta.extra_disc) : 0;
      const packChgVal = showPack ? parseFloat(meta.packing_chg) : 0;
      const rawTotal = Math.max(0, subTot - extraDiscVal + packChgVal);
      const grand = meta.total !== undefined ? parseInt(meta.total) : Math.round(rawTotal);
      const rnd = meta.round_off !== undefined ? parseFloat(meta.round_off) : +(grand - rawTotal).toFixed(2);
      const spanRows = 2 + (showDisc ? 1 : 0) + (showPack ? 1 : 0) + 1;

      let summaryRows = '<tr class="tot-row">' +
        '<td colspan="5" rowspan="' + spanRows + '" class="items-summary-cell">' +
          '<strong>Total Items : ' + meta.items.length + '</strong> &nbsp;|&nbsp; <strong>Total Qty : ' + totalQty + '</strong><br>' +
          '<span style="font-size:10.5px;color:#333;">Discount Range Applied : <b>' + esc(discRangeText) + '</b></span>' +
        '</td>' +
        '<td colspan="2" class="lbl-cell">Sub Total</td>' +
        '<td class="val-cell">' + subTot.toFixed(2) + '</td>' +
      '</tr>';

      if (showDisc) {
        summaryRows += '<tr class="tot-row">' +
          '<td colspan="2" class="lbl-cell">Extra Discount</td>' +
          '<td class="val-cell">- ' + extraDiscVal.toFixed(2) + '</td>' +
        '</tr>';
      }
      if (showPack) {
        summaryRows += '<tr class="tot-row">' +
          '<td colspan="2" class="lbl-cell">Packing Charges</td>' +
          '<td class="val-cell">+ ' + packChgVal.toFixed(2) + '</td>' +
        '</tr>';
      }
      summaryRows += '<tr class="tot-row">' +
        '<td colspan="2" class="lbl-cell">Round Off</td>' +
        '<td class="val-cell">' + (rnd >= 0 ? '+' : '') + rnd.toFixed(2) + '</td>' +
      '</tr>' +
      '<tr class="tot-row overall-highlight">' +
        '<td colspan="2" class="lbl-cell">Overall Amount</td>' +
        '<td class="val-cell">' + grand.toFixed(2) + '</td>' +
      '</tr>';

      return '<!DOCTYPE html><html><head>' +
        '<title>' + esc(SHOP_NAME_JS) + ' - Estimate ' + esc(meta.code) + '</title>' +
        '<style>' +
          '@page { size: A4 portrait; margin: 8mm; }' +
          '* { box-sizing: border-box; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }' +
          'body { font-family: Arial, sans-serif; margin: 0; padding: 0; color: #000; font-size: 11.5px; }' +
          '.page-wrap { border: 1.5px solid #000; min-height: 277mm; display: flex; flex-direction: column; justify-content: space-between; }' +
          '.top-section { width: 100%; }' +
          '.row-flex { display: flex; justify-content: space-between; padding: 5px 8px; border-bottom: 1px solid #000; }' +
          '.shop-box { text-align: center; padding: 7px 8px; border-bottom: 1px solid #000; line-height: 1.45; }' +
          '.shop-box h2 { margin: 0 0 2px; font-size: 16px; letter-spacing: 0.5px; }' +
          '.shop-contact { margin-top: 3px; font-weight: bold; font-size: 11px; }' +
          '.cust-bank { display: flex; border-bottom: 1px solid #000; }' +
          '.cust-col { width: 52%; padding: 7px 8px; border-right: 1px solid #000; line-height: 1.45; }' +
          '.bank-col { width: 48%; padding: 7px 8px; line-height: 1.4; }' +
          '.bank-table td { padding: 1px 4px; border: none; font-size: 11px; }' +
          'table.items { width: 100%; border-collapse: collapse; table-layout: fixed; }' +
          'table.items th, table.items td { border: 1px solid #000; padding: 4px 5px; font-size: 11px; word-wrap: break-word; }' +
          'table.items thead th { background: #f2f2f2; border-top: none; }' +
          'table.items th:first-child, table.items td:first-child { border-left: none; }' +
          'table.items th:last-child, table.items td:last-child { border-right: none; }' +
          '.bottom-section { width: 100%; margin-top: auto; }' +
          'table.summary-tbl { width: 100%; border-collapse: collapse; table-layout: fixed; }' +
          'table.summary-tbl td { border: 1px solid #000; padding: 5px 6px; font-size: 11.5px; }' +
          'table.summary-tbl td:first-child { border-left: none; }' +
          'table.summary-tbl td:last-child { border-right: none; }' +
          'table.summary-tbl tr:last-child td { border-bottom: none; }' +
          '.tot-row td { font-weight: bold; }' +
          '.items-summary-cell { vertical-align: middle; text-align: left; background: #fff; line-height: 1.5; }' +
          '.lbl-cell { text-align: right; white-space: nowrap; width: 30%; }' +
          '.val-cell { text-align: right; white-space: nowrap; width: 14%; }' +
          '.overall-highlight td.lbl-cell, .overall-highlight td.val-cell { background-color: #dcdcdc !important; font-size: 12.5px; }' +
        '</style></head><body>' +
        '<div class="page-wrap"><div class="top-section">' +
          '<div class="row-flex"><span><b>Enquiry No :</b> ' + esc(meta.code) + '</span><strong style="font-size:14px;">ESTIMATE</strong><span><b>Date :</b> ' + esc(meta.date) + '</span></div>' +
          '<div class="shop-box"><h2>' + esc(SHOP_NAME_JS) + '</h2><div>' + esc(CFG.address) + '</div><div class="shop-contact">Mobile : ' + esc(PHONE_JS) + ' &nbsp;|&nbsp; E-mail : ' + esc(CFG.email) + '</div></div>' +
          '<div class="cust-bank">' +
            '<div class="cust-col"><strong>Customer Details</strong><br>' + esc(meta.name) + '<br>' + esc(meta.phone) + '<br>' + esc(meta.city) + '</div>' +
            '<div class="bank-col"><table class="bank-table">' +
              '<tr><td><b>A/C Name</b></td><td>: ' + esc(CFG.ac_name) + '</td></tr>' +
              '<tr><td><b>A/C Number</b></td><td>: ' + esc(CFG.ac_no) + '</td></tr>' +
              '<tr><td><b>A/C Type</b></td><td>: ' + esc(CFG.ac_type) + '</td></tr>' +
              '<tr><td><b>Bank Name</b></td><td>: ' + esc(CFG.bank_name) + '</td></tr>' +
              '<tr><td><b>IFSC Code</b></td><td>: ' + esc(CFG.ifsc) + '</td></tr>' +
              '<tr><td><b>UPI ID</b></td><td>: ' + esc(CFG.upi) + '</td></tr>' +
            '</table></div>' +
          '</div>' +
          '<table class="items"><thead><tr>' +
            '<th style="width:6%;">S.No</th><th style="width:7%;">Code</th><th style="width:33%;">Product Name</th><th style="width:7%;">Qty</th>' +
            '<th style="width:11%;">MRP</th><th style="width:12%;">Disc (' + esc(discRangeText) + ')</th><th style="width:11%;">Rate</th><th style="width:13%;">Amount</th>' +
          '</tr></thead><tbody>' + rows + '</tbody></table>' +
        '</div>' +
        '<div class="bottom-section"><table class="summary-tbl">' +
          '<colgroup><col style="width:6%;"><col style="width:7%;"><col style="width:33%;"><col style="width:7%;"><col style="width:11%;"><col style="width:15%;"><col style="width:8%;"><col style="width:13%;"></colgroup>' +
          '<tbody>' + summaryRows + '</tbody>' +
        '</table></div></div>' +
        '<script>window.onload = function() { window.print(); };<\/script></body></html>';
    }

    function openPrintWindow(html) {
      const w = window.open("", "_blank");
      if (w) { w.document.write(html); w.document.close(); }
    }

    function printAdminOrder(ord) {
      const lines = (ord.items_text || "").trim().split("\n");
      let list = [], idx = 1;
      let extraDisc = 0, showExtraDisc = false, packingChg = 0, showPackingChg = false, roundOff = undefined;
      const discPct = parseFloat(CFG.discount_pct) || 0;

      lines.forEach(function(line) {
        const m = line.match(/^\d+\.\s*(.+?)\s*x\s*(\d+)\s*=\s*₹([\d.]+)/i);
        if (m) {
          const name = m[1].trim(), qty = parseInt(m[2]), sub = parseFloat(m[3]);
          const rate = qty > 0 ? (sub / qty) : sub;
          const dbItem = PROD_MAP[name];
          const mrp = dbItem ? parseFloat(dbItem.mrp) : (discPct > 0 && discPct < 100 ? Math.round(rate / (1 - discPct / 100)) : rate);
          const code = dbItem ? dbItem.id : idx++;
          list.push({ id: code, name: name, mrp: mrp, price: rate, qty: qty, sub: sub });
        } else {
          const dMatch = line.match(/^Extra Discount:\s*-₹([\d.]+)/i);
          if (dMatch) { extraDisc = parseFloat(dMatch[1]); showExtraDisc = true; }
          const pMatch = line.match(/^Packing Charges(?:\s*\([^)]+\))?:\s*\+?₹([\d.]+)/i);
          if (pMatch) { packingChg = parseFloat(pMatch[1]); showPackingChg = true; }
          const rMatch = line.match(/^Round Off:\s*([+-]?[\d.]+)/i);
          if (rMatch) { roundOff = parseFloat(rMatch[1]); }
        }
      });

      const dt = (ord.created_at || "").split(" ")[0] || "";
      openPrintWindow(buildEstimateHTML({
        code: ord.order_code,
        date: dt,
        name: ord.cust_name,
        phone: ord.cust_phone,
        city: ord.cust_city,
        items: list,
        extra_disc: extraDisc,
        show_extra_disc: showExtraDisc,
        packing_chg: packingChg,
        show_packing_chg: showPackingChg,
        round_off: roundOff,
        total: ord.total_amount
      }));
    }

    function filterCustomers() {
      const inp = document.getElementById("cust_search");
      if (!inp) return;
      const q = inp.value.toLowerCase().trim();
      document.querySelectorAll(".cust-row").forEach(function(tr) {
        tr.style.display = tr.dataset.search.includes(q) ? "" : "none";
      });
    }

    function exportCustomersPDF() {
      const searchInp = document.getElementById("cust_search");
      const q = searchInp ? searchInp.value.toLowerCase().trim() : "";
      const filtered = CUSTOMERS_DATA.filter(function(c) {
        if (!q) return true;
        const blob = (c.year + ' ' + c.name + ' ' + c.phone + ' ' + c.city + ' ' + c.order_codes.join(' ')).toLowerCase();
        return blob.includes(q);
      });

      if (!filtered.length) {
        alert("No customer records to export!");
        return;
      }

      let rows = "", totalOrders = 0, totalSpent = 0;
      filtered.forEach(function(c, i) {
        totalOrders += parseInt(c.orders_count) || 0;
        totalSpent += parseFloat(c.total_spent) || 0;
        rows += '<tr>' +
          '<td style="text-align:center;">' + (i + 1) + '</td>' +
          '<td style="text-align:center;">' + esc(c.year) + '</td>' +
          '<td><strong>' + esc(c.name) + '</strong></td>' +
          '<td style="text-align:center;">' + esc(c.phone) + '</td>' +
          '<td>' + esc(c.city) + '</td>' +
          '<td style="text-align:center;">' + (parseInt(c.orders_count) || 0) + '</td>' +
          '<td style="text-align:right;">' + (parseFloat(c.total_spent) || 0).toFixed(2) + '</td>' +
          '<td style="text-align:center;">' + esc(c.last_order_date) + '</td>' +
        '</tr>';
      });

      const today = new Date();
      const dd = String(today.getDate()).padStart(2, '0') + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + today.getFullYear();
      const yrLabel = SELECTED_YEAR === 'ALL' ? 'All Years' : 'Year ' + SELECTED_YEAR;

      const html = '<!DOCTYPE html><html><head>' +
        '<title>' + esc(SHOP_NAME_JS) + ' - Customer Database (' + esc(yrLabel) + ')</title>' +
        '<style>' +
          '@page { size: A4 portrait; margin: 8mm; }' +
          '* { box-sizing: border-box; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }' +
          'body { font-family: Arial, sans-serif; margin: 0; padding: 0; color: #000; font-size: 11px; }' +
          '.page-wrap { border: 1.5px solid #000; min-height: 277mm; display: flex; flex-direction: column; justify-content: space-between; }' +
          '.row-flex { display: flex; justify-content: space-between; padding: 5px 8px; border-bottom: 1px solid #000; }' +
          '.shop-box { text-align: center; padding: 7px 8px; border-bottom: 1px solid #000; line-height: 1.45; }' +
          '.shop-box h2 { margin: 0 0 2px; font-size: 16px; }' +
          '.shop-contact { margin-top: 3px; font-weight: bold; font-size: 11px; }' +
          'table.cust-tbl { width: 100%; border-collapse: collapse; }' +
          'table.cust-tbl th, table.cust-tbl td { border: 1px solid #000; padding: 5px 6px; font-size: 10.5px; }' +
          'table.cust-tbl thead th { background: #f2f2f2; border-top: none; }' +
          'table.cust-tbl th:first-child, table.cust-tbl td:first-child { border-left: none; }' +
          'table.cust-tbl th:last-child, table.cust-tbl td:last-child { border-right: none; }' +
          '.bottom-section { margin-top: auto; border-top: 1.5px solid #000; }' +
          '.summary-bar { display: flex; justify-content: space-between; padding: 8px 10px; background: #dcdcdc !important; font-weight: bold; font-size: 12px; }' +
        '</style></head><body>' +
        '<div class="page-wrap"><div>' +
          '<div class="row-flex"><span><b>Report :</b> Customer Database (' + esc(yrLabel) + ')</span><strong style="font-size:13px;">CUSTOMER DIRECTORY</strong><span><b>Export Date :</b> ' + esc(dd) + '</span></div>' +
          '<div class="shop-box"><h2>' + esc(SHOP_NAME_JS) + '</h2><div>' + esc(CFG.address) + '</div><div class="shop-contact">Mobile : ' + esc(PHONE_JS) + ' &nbsp;|&nbsp; E-mail : ' + esc(CFG.email) + '</div></div>' +
          '<table class="cust-tbl"><thead><tr>' +
            '<th style="width:6%;">S.No</th><th style="width:7%;">Year</th><th style="width:22%;">Customer Name</th><th style="width:14%;">Mobile</th>' +
            '<th style="width:22%;">City / Location</th><th style="width:7%;">Orders</th><th style="width:12%;">Total Value (₹)</th><th style="width:10%;">Last Order</th>' +
          '</tr></thead><tbody>' + rows + '</tbody></table>' +
        '</div>' +
        '<div class="bottom-section"><div class="summary-bar">' +
          '<span>Total Unique Customers : ' + filtered.length + '</span>' +
          '<span>Total Orders : ' + totalOrders + '</span>' +
          '<span>Overall Estimate Value : ₹' + totalSpent.toFixed(2) + '</span>' +
        '</div></div></div>' +
        '<script>window.onload = function() { window.print(); };<\/script></body></html>';

      openPrintWindow(html);
    }

    let walkinCart = {};
    function filterWalkin() {
      const q = document.getElementById("w_search").value.toLowerCase();
      document.querySelectorAll(".w-row").forEach(function(tr) {
        tr.style.display = tr.dataset.name.includes(q) ? "" : "none";
      });
    }

    function calcWalkin() {
      walkinCart = {};
      let cnt = 0, subTot = 0;
      document.querySelectorAll(".w-qty").forEach(function(inp) {
        const id = inp.dataset.id, rate = parseFloat(inp.dataset.rate), qty = Math.max(0, parseInt(inp.value) || 0), sub = rate * qty;
        document.getElementById("wsub-" + id).innerText = "₹" + sub;
        if (qty > 0) { walkinCart[id] = qty; cnt += qty; subTot += sub; }
      });

      const extraDisc = Math.max(0, parseFloat(document.getElementById("extra_disc").value) || 0);
      const showDisc = document.getElementById("show_extra_disc").checked;
      const packChg = Math.max(0, parseFloat(document.getElementById("packing_chg").value) || 0);
      const showPack = document.getElementById("show_packing_chg").checked;

      const appliedDisc = showDisc ? extraDisc : 0;
      const appliedPack = showPack ? packChg : 0;
      const netTotal = Math.round(Math.max(0, subTot - appliedDisc + appliedPack));

      document.getElementById("w-cnt").innerText = cnt;
      document.getElementById("w-subtot").innerText = subTot;
      document.getElementById("w-disc-lbl").innerText = showDisc ? ('Discount: -₹' + extraDisc) : 'Discount: Hidden';
      document.getElementById("w-pack-lbl").innerText = showPack ? ('Packing: +₹' + packChg) : 'Packing: Hidden';
      document.getElementById("w-tot").innerText = netTotal;
      document.getElementById("w_cart_data").value = JSON.stringify(walkinCart);
    }

    function getWalkinList() {
      const list = [];
      document.querySelectorAll(".w-qty").forEach(function(inp) {
        const qty = Math.max(0, parseInt(inp.value) || 0);
        if (qty > 0) {
          const rate = parseFloat(inp.dataset.rate);
          list.push({
            id: inp.dataset.id,
            name: inp.dataset.name,
            mrp: parseFloat(inp.dataset.mrp),
            price: rate,
            qty: qty,
            sub: rate * qty
          });
        }
      });
      return list;
    }

    function prepareWalkinSubmit() {
      const list = getWalkinList();
      if (!list.length) { alert("Please enter quantity for at least one cracker!"); return false; }
      document.getElementById("w_cart_data").value = JSON.stringify(walkinCart);
      return true;
    }

    function previewWalkinPDF() {
      const list = getWalkinList();
      if (!list.length) return alert("Please enter quantity for at least one cracker!");
      const d = new Date(), dd = String(d.getDate()).padStart(2,'0')+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+d.getFullYear();
      openPrintWindow(buildEstimateHTML({
        code: "WALK-IN",
        date: dd,
        name: document.getElementById("w_name").value || "Customer",
        phone: document.getElementById("w_phone").value || "-",
        city: document.getElementById("w_city").value || "Sivakasi (Direct)",
        items: list,
        extra_disc: parseFloat(document.getElementById("extra_disc").value) || 0,
        show_extra_disc: document.getElementById("show_extra_disc").checked,
        packing_chg: parseFloat(document.getElementById("packing_chg").value) || 0,
        show_packing_chg: document.getElementById("show_packing_chg").checked
      }));
    }

    if (WALKIN_SAVED) {
      openPrintWindow(buildEstimateHTML(WALKIN_SAVED));
    }
    
        function renumberSlides() {
      document.querySelectorAll("#slidesContainer .slide-editor").forEach(function(card, idx) {
        const lbl = card.querySelector(".slide-num");
        if (lbl) lbl.innerText = "Slide #" + (idx + 1);
      });
    }

    function addBannerSlide() {
      const container = document.getElementById("slidesContainer");
      if (!container) return;
      const idx = container.querySelectorAll(".slide-editor").length + 1;
      const div = document.createElement("div");
      div.className = "chg-card slide-editor";
      div.style.marginBottom = "10px";
      div.innerHTML =
        '<div style="display:flex;justify-content:space-between;align-items:center;">' +
          '<strong class="slide-num" style="font-size:13px;color:#d90429;">Slide #' + idx + '</strong>' +
          '<button type="button" onclick="removeBannerSlide(this)" class="btn-sm" style="background:#c92a2a;">🗑️ Remove</button>' +
        '</div>' +
        '<div class="grid-2" style="margin-top:6px;">' +
          '<div><label style="font-size:11px;font-weight:bold;">Top Badge / Pill Text</label>' +
          '<input type="text" name="b_pill[]" placeholder="e.g. 🎆 Sivakasi Factory Direct" style="width:100%;"></div>' +
          '<div><label style="font-size:11px;font-weight:bold;">Main Banner Heading *</label>' +
          '<input type="text" name="b_title[]" placeholder="e.g. Flat {DISC}% Diwali Factory Offer" required style="width:100%;"></div>' +
        '</div>' +
        '<div style="margin-top:4px;"><label style="font-size:11px;font-weight:bold;">Description Text</label>' +
        '<input type="text" name="b_desc[]" placeholder="Short promotional subtitle..." style="width:100%;"></div>' +
        '<div class="grid-2" style="margin-top:4px;">' +
          '<div><label style="font-size:11px;font-weight:bold;">Button Label</label>' +
          '<input type="text" name="b_btn_text[]" value="Shop Now →" style="width:100%;"></div>' +
          '<div><label style="font-size:11px;font-weight:bold;">Button Link</label>' +
          '<input type="text" name="b_btn_link[]" value="quick-shop.php" style="width:100%;"></div>' +
        '</div>' +
        '<div class="grid-2" style="margin-top:4px;">' +
          '<div><label style="font-size:11px;font-weight:bold;">Background Image URL (Optional)</label>' +
          '<input type="text" name="b_bg_image[]" placeholder="https://..." style="width:100%;"></div>' +
          '<div><label style="font-size:11px;font-weight:bold;">Or Upload Background Image (JPG/PNG/WebP, max 5MB)</label>' +
          '<input type="file" name="b_bg_file[]" accept="image/jpeg,image/png,image/webp,image/gif" style="width:100%;background:#fff;"></div>' +
        '</div>';
      container.appendChild(div);
    }

    function removeBannerSlide(btn) {
      const container = document.getElementById("slidesContainer");
      if (!container) return;
      if (container.querySelectorAll(".slide-editor").length <= 1) {
        alert("At least 1 banner slide is required.");
        return;
      }
      btn.closest(".slide-editor").remove();
      renumberSlides();
    }

  </script>
</body>
</html>