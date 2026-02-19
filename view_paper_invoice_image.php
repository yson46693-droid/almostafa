<?php
/**
 * عرض صورة الفاتورة الورقية في صفحة كاملة (الصورة تظهر كاملة ضمن الشاشة)
 * الاستخدام: view_paper_invoice_image.php?type=local&id=123 أو type=shipping&id=456
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/path_helper.php';

if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    header('Location: ' . (getRelativeUrl('index.php') ?: 'index.php'));
    exit;
}

$type = isset($_GET['type']) ? trim($_GET['type']) : '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!in_array($type, ['local', 'shipping'], true) || $id <= 0) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html dir="rtl"><head><meta charset="utf-8"><title>خطأ</title></head><body><p>معرف أو نوع غير صالح.</p></body></html>';
    exit;
}

$imgUrl = $type === 'local'
    ? getRelativeUrl('api/local_paper_invoice.php?action=view_image&id=' . $id)
    : getRelativeUrl('api/shipping_company_paper_invoice.php?action=view_image&id=' . $id);
$base = getBasePath();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>عرض الفاتورة الورقية</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; overflow: auto; background: #1a1a1a; }
        .toolbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 10;
            background: rgba(0,0,0,0.85);
            color: #fff;
            padding: 0.5rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
        }
        .toolbar a, .toolbar button {
            color: #fff;
            text-decoration: none;
            padding: 0.35rem 0.75rem;
            border: 1px solid rgba(255,255,255,0.5);
            border-radius: 4px;
            background: transparent;
            cursor: pointer;
            font-size: 0.9rem;
        }
        .toolbar a:hover, .toolbar button:hover { background: rgba(255,255,255,0.15); }
        .wrap {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 3rem 1rem 1rem;
        }
        .wrap img {
            max-width: 100%;
            max-height: calc(100vh - 4rem);
            width: auto;
            height: auto;
            object-fit: contain;
            display: block;
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <span>عرض الفاتورة الورقية</span>
        <div>
            <a href="<?php echo htmlspecialchars($base ?: '/'); ?>dashboard/accountant.php?page=invoices">العودة للفواتير</a>
            <button type="button" onclick="window.close();">إغلاق</button>
        </div>
    </div>
    <div class="wrap">
        <img src="<?php echo htmlspecialchars($imgUrl); ?>" alt="صورة الفاتورة">
    </div>
</body>
</html>
