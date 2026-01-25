<?php
/**
 * صفحة طباعة العملاء المدينين
 * جدول بسيط جداً يعرض جميع العملاء المدينين
 */

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/path_helper.php';

// التحقق من تسجيل الدخول
if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$currentUser = getCurrentUser();
$currentRole = strtolower((string)($currentUser['role'] ?? ''));

// التحقق من الصلاحيات
$allowedRoles = ['manager', 'developer', 'accountant', 'sales'];
if (!in_array($currentRole, $allowedRoles, true)) {
    die('غير مصرح لك بالوصول إلى هذه الصفحة');
}

$db = db();

// جلب جميع العملاء المدينين
$allDebtors = [];

// 1. عملاء الشركة
$companyCustomers = $db->query(
    "SELECT c.*, r.name as region_name
     FROM customers c
     LEFT JOIN regions r ON c.region_id = r.id
     WHERE c.status = 'active' AND c.balance > 0
       AND (c.rep_id IS NULL OR c.rep_id = 0)
     ORDER BY c.name ASC"
);

foreach ($companyCustomers as $customer) {
    $customerId = (int)($customer['id'] ?? 0);
    
    // جلب أرقام الهواتف الإضافية
    $additionalPhones = [];
    try {
        $phones = $db->query(
            "SELECT phone FROM customer_phones WHERE customer_id = ? AND is_primary = 0 ORDER BY id ASC",
            [$customerId]
        );
        
        foreach ($phones as $phoneRow) {
            $phone = trim($phoneRow['phone'] ?? '');
            if (!empty($phone)) {
                $additionalPhones[] = $phone;
            }
        }
    } catch (Exception $e) {
        // تجاهل الأخطاء
    }
    
    $balance = isset($customer['balance']) ? (float)$customer['balance'] : 0.0;
    $phone = trim($customer['phone'] ?? '');
    if (!empty($additionalPhones)) {
        $phone .= (!empty($phone) ? '، ' : '') . implode('، ', $additionalPhones);
    }
    
    $allDebtors[] = [
        'id' => $customerId,
        'name' => trim($customer['name'] ?? ''),
        'phone' => $phone,
        'balance' => $balance,
        'address' => trim($customer['address'] ?? ''),
        'region_name' => trim($customer['region_name'] ?? ''),
    ];
}

// 2. العملاء المحليين
$localCustomers = $db->query(
    "SELECT c.*, r.name as region_name
     FROM local_customers c
     LEFT JOIN regions r ON c.region_id = r.id
     WHERE c.status = 'active' AND c.balance > 0
     ORDER BY c.name ASC"
);

foreach ($localCustomers as $customer) {
    $customerId = (int)($customer['id'] ?? 0);
    
    // جلب أرقام الهواتف الإضافية
    $additionalPhones = [];
    try {
        $phones = $db->query(
            "SELECT phone FROM local_customer_phones WHERE customer_id = ? AND is_primary = 0 ORDER BY id ASC",
            [$customerId]
        );
        
        foreach ($phones as $phoneRow) {
            $phone = trim($phoneRow['phone'] ?? '');
            if (!empty($phone)) {
                $additionalPhones[] = $phone;
            }
        }
    } catch (Exception $e) {
        // تجاهل الأخطاء
    }
    
    $balance = isset($customer['balance']) ? (float)$customer['balance'] : 0.0;
    $phone = trim($customer['phone'] ?? '');
    if (!empty($additionalPhones)) {
        $phone .= (!empty($phone) ? '، ' : '') . implode('، ', $additionalPhones);
    }
    
    $allDebtors[] = [
        'id' => $customerId,
        'name' => trim($customer['name'] ?? ''),
        'phone' => $phone,
        'balance' => $balance,
        'address' => trim($customer['address'] ?? ''),
        'region_name' => trim($customer['region_name'] ?? ''),
    ];
}

// 3. عملاء المندوبين
$repCustomers = $db->query(
    "SELECT c.*, r.name as region_name
     FROM customers c
     LEFT JOIN regions r ON c.region_id = r.id
     WHERE c.status = 'active' 
       AND c.balance > 0
       AND (c.rep_id IS NOT NULL AND c.rep_id > 0)
     ORDER BY c.name ASC"
);

