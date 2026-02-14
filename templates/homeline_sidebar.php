<?php
/**
 * Homeline Style Sidebar - Modern Collapsible Sidebar
 * شريط جانبي حديث قابل للطي بتصميم Homeline
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../includes/path_helper.php';

// التأكد من أن $currentUser موجود - إذا لم يكن موجوداً، محاولة تحميله
if (!isset($currentUser) || $currentUser === null) {
    // محاولة تحميل auth.php إذا لم يكن محملاً
    if (!function_exists('getCurrentUser')) {
        if (!defined('ACCESS_ALLOWED')) {
            define('ACCESS_ALLOWED', true);
        }
        require_once __DIR__ . '/../includes/config.php';
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/../includes/auth.php';
    }
    if (function_exists('getCurrentUser')) {
        $currentUser = getCurrentUser();
    }
}

// التأكد من أن $lang موجود
if (!isset($lang) || empty($lang)) {
    if (!function_exists('getCurrentLanguage')) {
        if (!defined('ACCESS_ALLOWED')) {
            define('ACCESS_ALLOWED', true);
        }
        require_once __DIR__ . '/../includes/config.php';
    }
    if (function_exists('getCurrentLanguage')) {
        $currentLang = getCurrentLanguage();
        $langFile = __DIR__ . '/../includes/lang/' . $currentLang . '.php';
        if (file_exists($langFile)) {
            require_once $langFile;
        }
        if (isset($translations)) {
            $lang = $translations;
        }
    }
}

$currentPage = basename($_SERVER['PHP_SELF']);
// الحصول على base path فقط (بدون /dashboard/)
$basePath = getBasePath();
$baseUrl = rtrim($basePath, '/') . '/dashboard/';

// الحصول على role بأمان
$role = '';
if (isset($currentUser) && is_array($currentUser) && isset($currentUser['role'])) {
    $role = strtolower(trim($currentUser['role']));
}

// تم إزالة نظام الجلسات - استخدام getUserFromToken() فقط
if (empty($role) && function_exists('getUserFromToken')) {
    $user = getUserFromToken();
    if ($user && isset($user['role'])) {
        $role = strtolower(trim($user['role']));
    }
}

$currentPageParam = trim($_GET['page'] ?? '');

// محاولة تحديد role من الصفحة الحالية إذا كان غير معروف
if (empty($role)) {
    if ($currentPage === 'sales.php') {
        $role = 'sales';
    } elseif ($currentPage === 'manager.php') {
        $role = 'manager';
    } elseif ($currentPage === 'accountant.php') {
        $role = 'accountant';
    } elseif ($currentPage === 'production.php') {
        $role = 'production';
    } elseif ($currentPage === 'driver.php') {
        $role = 'driver';
    } elseif ($currentPage === 'developer.php') {
        $role = 'developer';
    }
}

// تحديد الروابط بناءً على الدور
$menuItems = [];

switch ($role) {
    case 'manager':
        $menuItems = [
            ['divider' => true, 'title' => isset($lang['management']) ? $lang['management'] : 'الإدارة'],
            [
                'title' => 'العملاء المحليين',
                'icon' => 'bi-people',
                'url' => $baseUrl . 'manager.php?page=local_customers',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'local_customers'),
                'badge' => null
            ],
            [
                'title' => 'عملاء المندوبين',
                'icon' => 'bi-people-fill',
                'url' => $baseUrl . 'manager.php?page=representatives_customers',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'representatives_customers'),
                'badge' => null
            ],
            [
                'title' => 'خزنة الشركة',
                'icon' => 'bi-bank',
                'url' => $baseUrl . 'manager.php?page=company_cash',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'company_cash'),
                'badge' => null
            ],
            [
                'title' => 'طلبات الشحن',
                'icon' => 'bi-truck',
                'url' => $baseUrl . 'manager.php?page=shipping_orders',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'shipping_orders'),
                'badge' => null
            ],
            [
                'title' => 'تسجيل الاوردرات',
                'icon' => 'bi-list-task',
                'url' => $baseUrl . 'manager.php?page=production_tasks',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'production_tasks'),
                'badge' => null
            ],
            [
                'title' => ' تسجيل طلبات العملاء',
                'icon' => 'bi-bag-check',
                'url' => $baseUrl . 'manager.php?page=orders',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'orders'),
                'badge' => null
            ],
            [
                'title' => 'نقطة البيع',
                'icon' => 'bi-cart4',
                'url' => $baseUrl . 'manager.php?page=pos',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'pos'),
                'badge' => null
            ],
            [
                'title' => 'الفواتير',
                'icon' => 'bi-receipt',
                'url' => $baseUrl . 'manager.php?page=invoices',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'invoices'),
                'badge' => null
            ],
            [
                'title' => 'جداول التحصيل ',
                'icon' => 'bi-calendar-check',
                'url' => $baseUrl . 'manager.php?page=company_payment_schedules',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'company_payment_schedules'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_suppliers']) ? $lang['menu_suppliers'] : 'الموردين',
                'icon' => 'bi-truck',
                'url' => $baseUrl . 'manager.php?page=suppliers',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'suppliers'),
                'badge' => null
            ],
            [
                'title' => 'منتجات الشركة',
                'icon' => 'bi-box-seam',
                'url' => $baseUrl . 'manager.php?page=company_products',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'company_products'),
                'badge' => null
            ],
            [
                'title' => 'تشوين المنتجات',
                'icon' => 'bi-arrow-left-right',
                'url' => $baseUrl . 'manager.php?page=product_storage',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'product_storage'),
                'badge' => null
            ],
            [
                'title' => 'المنتجات المنقولة لي',
                'icon' => 'bi-inbox',
                'url' => $baseUrl . 'manager.php?page=transferred_to_me',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'transferred_to_me'),
                'badge' => null
            ],
            [
                'title' => 'مخزن الخامات',
                'icon' => 'bi-box-seam',
                'url' => $baseUrl . 'manager.php?page=raw_materials_warehouse',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'raw_materials_warehouse'),
                'badge' => null
            ],
            [
                'title' => 'مخزن أدوات التعبئة',
                'icon' => 'bi-box-seam',
                'url' => $baseUrl . 'manager.php?page=packaging_warehouse',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'packaging_warehouse'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_salaries']) ? $lang['menu_salaries'] : 'الرواتب',
                'icon' => 'bi-currency-dollar',
                'url' => $baseUrl . 'manager.php?page=salaries',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'salaries'),
                'badge' => null
            ],
            [
                'title' => 'متابعة الحضور والانصراف',
                'icon' => 'bi-calendar-check',
                'url' => $baseUrl . 'manager.php?page=attendance_management',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'attendance_management'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_approvals']) ? $lang['menu_approvals'] : 'الموافقات',
                'icon' => 'bi-check-circle',
                'url' => $baseUrl . 'manager.php?page=approvals',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'approvals'),
                'badge' => '<span class="badge" id="approvalBadge">0</span>'
            ],
            [
                'title' => isset($lang['menu_returns_exchanges']) ? $lang['menu_returns_exchanges'] : 'المرتجعات',
                'icon' => 'bi-arrow-left-right',
                'url' => $baseUrl . 'manager.php?page=returns_overview',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'returns_overview'),
                'badge' => null
            ],
            [
                'title' => 'أرصدة العملاء الدائنة',
                'icon' => 'bi-wallet2',
                'url' => $baseUrl . 'manager.php?page=customer_credit_balances',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'customer_credit_balances'),
                'badge' => null
            ],
            [
                'title' => 'محافظ المستخدمين',
                'icon' => 'bi-wallet',
                'url' => $baseUrl . 'manager.php?page=user_wallets_control',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'user_wallets_control'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_warehouse_transfers']) ? $lang['menu_warehouse_transfers'] : 'نقل المخازن',
                'icon' => 'bi-arrow-left-right',
                'url' => $baseUrl . 'manager.php?page=warehouse_transfers',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'warehouse_transfers'),
                'badge' => null
            ],
            [
                'title' => 'قارئ أرقام التشغيلات',
                'icon' => 'bi-upc-scan',
                'url' => $baseUrl . 'manager.php?page=batch_reader',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'batch_reader'),
                'badge' => null
            ],
            [
                'title' => 'مخزن توالف المصنع',
                'icon' => 'bi-trash',
                'url' => $baseUrl . 'manager.php?page=factory_waste_warehouse',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'factory_waste_warehouse'),
                'badge' => null
            ],
            ['divider' => true, 'title' => 'أدوات'],
            [
                'title' => 'قوالب  و وصفات المنتجات',
                'icon' => 'bi-file-earmark-text',
                'url' => $baseUrl . 'manager.php?page=product_templates',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'product_templates'),
                'badge' => null
            ],
            [
                'title' => 'السيارات',
                'icon' => 'bi-truck',
                'url' => $baseUrl . 'manager.php?page=vehicles',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'vehicles'),
                'badge' => null
            ],
            [
                'title' => 'صيانات السيارة',
                'icon' => 'bi-wrench-adjustable',
                'url' => $baseUrl . 'manager.php?page=vehicle_maintenance',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'vehicle_maintenance'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_reports']) ? $lang['menu_reports'] : 'التقارير',
                'icon' => 'bi-file-earmark-text',
                'url' => $baseUrl . 'manager.php?page=reports',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'reports'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_security']) ? $lang['menu_security'] : 'الأمان',
                'icon' => 'bi-lock',
                'url' => $baseUrl . 'manager.php?page=security',
                'active' => ($currentPage === 'manager.php' && in_array($currentPageParam, ['security', 'permissions'])),
                'badge' => null
            ],
            ['divider' => true, 'title' => 'عام'],
            [
                'title' => isset($lang['dashboard']) ? $lang['dashboard'] : 'لوحة التحكم',
                'icon' => 'bi-speedometer2',
                'url' => $baseUrl . 'manager.php',
                'active' => ($currentPage === 'manager.php' && ($currentPageParam === 'overview' || $currentPageParam === '')),
                'badge' => null
            ],
            [
                'title' => 'الشات',
                'icon' => 'bi-chat-dots',
                'url' => $baseUrl . 'manager.php?page=chat',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'chat'),
                'badge' => null
            ]
        ];
        break;
        
    case 'accountant':
        $menuItems = [
            ['divider' => true, 'title' => isset($lang['accounting_section']) ? $lang['accounting_section'] : 'المحاسبة'],
            [
                'title' => 'العملاء المحليين',
                'icon' => 'bi-people',
                'url' => $baseUrl . 'accountant.php?page=local_customers',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'local_customers'),
                'badge' => null
            ],
            [
                'title' => 'عملاء المندوبين',
                'icon' => 'bi-people-fill',
                'url' => $baseUrl . 'accountant.php?page=representatives_customers',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'representatives_customers'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_financial']) ? $lang['menu_financial'] : 'الخزنة',
                'icon' => 'bi-safe',
                'url' => $baseUrl . 'accountant.php?page=accountant_cash',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'financial'),
                'badge' => null
            ],
            [
                'title' => 'محافظ المستخدمين',
                'icon' => 'bi-wallet',
                'url' => $baseUrl . 'accountant.php?page=user_wallets_control',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'user_wallets_control'),
                'badge' => null
            ],
            [
                'title' => 'طلبات الشحن',
                'icon' => 'bi-truck',
                'url' => $baseUrl . 'accountant.php?page=shipping_orders',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'shipping_orders'),
                'badge' => null
            ],
            [
                'title' => 'الاوردرات',
                'icon' => 'bi-send-check',
                'url' => $baseUrl . 'accountant.php?page=production_tasks',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'production_tasks'),
                'badge' => null
            ],
            [
                'title' => 'طلبات العملاء',
                'icon' => 'bi-bag-check',
                'url' => $baseUrl . 'accountant.php?page=orders',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'orders'),
                'badge' => null
            ],
            [
                'title' => 'نقطة البيع',
                'icon' => 'bi-cart4',
                'url' => $baseUrl . 'accountant.php?page=pos',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'pos'),
                'badge' => null
            ],
            [
                'title' => 'الفواتير',
                'icon' => 'bi-receipt',
                'url' => $baseUrl . 'accountant.php?page=invoices',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'invoices'),
                'badge' => null
            ],
            [
                'title' => 'جداول التحصيل ',
                'icon' => 'bi-calendar-check',
                'url' => $baseUrl . 'accountant.php?page=company_payment_schedules',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'company_payment_schedules'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_suppliers']) ? $lang['menu_suppliers'] : 'الموردين',
                'icon' => 'bi-truck',
                'url' => $baseUrl . 'accountant.php?page=suppliers',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'suppliers'),
                'badge' => null
            ],
            [
                'title' => 'منتجات الشركة',
                'icon' => 'bi-box-seam',
                'url' => $baseUrl . 'accountant.php?page=company_products',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'company_products'),
                'badge' => null
            ],
            [
                'title' => 'تشوين المنتجات',
                'icon' => 'bi-arrow-left-right',
                'url' => $baseUrl . 'accountant.php?page=product_storage',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'product_storage'),
                'badge' => null
            ],
            [
                'title' => 'المنتجات المنقولة لي',
                'icon' => 'bi-inbox',
                'url' => $baseUrl . 'accountant.php?page=transferred_to_me',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'transferred_to_me'),
                'badge' => null
            ],
            [
                'title' => 'مخزن الخامات',
                'icon' => 'bi-droplet',
                'url' => $baseUrl . 'accountant.php?page=raw_materials_warehouse',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'raw_materials_warehouse'),
                'badge' => null
            ],
            [
                'title' => 'مخزن أدوات التعبئة',
                'icon' => 'bi-box-seam',
                'url' => $baseUrl . 'accountant.php?page=packaging_warehouse',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'packaging_warehouse'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_salaries']) ? $lang['menu_salaries'] : 'الرواتب',
                'icon' => 'bi-currency-dollar',
                'url' => $baseUrl . 'accountant.php?page=salaries',
                'active' => ($currentPage === 'accountant.php' && ($currentPageParam === 'salaries' || $currentPageParam === 'salary_details')),
                'badge' => null
            ],
            [
                'title' => 'متابعة الحضور والانصراف',
                'icon' => 'bi-bar-chart',
                'url' => $baseUrl . 'accountant.php?page=attendance_management',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'attendance_management'),
                'badge' => null
            ],
            [
                'title' => 'أرصدة العملاء الدائنة',
                'icon' => 'bi-wallet2',
                'url' => $baseUrl . 'accountant.php?page=customer_credit_balances',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'customer_credit_balances'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_attendance']) ? $lang['menu_attendance'] : 'الحضور',
                'icon' => 'bi-calendar-check',
                'url' => getRelativeUrl('attendance.php'),
                'active' => ($currentPage === 'attendance.php'),
                'badge' => null
            ],
            [
                'title' => isset($lang['my_salary']) ? $lang['my_salary'] : 'مرتبي',
                'icon' => 'bi-wallet2',
                'url' => $baseUrl . 'accountant.php?page=my_salary',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'my_salary'),
                'badge' => null
            ],
            [
                'title' => 'المرتجعات',
                'icon' => 'bi-arrow-return-left',
                'url' => $baseUrl . 'accountant.php?page=returns',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'returns'),
                'badge' => null
            ],
            [
                'title' => 'السيارات',
                'icon' => 'bi-car-front',
                'url' => $baseUrl . 'accountant.php?page=vehicles',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'vehicles'),
                'badge' => null
            ],
            [
                'title' => 'صيانات السيارة',
                'icon' => 'bi-wrench-adjustable',
                'url' => $baseUrl . 'accountant.php?page=vehicle_maintenance',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'vehicle_maintenance'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_reports']) ? $lang['menu_reports'] : 'التقارير',
                'icon' => 'bi-file-earmark-text',
                'url' => $baseUrl . 'manager.php?page=reports',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'reports'),
                'badge' => null
            ],
            [
                'title' => 'نقل المنتجات',
                'icon' => 'bi-arrow-left-right',
                'url' => $baseUrl . 'accountant.php?page=warehouse_transfers',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'warehouse_transfers'),
                'badge' => null
            ],
            [
                'title' => 'مخزن توالف المصنع',
                'icon' => 'bi-trash',
                'url' => $baseUrl . 'accountant.php?page=factory_waste_warehouse',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'factory_waste_warehouse'),
                'badge' => null
            ],
            [
                'title' => 'قارئ أرقام التشغيلات',
                'icon' => 'bi-upc-scan',
                'url' => $baseUrl . 'accountant.php?page=batch_reader',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'batch_reader'),
                'badge' => null
            ],
            ['divider' => true, 'title' => 'عام'],
            [
                'title' => isset($lang['dashboard']) ? $lang['dashboard'] : 'لوحة التحكم',
                'icon' => 'bi-speedometer2',
                'url' => $baseUrl . 'accountant.php',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === ''),
                'badge' => null
            ],
            [
                'title' => 'الشات',
                'icon' => 'bi-chat-dots',
                'url' => $baseUrl . 'accountant.php?page=chat',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'chat'),
                'badge' => null
            ]
        ];
        break;
        
    case 'sales':
        $menuItems = [
            [
                'title' => isset($lang['dashboard']) ? $lang['dashboard'] : 'لوحة التحكم',
                'icon' => 'bi-speedometer2',
                'url' => $baseUrl . 'sales.php',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === ''),
                'badge' => null
            ],
            [
                'title' => 'الشات',
                'icon' => 'bi-chat-dots',
                'url' => $baseUrl . 'sales.php?page=chat',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'chat'),
                'badge' => null
            ],
            ['divider' => true, 'title' => isset($lang['sales_section']) ? $lang['sales_section'] : 'المبيعات'],
            [
                'title' => isset($lang['customers']) ? $lang['customers'] : 'العملاء',
                'icon' => 'bi-people',
                'url' => $baseUrl . 'sales.php?page=customers',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'customers'),
                'badge' => null
            ],

            [
                'title' => isset($lang['customer_orders']) ? $lang['customer_orders'] : 'طلبات العملاء',
                'icon' => 'bi-clipboard-check',
                'url' => $baseUrl . 'sales.php?page=orders',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'orders'),
                'badge' => (isset($currentUser) && $currentUser['role'] === 'sales' && function_exists('getNewOrdersCount')) ? getNewOrdersCount($currentUser['id']) : null
            ],
            [
                'title' => isset($lang['payment_schedules']) ? $lang['payment_schedules'] : 'جداول التحصيل',
                'icon' => 'bi-calendar-event',
                'url' => $baseUrl . 'sales.php?page=payment_schedules',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'payment_schedules'),
                'badge' => null
            ],
            [
                'title' => isset($lang['vehicle_inventory']) ? $lang['vehicle_inventory'] : 'مخزون السيارات',
                'icon' => 'bi-truck',
                'url' => $baseUrl . 'sales.php?page=vehicle_inventory',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'vehicle_inventory'),
                'badge' => null
            ],
            [
                'title' => 'تشوين المنتجات',
                'icon' => 'bi-arrow-left-right',
                'url' => $baseUrl . 'sales.php?page=product_storage',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'product_storage'),
                'badge' => null
            ],
            [
                'title' => 'المنتجات المنقولة لي',
                'icon' => 'bi-inbox',
                'url' => $baseUrl . 'sales.php?page=transferred_to_me',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'transferred_to_me'),
                'badge' => null
            ],
            [
                'title' => isset($lang['sales_pos']) ? $lang['sales_pos'] : 'نقطة البيع',
                'icon' => 'bi-shop',
                'url' => $baseUrl . 'sales.php?page=pos',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'pos'),
                'badge' => null
            ],
            [
                'title' => 'خزنة المندوب',
                'icon' => 'bi-cash-stack',
                'url' => $baseUrl . 'sales.php?page=cash_register',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'cash_register'),
                'badge' => null
            ],
            [
                'title' => 'سجلات المندوب',
                'icon' => 'bi-journal-text',
                'url' => $baseUrl . 'sales.php?page=my_records',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'my_records'),
                'badge' => null
            ],
            [
                'title' => isset($lang['my_salary']) ? $lang['my_salary'] : 'مرتبي',
                'icon' => 'bi-wallet2',
                'url' => $baseUrl . 'sales.php?page=my_salary',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'my_salary'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_attendance']) ? $lang['menu_attendance'] : 'الحضور',
                'icon' => 'bi-calendar-check',
                'url' => getRelativeUrl('attendance.php'),
                'active' => ($currentPage === 'attendance.php'),
                'badge' => null
            ],
            ['divider' => true, 'title' => 'أدوات'],
            [
                'title' => 'قارئ أرقام التشغيلات',
                'icon' => 'bi-upc-scan',
                'url' => $baseUrl . 'sales.php?page=batch_reader',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'batch_reader'),
                'badge' => null
            ]
        ];
        break;
        
    case 'developer':
        $menuItems = [
            [
                'title' => 'لوحة المطور',
                'icon' => 'bi-code-slash',
                'url' => $baseUrl . 'developer.php',
                'active' => ($currentPage === 'developer.php' && ($currentPageParam === 'overview' || $currentPageParam === '')),
                'badge' => null
            ],
            ['divider' => true, 'title' => 'إدارة النظام'],
            [
                'title' => 'إعدادات النظام',
                'icon' => 'bi-gear',
                'url' => $baseUrl . 'developer.php?page=system_settings',
                'active' => ($currentPage === 'developer.php' && $currentPageParam === 'system_settings'),
                'badge' => null
            ],
            [
                'title' => 'المستخدمين',
                'icon' => 'bi-people',
                'url' => $baseUrl . 'developer.php?page=users',
                'active' => ($currentPage === 'developer.php' && $currentPageParam === 'users'),
                'badge' => null
            ],
            [
                'title' => 'الأمان',
                'icon' => 'bi-shield-lock',
                'url' => $baseUrl . 'developer.php?page=security',
                'active' => ($currentPage === 'developer.php' && $currentPageParam === 'security'),
                'badge' => null
            ],
            [
                'title' => 'سجلات التدقيق',
                'icon' => 'bi-journal-text',
                'url' => $baseUrl . 'developer.php?page=audit_logs',
                'active' => ($currentPage === 'developer.php' && $currentPageParam === 'audit_logs'),
                'badge' => null
            ],
            [
                'title' => 'النسخ الاحتياطية',
                'icon' => 'bi-database',
                'url' => $baseUrl . 'developer.php?page=backups',
                'active' => ($currentPage === 'developer.php' && $currentPageParam === 'backups'),
                'badge' => null
            ],
            ['divider' => true, 'title' => 'لوحات أخرى'],
            [
                'title' => 'لوحة المدير',
                'icon' => 'bi-speedometer2',
                'url' => $baseUrl . 'manager.php',
                'active' => false,
                'badge' => null
            ]
        ];
        break;
    
    case 'production':
        $menuItems = [
            [
                'title' => isset($lang['dashboard']) ? $lang['dashboard'] : 'لوحة التحكم',
                'icon' => 'bi-speedometer2',
                'url' => $baseUrl . 'production.php',
                'active' => ($currentPage === 'production.php' && $currentPageParam === ''),
                'badge' => null
            ],
            [
                'title' => 'الشات',
                'icon' => 'bi-chat-dots',
                'url' => $baseUrl . 'production.php?page=chat',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'chat'),
                'badge' => null
            ],
            ['divider' => true, 'title' => isset($lang['production_section']) ? $lang['production_section'] : 'الإنتاج'],
            [
                'title' => isset($lang['menu_production']) ? $lang['menu_production'] : 'الإنتاج',
                'icon' => 'bi-box-seam',
                'url' => $baseUrl . 'production.php?page=production',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'production'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_tasks']) ? $lang['menu_tasks'] : 'أوردرات',
                'icon' => 'bi-list-check',
                'url' => $baseUrl . 'production.php?page=tasks',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'tasks'),
                'badge' => null
            ],
            [
                'title' => 'مخزن أدوات التعبئة',
                'icon' => 'bi-box-seam',
                'url' => $baseUrl . 'production.php?page=packaging_warehouse',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'packaging_warehouse'),
                'badge' => null
            ],
            [
                'title' => 'مخزن الخامات',
                'icon' => 'bi-box-seam',
                'url' => $baseUrl . 'production.php?page=raw_materials_warehouse',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'raw_materials_warehouse'),
                'badge' => null
            ],
            [
                'title' => 'مخزن توالف المصنع',
                'icon' => 'bi-trash',
                'url' => $baseUrl . 'production.php?page=factory_waste_warehouse',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'factory_waste_warehouse'),
                'badge' => null
            ],
            [
                'title' => 'مخزن المنتجات',
                'icon' => 'bi-boxes',
                'url' => $baseUrl . 'production.php?page=inventory',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'inventory'),
                'badge' => null
            ],
            [
                'title' => 'منتجات الشركة',
                'icon' => 'bi-box-seam',
                'url' => $baseUrl . 'production.php?page=company_products',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'company_products'),
                'badge' => null
            ],
            [
                'title' => 'تشوين المنتجات',
                'icon' => 'bi-arrow-left-right',
                'url' => $baseUrl . 'production.php?page=product_storage',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'product_storage'),
                'badge' => null
            ],
            [
                'title' => 'المنتجات المنقولة لي',
                'icon' => 'bi-inbox',
                'url' => $baseUrl . 'production.php?page=transferred_to_me',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'transferred_to_me'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_attendance']) ? $lang['menu_attendance'] : 'الحضور',
                'icon' => 'bi-calendar-check',
                'url' => getRelativeUrl('attendance.php'),
                'active' => ($currentPage === 'attendance.php'),
                'badge' => null
            ],
            [
                'title' => isset($lang['my_salary']) ? $lang['my_salary'] : 'مرتبي',
                'icon' => 'bi-wallet2',
                'url' => $baseUrl . 'production.php?page=my_salary',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'my_salary'),
                'badge' => null
            ],
            [
                'title' => 'محفظة المستخدم',
                'icon' => 'bi-wallet',
                'url' => $baseUrl . 'production.php?page=user_wallet',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'user_wallet'),
                'badge' => null
            ],
            ['divider' => true, 'title' => 'أدوات'],
            [
                'title' => 'قارئ أرقام التشغيلات',
                'icon' => 'bi-upc-scan',
                'url' => $baseUrl . 'production.php?page=batch_reader',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'batch_reader'),
                'badge' => null
            ],
        ];
        break;

    case 'driver':
        $menuItems = [
            [
                'title' => isset($lang['dashboard']) ? $lang['dashboard'] : 'لوحة التحكم',
                'icon' => 'bi-speedometer2',
                'url' => $baseUrl . 'driver.php',
                'active' => ($currentPage === 'driver.php' && ($currentPageParam === '' || $currentPageParam === 'dashboard')),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_attendance']) ? $lang['menu_attendance'] : 'الحضور والانصراف',
                'icon' => 'bi-calendar-check',
                'url' => getRelativeUrl('attendance.php'),
                'active' => ($currentPage === 'attendance.php'),
                'badge' => null
            ],
            [
                'title' => 'الاوردرات',
                'icon' => 'bi-list-task',
                'url' => $baseUrl . 'driver.php?page=tasks',
                'active' => ($currentPage === 'driver.php' && $currentPageParam === 'tasks'),
                'badge' => null
            ],
            [
                'title' => 'محفظة المستخدم',
                'icon' => 'bi-wallet',
                'url' => $baseUrl . 'driver.php?page=user_wallet',
                'active' => ($currentPage === 'driver.php' && $currentPageParam === 'user_wallet'),
                'badge' => null
            ],
            [
                'title' => 'صيانات السيارة',
                'icon' => 'bi-wrench-adjustable',
                'url' => $baseUrl . 'driver.php?page=vehicle_maintenance',
                'active' => ($currentPage === 'driver.php' && $currentPageParam === 'vehicle_maintenance'),
                'badge' => null
            ],
        ];
        break;
}

if (empty($menuItems)) {
    // إذا كانت الصفحة الحالية هي sales.php، استخدم قائمة sales
    if ($currentPage === 'sales.php') {
        $menuItems = [
            [
                'title' => isset($lang['dashboard']) ? $lang['dashboard'] : 'لوحة التحكم',
                'icon' => 'bi-speedometer2',
                'url' => $baseUrl . 'sales.php',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === ''),
                'badge' => null
            ],
            [
                'title' => 'الشات',
                'icon' => 'bi-chat-dots',
                'url' => $baseUrl . 'sales.php?page=chat',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'chat'),
                'badge' => null
            ],
            ['divider' => true, 'title' => isset($lang['sales_section']) ? $lang['sales_section'] : 'المبيعات'],
            [
                'title' => isset($lang['customers']) ? $lang['customers'] : 'العملاء',
                'icon' => 'bi-people',
                'url' => $baseUrl . 'sales.php?page=customers',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'customers'),
                'badge' => null
            ],
            [
                'title' => isset($lang['customer_orders']) ? $lang['customer_orders'] : 'طلبات العملاء',
                'icon' => 'bi-clipboard-check',
                'url' => $baseUrl . 'sales.php?page=orders',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'orders'),
                'badge' => (isset($currentUser) && $currentUser['role'] === 'sales' && function_exists('getNewOrdersCount')) ? getNewOrdersCount($currentUser['id']) : null
            ],
            [
                'title' => 'سجلات المندوب',
                'icon' => 'bi-journal-text',
                'url' => $baseUrl . 'sales.php?page=my_records',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'my_records'),
                'badge' => null
            ],
            [
                'title' => isset($lang['sales_pos']) ? $lang['sales_pos'] : 'نقطة البيع',
                'icon' => 'bi-shop',
                'url' => $baseUrl . 'sales.php?page=pos',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'pos'),
                'badge' => null
            ],
        ];
    } else {
        // القائمة الافتراضية
        $menuItems = [
            [
                'title' => isset($lang['dashboard']) ? $lang['dashboard'] : 'لوحة التحكم',
                'icon' => 'bi-speedometer2',
                'url' => $baseUrl . 'accountant.php',
                'active' => true,
                'badge' => null
            ],
            [
                'title' => 'الشات',
                'icon' => 'bi-chat-dots',
                'url' => $baseUrl . 'accountant.php?page=chat',
                'active' => false,
                'badge' => null
            ]
        ];
    }
}
?>

<aside class="homeline-sidebar">
    <div class="sidebar-header">
        <a href="<?php echo getDashboardUrl($role); ?>" class="sidebar-logo">
            <i class="bi bi-building"></i>
            <span class="sidebar-logo-text"><?php echo APP_NAME; ?></span>
        </a>
        <button class="sidebar-toggle" id="sidebarToggle" type="button">
            <i class="bi bi-chevron-left"></i>
        </button>
    </div>
    
    <!-- Sidebar Search -->
    <div class="sidebar-search-wrapper px-3 mb-3">
        <div class="input-group input-group-sm">
            <span class="input-group-text bg-transparent border-end-0 text-muted">
                <i class="bi bi-search"></i>
            </span>
            <input type="text" class="form-control border-start-0 bg-transparent sidebar-search-input shadow-none" 
                   id="sidebarSearchInput" 
                   placeholder="بحث سريع..." 
                   autocomplete="off"
                   style="border-color: rgba(0,0,0,0.1);">
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('sidebarSearchInput');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    const filter = this.value.toLowerCase().trim();
                    const navItems = document.querySelectorAll('.homeline-sidebar .nav-item');
                    
                    navItems.forEach(function(item) {
                        const link = item.querySelector('.nav-link');
                        if (link) {
                            const text = link.textContent.toLowerCase();
                            // البحث في النص والنص الفرعي (إذا وجد)
                            if (text.includes(filter)) {
                                item.style.display = '';
                                // تمييز النص المطابق (اختياري - بسيط حالياً)
                            } else {
                                item.style.display = 'none';
                            }
                        }
                    });
                });
            }
        });
        </script>
    </div>

    <nav class="sidebar-nav">
        <ul class="nav">
            <?php
            foreach ($menuItems as $item): if (!isset($item['divider'])): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $item['active'] ? 'active' : ''; ?>" 
                       href="<?php echo htmlspecialchars($item['url']); ?>"
                       data-no-splash="true">
                        <i class="bi <?php echo htmlspecialchars($item['icon']); ?>"></i>
                        <span><?php echo htmlspecialchars($item['title']); ?></span>
                        <?php if ($item['badge']): ?>
                            <?php echo $item['badge']; ?>
                        <?php endif; ?>
                    </a>
                </li>
            <?php
                endif;
            endforeach;
            ?>
        </ul>
    </nav>

    <!-- جزء التعريف الشخصي وتسجيل الخروج - يظهر لجميع المستخدمين (كل الأدوار) دون استثناء -->
    <div class="homeline-sidebar-profile-section" role="region" aria-label="التعريف الشخصي وتسجيل الخروج">
        <?php if (isset($currentUser) && is_array($currentUser) && !empty($currentUser)): ?>
            <?php
            $profileFullName = trim($currentUser['full_name'] ?? '') ?: '—';
            $profileUsername = trim($currentUser['username'] ?? '') ?: '—';
            $profileUserId = isset($currentUser['id']) ? (int) $currentUser['id'] : 0;
            ?>
            <div class="sidebar-profile-info mb-2">
                <div class="sidebar-profile-row" title="<?php echo htmlspecialchars($profileFullName); ?>">
                    <span class="sidebar-profile-row-icon"><i class="bi bi-person-fill" aria-hidden="true"></i></span>
                    <span class="sidebar-profile-row-label">الاسم</span>
                    <span class="sidebar-profile-row-value sidebar-profile-name"><?php echo htmlspecialchars($profileFullName); ?></span>
                </div>
                <div class="sidebar-profile-row" title="<?php echo htmlspecialchars($profileUsername); ?>">
                    <span class="sidebar-profile-row-icon"><i class="bi bi-at" aria-hidden="true"></i></span>
                    <span class="sidebar-profile-row-label">اسم المستخدم</span>
                    <span class="sidebar-profile-row-value sidebar-profile-username"><?php echo htmlspecialchars($profileUsername); ?></span>
                </div>
                <div class="sidebar-profile-row" title="<?php echo $profileUserId; ?>">
                    <span class="sidebar-profile-row-icon"><i class="bi bi-hash" aria-hidden="true"></i></span>
                    <span class="sidebar-profile-row-label">المعرّف</span>
                    <span class="sidebar-profile-row-value sidebar-profile-userid"><?php echo $profileUserId; ?></span>
                </div>
            </div>
            <a href="<?php echo getRelativeUrl('logout.php'); ?>" class="btn btn-danger btn-sm w-100 d-flex align-items-center justify-content-center gap-2 homeline-sidebar-logout-btn" data-no-splash="true">
                <i class="bi bi-box-arrow-right"></i>
                <span><?php echo isset($lang['logout']) ? $lang['logout'] : 'تسجيل الخروج'; ?></span>
            </a>
        <?php else: ?>
            <a href="<?php echo getRelativeUrl('index.php'); ?>" class="btn btn-danger btn-sm w-100 d-flex align-items-center justify-content-center gap-2 homeline-sidebar-logout-btn" data-no-splash="true">
                <i class="bi bi-box-arrow-in-right"></i>
                <span><?php echo isset($lang['login']) ? $lang['login'] : 'تسجيل الدخول'; ?></span>
            </a>
        <?php endif; ?>
    </div>
</aside>

<style>
.homeline-sidebar .homeline-sidebar-profile-section {
    margin-top: auto;
    padding: 1rem 1rem 1.25rem;
    border-top: 1px solid rgba(0, 0, 0, 0.08);
    background: rgba(0, 0, 0, 0.02);
    flex-shrink: 0;
}
.homeline-sidebar .sidebar-profile-label {
    font-size: 0.7rem;
    letter-spacing: 0.02em;
    opacity: 0.85;
}
.homeline-sidebar .sidebar-profile-info {
    background: rgba(0, 0, 0, 0.03);
    border-radius: 0.375rem;
    padding: 0.5rem 0.65rem;
    border: 1px solid rgba(0, 0, 0, 0.06);
}
.homeline-sidebar .sidebar-profile-row {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    min-height: 1.5rem;
    margin-bottom: 0.35rem;
    line-height: 1.35;
}
.homeline-sidebar .sidebar-profile-row:last-child {
    margin-bottom: 0;
}
.homeline-sidebar .sidebar-profile-row-icon {
    flex-shrink: 0;
    width: 1.25rem;
    text-align: center;
    color: var(--bs-secondary, #6c757d);
    font-size: 0.85rem;
}
.homeline-sidebar .sidebar-profile-row-label {
    flex-shrink: 0;
    font-size: 0.7rem;
    color: var(--bs-secondary, #6c757d);
    min-width: 4.5em;
}
.homeline-sidebar .sidebar-profile-row-value {
    flex: 1;
    min-width: 0;
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--bs-body-color, #212529);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.homeline-sidebar .sidebar-profile-name {
    font-weight: 600;
}
.homeline-sidebar .sidebar-profile-username {
    font-weight: 500;
    font-size: 0.75rem;
    color: var(--bs-body-color, #495057);
}
.homeline-sidebar .homeline-sidebar-logout-btn {
    font-weight: 500;
    padding: 0.5rem 0.75rem;
}
.homeline-sidebar .homeline-sidebar-logout-btn:hover {
    background-color: var(--bs-danger);
    color: #fff;
    border-color: var(--bs-danger);
    opacity: 0.92;
}
/* ضمان أن الشريط يعرض الجزء السفلي بشكل صحيح */
.homeline-sidebar {
    display: flex;
    flex-direction: column;
}
.homeline-sidebar .sidebar-nav {
    flex: 1 1 auto;
    overflow-y: auto;
    overflow-x: hidden;
}
/* عند طي الشريط: إظهار قسم التعريف بشكل مضغوط لجميع المستخدمين */
.dashboard-wrapper.sidebar-collapsed .homeline-sidebar .homeline-sidebar-profile-section {
    padding: 0.75rem 0.5rem;
}
.dashboard-wrapper.sidebar-collapsed .homeline-sidebar .sidebar-profile-label,
.dashboard-wrapper.sidebar-collapsed .homeline-sidebar .sidebar-profile-info,
.dashboard-wrapper.sidebar-collapsed .homeline-sidebar .sidebar-profile-name,
.dashboard-wrapper.sidebar-collapsed .homeline-sidebar .sidebar-profile-username {
    opacity: 0;
    width: 0;
    height: 0;
    overflow: hidden;
    margin: 0;
    padding: 0;
    position: absolute;
    pointer-events: none;
}
.dashboard-wrapper.sidebar-collapsed .homeline-sidebar .homeline-sidebar-logout-btn span {
    opacity: 0;
    width: 0;
    overflow: hidden;
    position: absolute;
}
.dashboard-wrapper.sidebar-collapsed .homeline-sidebar .homeline-sidebar-logout-btn {
    justify-content: center;
    padding: 0.5rem;
}
</style>

