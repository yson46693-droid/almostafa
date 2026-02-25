<?php
/**
 * القائمة الجانبية للمحاسب
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../includes/path_helper.php';

$currentPage = basename($_SERVER['PHP_SELF']);
$baseUrl = getDashboardUrl();
?>
<div class="sidebar">
    <div class="sidebar-header">
        <h5>Menu</h5>
        <i class="bi bi-chevron-down"></i>
    </div>
    
    <nav class="sidebar-nav">
        <ul class="nav">
            <li class="nav-item">
                <a class="nav-link <?php echo $currentPage === 'accountant.php' && (!isset($_GET['page']) || $_GET['page'] === '') ? 'active' : ''; ?>" 
                   href="<?php echo $baseUrl; ?>accountant.php">
                    <i class="bi bi-speedometer2"></i>
                    <span><?php echo $lang['dashboard']; ?></span>
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] === 'chat') ? 'active' : ''; ?>"
                   href="<?php echo $baseUrl; ?>accountant.php?page=chat">
                    <i class="bi bi-chat-dots"></i>
                    <span>الشات</span>
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] === 'accountant_cash') ? 'active' : ''; ?>"
                   href="<?php echo $baseUrl; ?>accountant.php?page=accountant_cash">
                    <i class="bi bi-safe"></i>
                    <span><?php echo $lang['menu_financial']; ?></span>
                </a>
            </li>

            <li class="nav-item sidebar-section-divider">
                <div class="sidebar-section-title">Finance</div>
            </li>
                
            <li class="nav-item">
                <a class="nav-link" href="<?php echo $baseUrl; ?>accountant.php?page=suppliers">
                    <i class="bi bi-truck"></i>
                    <span><?php echo $lang['menu_suppliers']; ?></span>
                </a>
            </li>
                
            <li class="nav-item">
                <a class="nav-link" href="<?php echo $baseUrl; ?>accountant.php?page=collections">
                    <i class="bi bi-cash-coin"></i>
                    <span><?php echo $lang['menu_collections']; ?></span>
                </a>
            </li>
                
            <li class="nav-item">
                <a class="nav-link" href="<?php echo $baseUrl; ?>accountant.php?page=salaries">
                    <i class="bi bi-currency-dollar"></i>
                    <span><?php echo $lang['menu_salaries']; ?></span>
                </a>
            </li>
                
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] === 'local_customers') ? 'active' : ''; ?>" 
                   href="<?php echo $baseUrl; ?>accountant.php?page=local_customers">
                    <i class="bi bi-people"></i>
                    <span>العملاء المحليين</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] === 'daily_collection_schedules') ? 'active' : ''; ?>" 
                   href="<?php echo $baseUrl; ?>accountant.php?page=daily_collection_schedules">
                    <i class="bi bi-calendar2-range"></i>
                    <span>جداول التحصيل اليومية</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] === 'daily_collection_my_tables') ? 'active' : ''; ?>" 
                   href="<?php echo $baseUrl; ?>accountant.php?page=daily_collection_my_tables">
                    <i class="bi bi-calendar-check"></i>
                    <span>عرض وتحديث التحصيل اليومي</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?php echo $baseUrl; ?>accountant.php?page=invoices">
                    <i class="bi bi-receipt"></i>
                    <span>الفواتير</span>
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] === 'customer_credit_balances') ? 'active' : ''; ?>" 
                   href="<?php echo $baseUrl; ?>accountant.php?page=customer_credit_balances">
                    <i class="bi bi-wallet2"></i>
                    <span>أرصدة العملاء الدائنة</span>
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] === 'company_products') ? 'active' : ''; ?>" 
                   href="<?php echo $baseUrl; ?>accountant.php?page=company_products">
                    <i class="bi bi-box-seam"></i>
                    <span>منتجات الشركة</span>
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] === 'product_storage') ? 'active' : ''; ?>" 
                   href="<?php echo $baseUrl; ?>accountant.php?page=product_storage">
                    <i class="bi bi-arrow-left-right"></i>
                    <span>تشوين المنتجات</span>
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] === 'transferred_to_me') ? 'active' : ''; ?>" 
                   href="<?php echo $baseUrl; ?>accountant.php?page=transferred_to_me">
                    <i class="bi bi-inbox"></i>
                    <span>المنتجات المنقولة لي</span>
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] === 'pos') ? 'active' : ''; ?>" 
                   href="<?php echo $baseUrl; ?>accountant.php?page=pos">
                    <i class="bi bi-cart4"></i>
                    <span>نقطة البيع</span>
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link" href="<?php echo $baseUrl; ?>accountant.php?page=attendance">
                    <i class="bi bi-calendar-check"></i>
                    <span><?php echo $lang['menu_attendance']; ?></span>
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link" href="<?php echo $baseUrl; ?>accountant.php?page=reports">
                    <i class="bi bi-file-earmark-text"></i>
                    <span><?php echo $lang['menu_reports']; ?></span>
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] === 'batch_reader') ? 'active' : ''; ?>" 
                   href="<?php echo $baseUrl; ?>accountant.php?page=batch_reader">
                    <i class="bi bi-upc-scan"></i>
                    <span>قارئ أرقام التشغيلات</span>
                </a>
            </li>
            
            <li class="nav-item sidebar-section-divider">
                <div class="sidebar-section-title">Warehouses</div>
            </li>
            
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] === 'packaging_warehouse') ? 'active' : ''; ?>" 
                   href="<?php echo $baseUrl; ?>accountant.php?page=packaging_warehouse">
                    <i class="bi bi-box-seam"></i>
                    <span>مخزن أدوات التعبئة</span>
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] === 'raw_materials_warehouse') ? 'active' : ''; ?>" 
                   href="<?php echo $baseUrl; ?>accountant.php?page=raw_materials_warehouse">
                    <i class="bi bi-droplet"></i>
                    <span>مخزن الخامات</span>
                </a>
            </li>
        </ul>
    </nav>
    
    <div class="sidebar-footer">
        <div class="sidebar-footer-item">
            <div>
                <i class="bi bi-moon me-2"></i>
                <span>Dark Mode</span>
            </div>
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="darkModeToggle">
            </div>
        </div>
        <a href="<?php echo getRelativeUrl('logout.php'); ?>" class="sidebar-footer-item text-decoration-none">
            <div>
                <i class="bi bi-box-arrow-right me-2"></i>
                <span>Sign Out</span>
            </div>
        </a>
    </div>
</div>