foreach ($repCustomers as $customer) {
    $customerId = (int)($customer['id'] ?? 0);
    
    // جلب أرقام الهواتف الإضافية
    $additionalPhones = [];
    try {
        $phones = $db->query(
            "SELECT phone FROM customer_phones WHERE customer_id = ? AND is_primary = 0 ORDER BY id ASC",
            [$customerId]
        );
        
        foreach ($phones as $phoneRow) {
            $phone = trim($phoneRow['phone'] ?? '');
            if (!empty($phone)) {
                $additionalPhones[] = $phone;
            }
        }
    } catch (Exception $e) {
        // تجاهل الأخطاء
    }
    
    $balance = isset($customer['balance']) ? (float)$customer['balance'] : 0.0;
    $phone = trim($customer['phone'] ?? '');
    if (!empty($additionalPhones)) {
        $phone .= (!empty($phone) ? '، ' : '') . implode('، ', $additionalPhones);
    }
    
    $allDebtors[] = [
        'id' => $customerId,
        'name' => trim($customer['name'] ?? ''),
        'phone' => $phone,
        'balance' => $balance,
        'address' => trim($customer['address'] ?? ''),
        'region_name' => trim($customer['region_name'] ?? ''),
    ];
}

// ترتيب حسب الاسم
usort($allDebtors, function($a, $b) {
    return strcmp($a['name'], $b['name']);
});

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>طباعة العملاء المدينين - <?php echo APP_NAME; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            font-size: 10px;
            padding: 7px;
            background: white;
        }
        
        .header {
            text-align: center;
            margin-bottom: 5px;
            padding-bottom: 3px;
            border-bottom: 1px solid #000;
        }
        
        .header h1 {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 2px;
        }
        
        .header .date {
            font-size: 8px;
            color: #666;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 7px;
            margin-top: 3px;
        }
        
        table th {
            background-color: #f0f0f0;
            border: 1px solid #000;
            padding: 2px 3px;
            text-align: center;
            font-weight: bold;
            font-size: 7px;
        }
        
        table td {
            border: 1px solid #000;
            padding: 1px 2px;
            text-align: right;
            font-size: 6.5px;
            line-height: 1.2;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        table tr {
            height: 6px;
        }
        
        .id-col {
            width: 4%;
            text-align: center;
        }
        
        .name-col {
            width: 18%;
        }
        
        .phone-col {
            width: 12%;
            text-align: center;
        }
        
        .balance-col {
            width: 10%;
            text-align: center;
            font-weight: bold;
        }
        
        .address-col {
            width: 35%;
        }
        
        .region-col {
            width: 11%;
            text-align: center;
        }
        
        @media print {
            body {
                padding: 0;
            }
            
            .no-print {
                display: none;
            }
            
            table {
                page-break-inside: auto;
            }
            
            tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
        }
        
        .print-btn {
            position: fixed;
            top: 10px;
            left: 10px;
            padding: 8px 16px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            z-index: 1000;
        }
        
        .print-btn:hover {
            background: #0056b3;
        }
    </style>
</head>
<body>
    <button class="print-btn no-print" onclick="window.print()">
        <i class="bi bi-printer"></i> طباعة
    </button>
    
    <div class="header">
        <h1>قائمة العملاء المدينين</h1>
        <div class="date">تاريخ الطباعة: <?php echo date('Y-m-d H:i'); ?></div>
        <div class="date">إجمالي العملاء: <?php echo count($allDebtors); ?></div>
    </div>
    
    <table>
        <thead>
            <tr>
                <th class="id-col">ID</th>
                <th class="name-col">اسم العميل</th>
                <th class="phone-col">رقم الهاتف</th>
                <th class="balance-col">الرصيد</th>
                <th class="address-col">العنوان</th>
                <th class="region-col">المنطقة</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($allDebtors)): ?>
                <tr>
                    <td colspan="6" style="text-align: center; padding: 15px; font-size: 9px;">لا توجد عملاء مدينين</td>
                </tr>
            <?php else: ?>
                <?php foreach ($allDebtors as $customer): ?>
                    <tr>
                        <td class="id-col" style="font-size: 6.5px;"><?php echo htmlspecialchars((string)$customer['id']); ?></td>
                        <td class="name-col" style="font-size: 6.5px;"><?php echo htmlspecialchars($customer['name']); ?></td>
                        <td class="phone-col" style="font-size: 6.5px;"><?php echo htmlspecialchars($customer['phone']); ?></td>
                        <td class="balance-col" style="font-size: 6.5px; font-weight: bold;"><?php echo number_format($customer['balance'], 2); ?> ج.م</td>
                        <td class="address-col" style="font-size: 6.5px;"><?php echo htmlspecialchars($customer['address']); ?></td>
                        <td class="region-col" style="font-size: 6.5px;"><?php echo htmlspecialchars($customer['region_name']); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    
    <script>
        // طباعة تلقائية عند تحميل الصفحة
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>
</body>
</html>