<!-- PWA Performance: Prefetching للروابط في الشريط الجانبي -->
<script>
(function() {
    'use strict';
    
    // كشف نوع الاتصال
    function detectConnectionType() {
        if ('connection' in navigator) {
            const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
            if (conn) {
                const effectiveType = conn.effectiveType || 'unknown';
                const saveData = conn.saveData || false;
                
                // لا نستخدم prefetching على اتصالات بطيئة أو saveData
                if (saveData || effectiveType === '2g' || effectiveType === 'slow-2g') {
                    return false;
                }
            }
        }
        return true; // السماح بالـ prefetching افتراضياً
    }
    
    const canPrefetch = detectConnectionType();
    
    if (!canPrefetch) {
        return; // لا نستخدم prefetching على اتصالات بطيئة
    }
    
    // Prefetching للروابط في الشريط الجانبي
    function setupPrefetching() {
        const sidebarLinks = document.querySelectorAll('.homeline-sidebar .nav-link[href]');
        const prefetchedUrls = new Set();
        
        sidebarLinks.forEach(link => {
            const href = link.getAttribute('href');
            if (!href || href === '#' || href.startsWith('javascript:') || href.startsWith('mailto:') || href.startsWith('tel:')) {
                return;
            }
            
            // Prefetch عند hover (للكمبيوتر) أو touchstart (للهاتف)
            const prefetchUrl = () => {
                if (prefetchedUrls.has(href)) {
                    return; // تم prefetch مسبقاً
                }
                
                // استخدام link prefetch
                const linkElement = document.createElement('link');
                linkElement.rel = 'prefetch';
                linkElement.href = href;
                linkElement.as = 'document';
                document.head.appendChild(linkElement);
                
                prefetchedUrls.add(href);
            };
            
            // Prefetch عند hover (للكمبيوتر)
            link.addEventListener('mouseenter', prefetchUrl, { once: true, passive: true });
            
            // Prefetch عند touchstart (للهاتف) - بعد تأخير بسيط
            link.addEventListener('touchstart', () => {
                setTimeout(prefetchUrl, 100); // تأخير 100ms لتجنب prefetching غير ضروري
            }, { once: true, passive: true });
        });
    }
    
    // تهيئة prefetching بعد تحميل DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupPrefetching);
    } else {
        setupPrefetching();
    }
    
    // كشف PWA
    function isPWA() {
        return window.matchMedia('(display-mode: standalone)').matches ||
               (navigator.standalone === true) ||
               (window.matchMedia('(display-mode: fullscreen)').matches);
    }
    
    // في PWA، prefetch جميع الصفحات الشائعة فوراً بعد تحميل الصفحة
    if (isPWA()) {
        setTimeout(() => {
            const sidebarLinks = document.querySelectorAll('.homeline-sidebar .nav-link[href]');
            const prefetchedUrls = new Set();
            
            // Prefetch أول 5 صفحات شائعة فوراً في PWA
            Array.from(sidebarLinks).slice(0, 5).forEach((link, index) => {
                setTimeout(() => {
                    const href = link.getAttribute('href');
                    if (href && href !== '#' && !href.startsWith('javascript:') && !prefetchedUrls.has(href)) {
                        const linkElement = document.createElement('link');
                        linkElement.rel = 'prefetch';
                        linkElement.href = href;
                        linkElement.as = 'document';
                        document.head.appendChild(linkElement);
                        prefetchedUrls.add(href);
                    }
                }, index * 300); // تأخير 300ms بين كل صفحة
            });
        }, 1000); // انتظار ثانية واحدة بعد تحميل الصفحة
    }
    
    // Prefetch للصفحة النشطة الحالية (إذا كانت موجودة في cache)
    const currentActiveLink = document.querySelector('.homeline-sidebar .nav-link.active');
    if (currentActiveLink) {
        const activeHref = currentActiveLink.getAttribute('href');
        if (activeHref && activeHref !== '#') {
            // Prefetch الصفحات المجاورة في القائمة
            const allLinks = Array.from(document.querySelectorAll('.homeline-sidebar .nav-link[href]'));
            const currentIndex = allLinks.indexOf(currentActiveLink);
            
            // Prefetch الصفحة التالية والسابقة
            [currentIndex - 1, currentIndex + 1].forEach(index => {
                if (index >= 0 && index < allLinks.length) {
                    const link = allLinks[index];
                    const href = link.getAttribute('href');
                    if (href && href !== '#') {
                        const linkElement = document.createElement('link');
                        linkElement.rel = 'prefetch';
                        linkElement.href = href;
                        linkElement.as = 'document';
                        document.head.appendChild(linkElement);
                    }
                }
            });
        }
    }
})();
</script>

