<?php
/**
 * صفحة منتجات الشركة - المدير
 * Company Products Page - Manager
 */

// تعيين ترميز UTF-8
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/table_styles.php';
require_once __DIR__ . '/../../includes/audit_log.php';

requireRole(['manager', 'accountant', 'developer', 'production']);

$currentUser = getCurrentUser();
$isProductionRole = ($currentUser['role'] ?? '') === 'production';
$redirectRole = $currentUser['role'] ?? 'manager'; // لإعادة التوجيه بعد العمليات (مدير/محاسب/عامل إنتاج)
$db = db();
$error = '';
$success = '';

// إنشاء جدول الأصناف إذا لم يكن موجوداً وإضافة الأصناف الافتراضية
try {
    $categoriesTableExists = $db->queryOne("SHOW TABLES LIKE 'product_categories'");
    if (empty($categoriesTableExists)) {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `product_categories` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `name` varchar(100) NOT NULL,
              `is_default` tinyint(1) DEFAULT 0,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    // إضافة الأصناف الافتراضية إذا لم تكن موجودة
    $defaultCategories = ['عسل', 'زيت زيتون', 'كريمات', 'زيوت', 'اخري'];
    foreach ($defaultCategories as $catName) {
        try {
            $existing = $db->queryOne("SELECT id FROM product_categories WHERE name = ?", [$catName]);
            if (empty($existing)) {
                $db->execute(
                    "INSERT INTO product_categories (name, is_default) VALUES (?, 1)",
                    [$catName]
                );
            }
        } catch (Exception $e) {
            error_log('Error inserting default category ' . $catName . ': ' . $e->getMessage());
        }
    }
} catch (Exception $e) {
    error_log('Error creating product_categories table: ' . $e->getMessage());
}

// الحصول على قائمة الأصناف
$productCategories = [];
try {
    // التحقق من وجود الجدول أولاً
    $categoriesTableExists = $db->queryOne("SHOW TABLES LIKE 'product_categories'");
    if (!empty($categoriesTableExists)) {
        $productCategories = $db->query("SELECT id, name FROM product_categories ORDER BY is_default DESC, name ASC");
        
        // إذا كانت القائمة فارغة، إضافة الأصناف الافتراضية
        if (empty($productCategories)) {
            $defaultCategories = ['عسل', 'زيت زيتون', 'كريمات', 'زيوت', 'اخري'];
            foreach ($defaultCategories as $catName) {
                try {
                    $db->execute(
                        "INSERT IGNORE INTO product_categories (name, is_default) VALUES (?, 1)",
                        [$catName]
                    );
                } catch (Exception $e) {
                    error_log('Error inserting category ' . $catName . ': ' . $e->getMessage());
                }
            }
            // إعادة جلب القائمة
            $productCategories = $db->query("SELECT id, name FROM product_categories ORDER BY is_default DESC, name ASC");
        }
    } else {
        // الجدول غير موجود، استخدام قائمة افتراضية
        error_log('product_categories table does not exist');
    }
} catch (Exception $e) {
    error_log('Error fetching product categories: ' . $e->getMessage());
}

// في حالة عدم وجود أصناف، استخدام قائمة افتراضية
if (empty($productCategories)) {
    $productCategories = [
        ['id' => 1, 'name' => 'عسل'],
        ['id' => 2, 'name' => 'زيت زيتون'],
        ['id' => 3, 'name' => 'كريمات'],
        ['id' => 4, 'name' => 'زيوت'],
        ['id' => 5, 'name' => 'اخري']
    ];
}

// الحصول على رسائل النجاح والخطأ من session (بعد redirect)
$sessionSuccess = getSuccessMessage();
if ($sessionSuccess) {
    $success = $sessionSuccess;
}

$sessionError = getErrorMessage();
if ($sessionError) {
    $error = $sessionError;
}

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_external_product') {
        $name = trim($_POST['product_name'] ?? '');
        $quantity = max(0, floatval($_POST['quantity'] ?? 0));
        $unitPrice = max(0, floatval($_POST['unit_price'] ?? 0));
        $unit = trim($_POST['unit'] ?? 'قطعة');
        $categoryId = intval($_POST['category_id'] ?? 0);
        $customCategory = trim($_POST['custom_category'] ?? '');
        
        if ($name === '') {
            $error = 'يرجى إدخال اسم المنتج.';
            // في حالة وجود خطأ في التحقق، إعادة التوجيه مع رسالة الخطأ
            preventDuplicateSubmission(
                null,
                ['page' => 'company_products'],
                null,
                $redirectRole,
                $error
            );
        } else {
            try {
                // التأكد من وجود الأعمدة المطلوبة
                try {
                    $productTypeColumn = $db->queryOne("SHOW COLUMNS FROM products LIKE 'product_type'");
                    if (empty($productTypeColumn)) {
                        $db->execute("ALTER TABLE `products` ADD COLUMN `product_type` ENUM('internal','external') DEFAULT 'internal' AFTER `category`");
                    }
                } catch (Exception $e) {
                    // العمود موجود بالفعل
                }
                
                // معالجة الصنف
                $categoryName = 'منتجات خارجية';
                if ($categoryId > 0) {
                    $category = $db->queryOne("SELECT name FROM product_categories WHERE id = ?", [$categoryId]);
                    if ($category) {
                        $categoryName = $category['name'];
                    }
                } elseif (!empty($customCategory)) {
                    // حفظ الصنف المخصص في جدول الأصناف
                    try {
                        $db->execute(
                            "INSERT INTO product_categories (name, is_default) VALUES (?, 0)",
                            [$customCategory]
                        );
                        $categoryName = $customCategory;
                    } catch (Exception $e) {
                        // الصنف موجود بالفعل
                        $categoryName = $customCategory;
                    }
                }
                
                $db->execute(
                    "INSERT INTO products (name, category, product_type, quantity, unit, unit_price, status)
                     VALUES (?, ?, 'external', ?, ?, ?, 'active')",
                    [$name, $categoryName, $quantity, $unit, $unitPrice]
                );
                
                $productId = $db->getLastInsertId();
                logAudit($currentUser['id'], 'create_external_product', 'product', $productId, null, [
                    'name' => $name,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'category' => $categoryName
                ]);
                
                // منع التكرار باستخدام redirect
                preventDuplicateSubmission(
                    'تم إضافة المنتج الخارجي بنجاح.',
                    ['page' => 'company_products'],
null,
                $redirectRole
            );
            } catch (Exception $e) {
                error_log('create_external_product error: ' . $e->getMessage());
                preventDuplicateSubmission(
                    null,
                    ['page' => 'company_products'],
                null,
                $redirectRole,
                    'تعذر إضافة المنتج الخارجي. يرجى المحاولة لاحقاً.'
                );
            }
        }
    } elseif ($action === 'update_external_product') {
        // منع المحاسب وعامل الإنتاج من التعديل على المنتجات الخارجية
        if ($currentUser['role'] === 'accountant' || $currentUser['role'] === 'production') {
            $error = 'ليس لديك صلاحية لتعديل المنتجات الخارجية.';
            // في حالة عدم وجود صلاحية، إعادة التوجيه مع رسالة الخطأ
            preventDuplicateSubmission(
                null,
                ['page' => 'company_products'],
                null,
                $redirectRole,
                $error
            );
        } else {
            $productId = intval($_POST['product_id'] ?? 0);
            $name = trim($_POST['product_name'] ?? '');
            $quantity = max(0, floatval($_POST['quantity'] ?? 0));
            $unitPrice = max(0, floatval($_POST['unit_price'] ?? 0));
            $unit = trim($_POST['unit'] ?? 'قطعة');
            $categoryId = intval($_POST['category_id'] ?? 0);
            $customCategory = trim($_POST['custom_category'] ?? '');
            
            if ($productId <= 0 || $name === '') {
                $error = 'بيانات غير صحيحة.';
                // في حالة وجود خطأ في التحقق، إعادة التوجيه مع رسالة الخطأ
                preventDuplicateSubmission(
                    null,
                    ['page' => 'company_products'],
                null,
                $redirectRole,
                    $error
                );
            } else {
                try {
                    // معالجة الصنف
                    $categoryName = null; // null يعني عدم التغيير
                    if ($categoryId > 0) {
                        $category = $db->queryOne("SELECT name FROM product_categories WHERE id = ?", [$categoryId]);
                        if ($category) {
                            $categoryName = $category['name'];
                        }
                    } elseif (!empty($customCategory)) {
                        // حفظ الصنف المخصص في جدول الأصناف
                        try {
                            $db->execute(
                                "INSERT INTO product_categories (name, is_default) VALUES (?, 0)",
                                [$customCategory]
                            );
                            $categoryName = $customCategory;
                        } catch (Exception $e) {
                            // الصنف موجود بالفعل
                            $categoryName = $customCategory;
                        }
                    }
                    
                    if ($categoryName !== null) {
                        $db->execute(
                            "UPDATE products SET name = ?, category = ?, quantity = ?, unit = ?, unit_price = ?, updated_at = NOW()
                             WHERE id = ? AND product_type = 'external'",
                            [$name, $categoryName, $quantity, $unit, $unitPrice, $productId]
                        );
                    } else {
                        $db->execute(
                            "UPDATE products SET name = ?, quantity = ?, unit = ?, unit_price = ?, updated_at = NOW()
                             WHERE id = ? AND product_type = 'external'",
                            [$name, $quantity, $unit, $unitPrice, $productId]
                        );
                    }
                    
                    logAudit($currentUser['id'], 'update_external_product', 'product', $productId, null, [
                        'name' => $name,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'category' => $categoryName
                    ]);
                    
                    // منع التكرار باستخدام redirect
                    preventDuplicateSubmission(
                        'تم تحديث المنتج الخارجي بنجاح.',
                        ['page' => 'company_products'],
null,
                $redirectRole
            );
                } catch (Exception $e) {
                    error_log('update_external_product error: ' . $e->getMessage());
                    preventDuplicateSubmission(
                        null,
                        ['page' => 'company_products'],
                null,
                $redirectRole,
                        'تعذر تحديث المنتج الخارجي. يرجى المحاولة لاحقاً.'
                    );
                }
            }
        }
    } elseif ($action === 'delete_external_product') {
        // منع المحاسب وعامل الإنتاج من الحذف على المنتجات الخارجية
        if ($currentUser['role'] === 'accountant' || $currentUser['role'] === 'production') {
            $error = 'ليس لديك صلاحية لحذف المنتجات الخارجية.';
            // في حالة عدم وجود صلاحية، إعادة التوجيه مع رسالة الخطأ
            preventDuplicateSubmission(
                null,
                ['page' => 'company_products'],
                null,
                $redirectRole,
                $error
            );
        } else {
            $productId = intval($_POST['product_id'] ?? 0);
            
            if ($productId <= 0) {
                $error = 'بيانات غير صحيحة.';
                // في حالة وجود خطأ في التحقق، إعادة التوجيه مع رسالة الخطأ
                preventDuplicateSubmission(
                    null,
                    ['page' => 'company_products'],
                null,
                $redirectRole,
                    $error
                );
            } else {
                try {
                    $db->execute(
                        "DELETE FROM products WHERE id = ? AND product_type = 'external'",
                        [$productId]
                    );
                    
                    logAudit($currentUser['id'], 'delete_external_product', 'product', $productId, null, []);
                    
                    // منع التكرار باستخدام redirect
                    preventDuplicateSubmission(
                        'تم حذف المنتج الخارجي بنجاح.',
                        ['page' => 'company_products'],
null,
                $redirectRole
            );
                } catch (Exception $e) {
                    error_log('delete_external_product error: ' . $e->getMessage());
                    preventDuplicateSubmission(
                        null,
                        ['page' => 'company_products'],
                null,
                $redirectRole,
                        'تعذر حذف المنتج الخارجي. يرجى المحاولة لاحقاً.'
                    );
                }
            }
        }
    } elseif ($action === 'add_quantity_external_product') {
        // إضافة كمية لمنتج خارجي (متاح لعامل الإنتاج والمدير والمحاسب)
        $productId = intval($_POST['product_id'] ?? 0);
        $quantityToAdd = max(0, floatval($_POST['quantity_to_add'] ?? 0));
        if ($productId <= 0 || $quantityToAdd <= 0) {
            $error = 'بيانات غير صحيحة. أدخل كمية صحيحة.';
            preventDuplicateSubmission(null, ['page' => 'company_products'], null, $redirectRole, $error);
        } else {
            try {
                $row = $db->queryOne("SELECT id, quantity FROM products WHERE id = ? AND product_type = 'external' AND status = 'active'", [$productId]);
                if (!$row) {
                    $error = 'المنتج غير موجود.';
                    preventDuplicateSubmission(null, ['page' => 'company_products'], null, $redirectRole, $error);
                } else {
                    $newQuantity = floatval($row['quantity'] ?? 0) + $quantityToAdd;
                    $db->execute("UPDATE products SET quantity = ? WHERE id = ?", [$newQuantity, $productId]);
                    logAudit($currentUser['id'], 'add_quantity_external_product', 'product', $productId, null, [
                        'quantity_added' => $quantityToAdd,
                        'new_quantity' => $newQuantity
                    ]);
                    preventDuplicateSubmission('تم إضافة الكمية بنجاح.', ['page' => 'company_products'], null, $redirectRole);
                }
            } catch (Exception $e) {
                error_log('add_quantity_external_product error: ' . $e->getMessage());
                preventDuplicateSubmission(null, ['page' => 'company_products'], null, $redirectRole, 'تعذر إضافة الكمية.');
            }
        }
    } elseif ($action === 'add_quantity_factory_product') {
        // إضافة كمية لمنتج مصنع (تشغيلة) - متاح لعامل الإنتاج والمدير والمحاسب
        $batchId = intval($_POST['batch_id'] ?? 0);
        $quantityToAdd = max(0, floatval($_POST['quantity_to_add'] ?? 0));
        if ($batchId <= 0 || $quantityToAdd <= 0) {
            $error = 'بيانات غير صحيحة. أدخل كمية صحيحة.';
            preventDuplicateSubmission(null, ['page' => 'company_products'], null, $redirectRole, $error);
        } else {
            try {
                $row = $db->queryOne("SELECT id, quantity_produced FROM finished_products WHERE id = ?", [$batchId]);
                if (!$row) {
                    $error = 'التشغيلة غير موجودة.';
                    preventDuplicateSubmission(null, ['page' => 'company_products'], null, $redirectRole, $error);
                } else {
                    $currentQty = floatval($row['quantity_produced'] ?? 0);
                    $newQuantity = $currentQty + $quantityToAdd;
                    $db->execute("UPDATE finished_products SET quantity_produced = ? WHERE id = ?", [$newQuantity, $batchId]);
                    logAudit($currentUser['id'], 'add_quantity_factory_product', 'finished_product', $batchId, null, [
                        'quantity_added' => $quantityToAdd,
                        'new_quantity' => $newQuantity
                    ]);
                    preventDuplicateSubmission('تم إضافة الكمية بنجاح.', ['page' => 'company_products'], null, $redirectRole);
                }
            } catch (Exception $e) {
                error_log('add_quantity_factory_product error: ' . $e->getMessage());
                preventDuplicateSubmission(null, ['page' => 'company_products'], null, $redirectRole, 'تعذر إضافة الكمية.');
            }
        }
    } elseif ($action === 'update_factory_product_category') {
        // منع عامل الإنتاج من تعديل الصنف
        if ($currentUser['role'] === 'production') {
            $error = 'ليس لديك صلاحية لتعديل صنف المنتج.';
            preventDuplicateSubmission(null, ['page' => 'company_products'], null, $redirectRole, $error);
        }
        $batchId = intval($_POST['batch_id'] ?? 0);
        $categoryId = intval($_POST['category_id'] ?? 0);
        $customCategory = trim($_POST['custom_category'] ?? '');
        
        if ($batchId <= 0) {
            $error = 'بيانات غير صحيحة.';
            preventDuplicateSubmission(
                null,
                ['page' => 'company_products'],
                null,
                $redirectRole,
                $error
            );
        } else {
            try {
                // معالجة الصنف
                $categoryName = null;
                if ($categoryId > 0) {
                    $category = $db->queryOne("SELECT name FROM product_categories WHERE id = ?", [$categoryId]);
                    if ($category) {
                        $categoryName = $category['name'];
                    }
                } elseif (!empty($customCategory)) {
                    // حفظ الصنف المخصص في جدول الأصناف
                    try {
                        $db->execute(
                            "INSERT INTO product_categories (name, is_default) VALUES (?, 0)",
                            [$customCategory]
                        );
                        $categoryName = $customCategory;
                    } catch (Exception $e) {
                        // الصنف موجود بالفعل
                        $categoryName = $customCategory;
                    }
                }
                
                if ($categoryName !== null) {
                    // تحديث الصنف في جدول products المرتبط بـ finished_products
                    $finishedProduct = $db->queryOne("
                        SELECT product_id FROM finished_products WHERE id = ?
                    ", [$batchId]);
                    
                    if ($finishedProduct && $finishedProduct['product_id']) {
                        $db->execute(
                            "UPDATE products SET category = ? WHERE id = ?",
                            [$categoryName, $finishedProduct['product_id']]
                        );
                    }
                }
                
                logAudit($currentUser['id'], 'update_factory_product_category', 'finished_product', $batchId, null, [
                    'category' => $categoryName
                ]);
                
                preventDuplicateSubmission(
                    'تم تحديث صنف المنتج بنجاح.',
                    ['page' => 'company_products'],
null,
                $redirectRole
            );
            } catch (Exception $e) {
                error_log('update_factory_product_category error: ' . $e->getMessage());
                preventDuplicateSubmission(
                    null,
                    ['page' => 'company_products'],
                null,
                $redirectRole,
                    'تعذر تحديث صنف المنتج. يرجى المحاولة لاحقاً.'
                );
            }
        }
    } elseif ($action === 'update_factory_product_price') {
        // منع عامل الإنتاج من تعديل السعر
        if ($currentUser['role'] === 'production') {
            $error = 'ليس لديك صلاحية لتعديل سعر المنتج.';
            preventDuplicateSubmission(null, ['page' => 'company_products'], null, $redirectRole, $error);
        }
        // تعديل سعر منتج المصنع (متاح للمدير والمحاسب)
        $batchId = intval($_POST['batch_id'] ?? 0);
        $unitPrice = max(0, floatval($_POST['unit_price'] ?? 0));

        if ($batchId <= 0) {
            $error = 'بيانات غير صحيحة.';
            preventDuplicateSubmission(
                null,
                ['page' => 'company_products'],
                null,
                $redirectRole,
                $error
            );
        } else {
            try {
                $db->execute(
                    "UPDATE finished_products SET unit_price = ? WHERE id = ?",
                    [$unitPrice, $batchId]
                );
                logAudit($currentUser['id'], 'update_factory_product_price', 'finished_product', $batchId, null, [
                    'unit_price' => $unitPrice
                ]);
                preventDuplicateSubmission(
                    'تم تحديث سعر المنتج بنجاح.',
                    ['page' => 'company_products'],
null,
                $redirectRole
            );
            } catch (Exception $e) {
                error_log('update_factory_product_price error: ' . $e->getMessage());
                preventDuplicateSubmission(
                    null,
                    ['page' => 'company_products'],
                null,
                $redirectRole,
                    'تعذر تحديث السعر. يرجى المحاولة لاحقاً.'
                );
            }
        }
    } elseif ($action === 'create_factory_product') {
        $productName = trim($_POST['product_name'] ?? '');
        $categoryId = intval($_POST['category_id'] ?? 0);
        $customCategory = trim($_POST['custom_category'] ?? '');
        $productionDate = trim($_POST['production_date'] ?? '');
        $expiryDate = trim($_POST['expiry_date'] ?? '');
        $quantityProduced = max(0.01, floatval($_POST['quantity_produced'] ?? 0));
        $unitPrice = max(0, floatval($_POST['unit_price'] ?? 0));
        
        if ($productName === '' || $productionDate === '' || $quantityProduced <= 0) {
            $error = 'يرجى إدخال جميع البيانات المطلوبة.';
            preventDuplicateSubmission(
                null,
                ['page' => 'company_products'],
                null,
                $redirectRole,
                $error
            );
        } else {
            try {
                require_once __DIR__ . '/../../includes/batch_creation.php';
                
                // معالجة الصنف
                $categoryName = 'اخري';
                if ($categoryId > 0) {
                    $category = $db->queryOne("SELECT name FROM product_categories WHERE id = ?", [$categoryId]);
                    if ($category) {
                        $categoryName = $category['name'];
                    }
                } elseif (!empty($customCategory)) {
                    // حفظ الصنف المخصص في جدول الأصناف
                    try {
                        $db->execute(
                            "INSERT INTO product_categories (name, is_default) VALUES (?, 0)",
                            [$customCategory]
                        );
                        $categoryName = $customCategory;
                    } catch (Exception $e) {
                        // الصنف موجود بالفعل
                        $categoryName = $customCategory;
                    }
                }
                
                // التحقق من وجود جدول finished_products
                $finishedProductsTableExists = $db->queryOne("SHOW TABLES LIKE 'finished_products'");
                if (empty($finishedProductsTableExists)) {
                    throw new Exception('جدول finished_products غير موجود');
                }
                
                // التحقق من وجود جدول batches
                $batchesTableExists = $db->queryOne("SHOW TABLES LIKE 'batches'");
                if (empty($batchesTableExists)) {
                    throw new Exception('جدول batches غير موجود');
                }
                
                // توليد رقم باركود باستخدام دالة batchCreationGenerateNumber
                $pdo = batchCreationGetPdo();
                $batchNumber = batchCreationGenerateNumber($pdo);
                
                // إنشاء batch_id في جدول batches
                $batchInsertResult = $db->execute(
                    "INSERT INTO batches (batch_number, product_id, production_date, expiry_date, quantity, status)
                     VALUES (?, NULL, ?, ?, ?, 'completed')",
                    [$batchNumber, $productionDate, !empty($expiryDate) ? $expiryDate : null, (int)$quantityProduced]
                );
                
                $batchId = $batchInsertResult['insert_id'] ?? $db->getLastInsertId();
                
                if (empty($batchId)) {
                    throw new Exception('فشل في إنشاء batch_id');
                }
                
                // البحث عن product_id من جدول products أو إنشاء منتج جديد
                $productId = null;
                $existingProduct = $db->queryOne(
                    "SELECT id FROM products WHERE name = ? AND category = ? LIMIT 1",
                    [$productName, $categoryName]
                );
                
                if ($existingProduct) {
                    $productId = $existingProduct['id'];
                } else {
                    // إنشاء منتج جديد في جدول products
                    $productInsertResult = $db->execute(
                        "INSERT INTO products (name, category, product_type, quantity, unit, unit_price, status)
                         VALUES (?, ?, 'internal', 0, 'قطعة', ?, 'active')",
                        [$productName, $categoryName, $unitPrice > 0 ? $unitPrice : 0]
                    );
                    $productId = $productInsertResult['insert_id'] ?? $db->getLastInsertId();
                }
                
                // إدراج المنتج في جدول finished_products
                $db->execute(
                    "INSERT INTO finished_products (batch_id, product_id, product_name, batch_number, production_date, expiry_date, quantity_produced, unit_price)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $batchId,
                        $productId,
                        $productName,
                        $batchNumber,
                        $productionDate,
                        !empty($expiryDate) ? $expiryDate : null,
                        (int)$quantityProduced,
                        $unitPrice > 0 ? $unitPrice : null
                    ]
                );
                
                $finishedProductId = $db->getLastInsertId();
                
                logAudit($currentUser['id'] ?? null, 'create_factory_product', 'finished_product', $finishedProductId, null, [
                    'product_name' => $productName,
                    'batch_number' => $batchNumber,
                    'category' => $categoryName,
                    'quantity_produced' => $quantityProduced,
                    'unit_price' => $unitPrice
                ]);
                
                preventDuplicateSubmission(
                    'تم إضافة منتج المصنع بنجاح. رقم الباركود: ' . $batchNumber,
                    ['page' => 'company_products'],
null,
                $redirectRole
            );
            } catch (Exception $e) {
                error_log('create_factory_product error: ' . $e->getMessage());
                preventDuplicateSubmission(
                    null,
                    ['page' => 'company_products'],
                null,
                $redirectRole,
                    'تعذر إضافة منتج المصنع. يرجى المحاولة لاحقاً: ' . $e->getMessage()
                );
            }
        }
    }
}

// الحصول على منتجات المصنع (من جدول finished_products - كل تشغيلة منفصلة)
$factoryProducts = [];
try {
    // التحقق من وجود جدول finished_products
    $finishedProductsTableExists = $db->queryOne("SHOW TABLES LIKE 'finished_products'");
    
    if (!empty($finishedProductsTableExists)) {
        $factoryProducts = $db->query("
            SELECT 
                base.id,
                base.batch_id,
                base.batch_number,
                base.product_id,
                base.product_name,
                base.product_category,
                base.production_date,
                base.quantity_produced,
                base.unit_price,
                base.total_price,
                CASE 
                    WHEN base.unit_price > 0 
                        THEN (base.unit_price * COALESCE(base.quantity_produced, 0))
                    WHEN base.unit_price = 0 AND base.total_price IS NOT NULL AND base.total_price > 0 
                        THEN base.total_price
                    ELSE 0
                END AS calculated_total_price,
                base.workers
            FROM (
                SELECT 
                    fp.id,
                    fp.batch_id,
                    fp.batch_number,
                    COALESCE(fp.product_id, bn.product_id) AS product_id,
                    COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name, 'غير محدد') AS product_name,
                    pr.category as product_category,
                    fp.production_date,
                    fp.quantity_produced,
                    COALESCE(
                        NULLIF(fp.unit_price, 0),
                        (SELECT pt.unit_price 
                         FROM product_templates pt 
                         WHERE pt.status = 'active' 
                           AND pt.unit_price IS NOT NULL 
                           AND pt.unit_price > 0
                           AND pt.unit_price <= 10000
                           AND (
                               -- مطابقة product_id أولاً (الأكثر دقة)
                               (
                                   COALESCE(fp.product_id, bn.product_id) IS NOT NULL 
                                   AND COALESCE(fp.product_id, bn.product_id) > 0
                                   AND pt.product_id IS NOT NULL 
                                   AND pt.product_id > 0 
                                   AND pt.product_id = COALESCE(fp.product_id, bn.product_id)
                               )
                               -- مطابقة product_name (مطابقة دقيقة أو جزئية)
                               OR (
                                   pt.product_name IS NOT NULL 
                                   AND pt.product_name != ''
                                   AND COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name) IS NOT NULL
                                   AND COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name) != ''
                                   AND (
                                       LOWER(TRIM(pt.product_name)) = LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name)))
                                       OR LOWER(TRIM(pt.product_name)) LIKE CONCAT('%', LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name))), '%')
                                       OR LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name))) LIKE CONCAT('%', LOWER(TRIM(pt.product_name)), '%')
                                   )
                               )
                               -- إذا لم يكن هناك product_id في القالب، نبحث فقط بالاسم
                               OR (
                                   (pt.product_id IS NULL OR pt.product_id = 0)
                                   AND pt.product_name IS NOT NULL 
                                   AND pt.product_name != ''
                                   AND COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name) IS NOT NULL
                                   AND COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name) != ''
                                   AND (
                                       LOWER(TRIM(pt.product_name)) = LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name)))
                                       OR LOWER(TRIM(pt.product_name)) LIKE CONCAT('%', LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name))), '%')
                                       OR LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name))) LIKE CONCAT('%', LOWER(TRIM(pt.product_name)), '%')
                                   )
                               )
                           )
                         ORDER BY pt.unit_price DESC
                         LIMIT 1),
                        0
                    ) AS unit_price,
                    fp.total_price,
                    GROUP_CONCAT(DISTINCT u.full_name ORDER BY u.full_name SEPARATOR ', ') AS workers
                FROM finished_products fp
                LEFT JOIN batch_numbers bn ON fp.batch_number = bn.batch_number
                LEFT JOIN products pr ON COALESCE(fp.product_id, bn.product_id) = pr.id
                LEFT JOIN batch_workers bw ON fp.batch_id = bw.batch_id
                LEFT JOIN users u ON bw.employee_id = u.id
                WHERE (fp.quantity_produced IS NULL OR fp.quantity_produced > 0)
                GROUP BY fp.id
            ) AS base
            ORDER BY base.production_date DESC, base.id DESC
        ");
    }
} catch (Exception $e) {
    error_log('Error fetching factory products from finished_products: ' . $e->getMessage());
}

// حساب الكمية المتاحة لكل منتج من منتجات المصنع (طرح المبيعات والطلبات المعلقة)
if (!empty($factoryProducts)) {
    foreach ($factoryProducts as &$product) {
        $quantityProduced = (float)($product['quantity_produced'] ?? 0);
        $batchNumber = $product['batch_number'] ?? '';
        $batchId = $product['id'] ?? null;
        
        // حساب الكمية المتاحة (طرح المبيعات والطلبات المعلقة)
        $soldQty = 0;
        $pendingQty = 0;
        $pendingShippingQty = 0;
        
        if (!empty($batchNumber) && $batchId) {
            try {
                // حساب الكمية المباعة
                $sold = $db->queryOne("
                    SELECT COALESCE(SUM(ii.quantity), 0) AS sold_quantity
                    FROM invoice_items ii
                    INNER JOIN invoices i ON ii.invoice_id = i.id
                    INNER JOIN sales_batch_numbers sbn ON ii.id = sbn.invoice_item_id
                    INNER JOIN batch_numbers bn ON sbn.batch_number_id = bn.id
                    WHERE bn.batch_number = ?
                ", [$batchNumber]);
                $soldQty = (float)($sold['sold_quantity'] ?? 0);
                
                // حساب الكمية المحجوزة في طلبات العملاء المعلقة
                $pending = $db->queryOne("
                    SELECT COALESCE(SUM(oi.quantity), 0) AS pending_quantity
                    FROM customer_order_items oi
                    INNER JOIN customer_orders co ON oi.order_id = co.id
                    INNER JOIN finished_products fp2 ON fp2.product_id = oi.product_id AND fp2.batch_number = ?
                    WHERE co.status = 'pending'
                ", [$batchNumber]);
                $pendingQty = (float)($pending['pending_quantity'] ?? 0);
                
                // ملاحظة: لا نحتاج لخصم طلبات الشحن من quantity_produced
                // لأن quantity_produced يتم تحديثه مباشرة عند إنشاء طلب الشحن
                // (يتم خصم الكمية منه عبر recordInventoryMovement)
                // لذلك quantity_produced يحتوي بالفعل على الكمية المتبقية بعد خصم طلبات الشحن
                // نحسب pendingShippingQty فقط للعرض (للمعلومات) وليس للخصم
                $pendingShipping = $db->queryOne("
                    SELECT COALESCE(SUM(soi.quantity), 0) AS pending_quantity
                    FROM shipping_company_order_items soi
                    INNER JOIN shipping_company_orders sco ON soi.order_id = sco.id
                    WHERE sco.status = 'in_transit'
                      AND soi.batch_id = ?
                ", [$batchId]);
                $pendingShippingQty = (float)($pendingShipping['pending_quantity'] ?? 0);
            } catch (Throwable $calcError) {
                error_log('company_products: error calculating available quantity for batch ' . $batchNumber . ': ' . $calcError->getMessage());
            }
        }
        
        // حساب الكمية المتاحة
        // ملاحظة: quantity_produced يتم تحديثه تلقائياً عند المبيعات وطلبات الشحن
        // لذلك نحتاج فقط خصم طلبات العملاء المعلقة (pendingQty)
        $product['available_quantity'] = max(0, $quantityProduced - $pendingQty);
        $product['sold_quantity'] = $soldQty;
        $product['pending_quantity'] = $pendingQty;
        $product['pending_shipping_quantity'] = $pendingShippingQty;
    }
    unset($product); // إلغاء المرجع لتجنب التأثير على المتغيرات المستقبلية
}

// الحصول على المنتجات الخارجية
$externalProducts = [];
try {
    $externalProducts = $db->query("
        SELECT 
            id,
            name,
            COALESCE(category, '') as category,
            quantity,
            COALESCE(unit, 'قطعة') as unit,
            unit_price,
            (quantity * unit_price) as total_value,
            created_at,
            updated_at
        FROM products
        WHERE product_type = 'external'
          AND status = 'active'
        ORDER BY name ASC
    ");
} catch (Exception $e) {
    error_log('Error fetching external products: ' . $e->getMessage());
}

// إحصائيات
$totalFactoryProducts = count($factoryProducts);
$totalExternalProducts = count($externalProducts);
$totalExternalValue = 0;
foreach ($externalProducts as $ext) {
    $totalExternalValue += floatval($ext['total_value'] ?? 0);
}

// حساب القيمة الإجمالية لمنتجات المصنع بناءً على الكمية المتاحة
$totalFactoryValue = 0;
foreach ($factoryProducts as $product) {
    $unitPrice = floatval($product['unit_price'] ?? 0);
    $availableQuantity = floatval($product['available_quantity'] ?? $product['quantity_produced'] ?? 0);
    $totalPrice = $unitPrice * $availableQuantity;
    $totalFactoryValue += $totalPrice;
}
?>

<style>
* {
    box-sizing: border-box;
}

.company-products-page {
    padding: 1.5rem 0;
    width: 100%;
    max-width: 100%;
    overflow-x: hidden;
    box-sizing: border-box;
}

/* ضمان أن جميع العناصر داخل الصفحة لا تتجاوز العرض */
.company-products-page * {
    max-width: 100%;
    box-sizing: border-box;
}

.section-header {
    background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
    color: white;
    padding: 1.5rem 1.75rem;
    border-radius: 16px 16px 0 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 4px 12px rgba(29, 78, 216, 0.15);
    width: 100%;
    max-width: 100%;
    flex-wrap: wrap;
    gap: 1rem;
}

.section-header h5 {
    margin: 0;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-size: 1.15rem;
}

.section-header h5 i {
    font-size: 1.4rem;
    opacity: 0.95;
}

.section-header .badge {
    background: rgba(255, 255, 255, 0.25);
    color: white;
    padding: 0.45rem 0.9rem;
    border-radius: 25px;
    font-size: 0.875rem;
    font-weight: 600;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.company-card {
    border: none;
    border-radius: 16px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
    margin-bottom: 2.5rem;
    overflow: hidden;
    background: #ffffff;
    transition: all 0.3s ease;
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
}

.company-card:hover {
    box-shadow: 0 12px 32px rgba(0, 0, 0, 0.12);
    transform: translateY(-2px);
}

.company-card .card-body {
    padding: 2rem;
    width: 100%;
    max-width: 100%;
    overflow-x: hidden;
}

/* تحسين تصميم الجداول */
.company-card .dashboard-table-wrapper {
    border-radius: 12px;
    border: 1px solid rgba(226, 232, 240, 0.8);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
    overflow: hidden;
    background: #ffffff;
}

.company-card .dashboard-table thead th {
    background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
    color: #ffffff;
    font-weight: 600;
    letter-spacing: 0.03em;
    text-transform: uppercase;
    font-size: 0.8rem;
    padding: 1rem 1.25rem;
    border-right: 1px solid rgba(255, 255, 255, 0.15);
    white-space: nowrap;
    position: relative;
}

.company-card .dashboard-table thead th:first-child {
    padding-left: 1.5rem;
}

.company-card .dashboard-table thead th:last-child {
    border-right: none;
    padding-right: 1.5rem;
}

.company-card .dashboard-table tbody tr {
    transition: all 0.2s ease;
    border-bottom: 1px solid rgba(226, 232, 240, 0.5);
}

.company-card .dashboard-table tbody tr:hover {
    background: linear-gradient(90deg, rgba(29, 78, 216, 0.05) 0%, rgba(37, 99, 235, 0.08) 100%) !important;
    transform: scale(1.01);
    box-shadow: 0 2px 8px rgba(29, 78, 216, 0.1);
}

.company-card .dashboard-table tbody tr:nth-child(even) {
    background: rgba(248, 250, 252, 0.6);
}

.company-card .dashboard-table tbody tr:nth-child(even):hover {
    background: linear-gradient(90deg, rgba(29, 78, 216, 0.08) 0%, rgba(37, 99, 235, 0.12) 100%) !important;
}

.company-card .dashboard-table tbody td {
    padding: 1rem 1.25rem;
    vertical-align: middle;
    font-size: 0.9rem;
    color: #1e293b;
    border: none;
}

.company-card .dashboard-table tbody td:first-child {
    padding-left: 1.5rem;
    font-weight: 600;
    color: #0f172a;
}

.company-card .dashboard-table tbody td:last-child {
    padding-right: 1.5rem;
}

.company-card .dashboard-table tbody td strong {
    font-weight: 600;
    color: #0f172a;
}

/* تحسين الألوان للقيم */
.company-card .dashboard-table tbody td .text-success {
    color: #059669 !important;
    font-weight: 600;
    font-size: 1rem;
}

.company-card .dashboard-table tbody td .text-primary {
    color: #1d4ed8 !important;
    font-weight: 600;
    font-size: 1rem;
}

/* إحصائيات المنتجات الخارجية */
.total-value-box {
    background: linear-gradient(135deg, rgba(5, 150, 105, 0.1) 0%, rgba(16, 185, 129, 0.15) 100%);
    border: 1px solid rgba(5, 150, 105, 0.2);
    border-radius: 12px;
    padding: 1.25rem 1.5rem;
    margin-bottom: 1.5rem;
    width: 100%;
    max-width: 100%;
    overflow: hidden;
}

.total-value-box .fw-bold {
    color: #0f766e;
    font-size: 1rem;
}

.total-value-box .text-success {
    color: #059669 !important;
    font-size: 1.5rem;
    font-weight: 700;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}

.btn-primary-custom {
    background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
    border: none;
    color: white;
    padding: 0.65rem 1.4rem;
    border-radius: 10px;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(29, 78, 216, 0.25);
}

.btn-primary-custom:hover {
    background: linear-gradient(135deg, #1e40af 0%, #1d4ed8 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(29, 78, 216, 0.35);
    color: white;
}

.btn-success-custom {
    background: linear-gradient(135deg, #059669 0%, #10b981 100%);
    border: none;
    color: white;
    padding: 0.65rem 1.4rem;
    border-radius: 10px;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.25);
}

.btn-success-custom:hover {
    background: linear-gradient(135deg, #047857 0%, #059669 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(5, 150, 105, 0.35);
    color: white;
}

.btn-success-custom.btn-sm {
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
}

/* تحسين الأزرار في الجداول */
.company-card .btn-group-sm .btn {
    border-radius: 8px;
    font-weight: 500;
    padding: 0.4rem 0.8rem;
    font-size: 0.8rem;
    transition: all 0.2s ease;
}

.company-card .btn-outline-primary {
    border-color: #1d4ed8;
    color: #1d4ed8;
}

.company-card .btn-outline-primary:hover {
    background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
    border-color: #1d4ed8;
    color: white;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(29, 78, 216, 0.2);
}

.company-card .btn-outline-danger {
    border-color: #dc2626;
    color: #dc2626;
}

.company-card .btn-outline-danger:hover {
    background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%);
    border-color: #dc2626;
    color: white;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(220, 38, 38, 0.2);
}

/* حالة فارغة */
.company-card .dashboard-table tbody tr td.text-center {
    padding: 3rem 1.5rem;
    color: #94a3b8;
    font-style: italic;
}

@media (max-width: 768px) {
    .company-products-page {
        padding: 1rem 0;
    }
    
    .section-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
        padding: 1.25rem 1.5rem;
        width: 100%;
        max-width: 100%;
    }
    
    .section-header h5 {
        font-size: 1rem;
        word-wrap: break-word;
        width: 100%;
    }
    
    .section-header .badge {
        font-size: 0.8rem;
        padding: 0.4rem 0.8rem;
    }
    
    .company-card {
        width: 100%;
        max-width: 100%;
        margin-bottom: 1.5rem;
    }
    
    .company-card .card-body {
        padding: 1.25rem;
        width: 100%;
        max-width: 100%;
        overflow-x: hidden;
    }
    
    .total-value-box {
        padding: 1rem 1.25rem;
        margin-bottom: 1.25rem;
        width: 100%;
        max-width: 100%;
    }
    
    .total-value-box .d-flex {
        flex-direction: column;
        gap: 0.5rem;
        align-items: flex-start !important;
    }
    
    .total-value-box .fw-bold {
        font-size: 0.9rem;
        word-wrap: break-word;
    }
    
    .total-value-box .text-success {
        font-size: 1.25rem !important;
    }
    
    .products-grid {
        padding: 15px;
        grid-template-columns: 1fr !important;
        gap: 15px;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box;
        margin: 0;
    }
    
    .product-card {
        padding: 20px;
        border-radius: 12px;
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        box-sizing: border-box;
        margin: 0;
        overflow: hidden;
    }
    
    .product-status {
        top: 10px;
        left: 10px;
        padding: 5px 12px;
        font-size: 11px;
    }
    
    .product-name {
        font-size: 16px;
        margin-bottom: 5px;
        word-wrap: break-word;
    }
    
    .product-batch-id {
        font-size: 13px;
        word-wrap: break-word;
    }
    
    .product-barcode-box {
        padding: 12px;
        margin: 12px 0;
    }
    
    .product-barcode-container {
        min-height: 50px;
    }
    
    .product-barcode-container svg {
        max-width: 100%;
        height: auto;
    }
    
    .product-barcode-id {
        font-size: 12px;
        margin-top: 6px;
        word-wrap: break-word;
    }
    
    .product-detail-row {
        font-size: 13px;
        margin-top: 4px;
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    
    .product-detail-row span {
        word-wrap: break-word;
    }
    
    .product-card > div[style*="display: flex"] {
        flex-direction: row !important;
        gap: 10px;
        flex-wrap: wrap;
    }
    
    .product-card button {
        flex: 1 1 auto !important;
        min-width: calc(50% - 5px);
        padding: 10px 14px !important;
        font-size: 12px !important;
    }
    
    .company-card .dashboard-table-wrapper {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        width: 100%;
        max-width: 100%;
    }
    
    .company-card .dashboard-table-wrapper::-webkit-scrollbar {
        height: 8px;
    }
    
    .company-card .dashboard-table-wrapper::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 4px;
    }
    
    .company-card .dashboard-table-wrapper::-webkit-scrollbar-thumb {
        background: #888;
        border-radius: 4px;
    }
    
    .company-card .dashboard-table {
        min-width: 500px;
    }
    
    .company-card .dashboard-table thead th,
    .company-card .dashboard-table tbody td {
        padding: 0.75rem 0.85rem;
        font-size: 0.85rem;
    }
    
    .company-card .dashboard-table thead th:first-child {
        padding-left: 1rem;
    }
    
    .company-card .dashboard-table tbody td:first-child {
        padding-left: 1rem;
    }
}

@media (max-width: 576px) {
    .company-products-page {
        padding: 0.75rem 0;
    }
    
    .company-products-page h2 {
        font-size: 1.25rem;
        word-wrap: break-word;
    }
    
    .section-header {
        padding: 1rem 1.25rem;
        gap: 0.75rem;
    }
    
    .section-header h5 {
        font-size: 0.95rem;
    }
    
    .section-header .badge {
        font-size: 0.75rem;
        padding: 0.35rem 0.7rem;
    }
    
    .company-card .card-body {
        padding: 1rem;
    }
    
    .total-value-box {
        padding: 0.875rem 1rem;
        margin-bottom: 1rem;
    }
    
    .total-value-box .fw-bold {
        font-size: 0.85rem;
    }
    
    .total-value-box .text-success {
        font-size: 1.1rem !important;
    }
    
    .products-grid {
        padding: 12px;
        gap: 12px;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box;
    }
    
    .product-card {
        padding: 15px;
        border-radius: 10px;
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        box-sizing: border-box;
    }
    
    .product-status {
        top: 8px;
        left: 8px;
        padding: 4px 10px;
        font-size: 10px;
    }
    
    .product-name {
        font-size: 15px;
    }
    
    .product-batch-id {
        font-size: 12px;
    }
    
    .product-barcode-box {
        padding: 10px;
        margin: 10px 0;
    }
    
    .product-barcode-container {
        min-height: 45px;
    }
    
    .product-barcode-id {
        font-size: 11px;
    }
    
    .product-detail-row {
        font-size: 12px;
    }
    
    .product-card > div[style*="display: flex"] {
        flex-direction: row !important;
        gap: 8px;
    }
    
    .product-card button {
        flex: 1 1 auto !important;
        min-width: calc(50% - 4px);
        padding: 8px 12px !important;
        font-size: 11px !important;
    }
    
    .company-card .dashboard-table {
        min-width: 450px;
        font-size: 0.8rem;
    }
    
    .company-card .dashboard-table thead th,
    .company-card .dashboard-table tbody td {
        padding: 0.6rem 0.7rem;
        font-size: 0.8rem;
    }
    
    .btn-success-custom.btn-sm {
        padding: 0.4rem 0.8rem;
        font-size: 0.8rem;
    }
}
</style>

<div class="company-products-page">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2" style="width: 100%; max-width: 100%;">
        <h2 class="mb-0" style="word-wrap: break-word; width: 100%; max-width: 100%;"><i class="bi bi-box-seam me-2 text-primary"></i>منتجات الشركة</h2>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" id="errorAlert" data-auto-refresh="true">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" id="successAlert" data-auto-refresh="true">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- قسم منتجات المصنع -->
    <div class="card company-card mb-4" id="factoryProductsSection">
        <div class="section-header">
            <h5>
                <i class="bi bi-building"></i>
                منتجات المصنع
            </h5>
            <span class="badge" id="factoryProductsCount"><?php echo $totalFactoryProducts; ?> منتج</span>
            <button type="button" class="btn btn-primary btn-sm" id="toggleAddFactoryProductCard" style="margin-right: 10px;">
                <i class="bi bi-plus-circle me-1"></i>إضافة منتج مصنع جديد
            </button>
        </div>
        <div class="card-body">
            <!-- بطاقة إضافة منتج مصنع جديد -->
            <div class="card mb-4" id="addFactoryProductCard" style="display: none; border: 2px solid #0d6efd;">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>إضافة منتج مصنع جديد</h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="addFactoryProductForm">
                        <input type="hidden" name="action" value="create_factory_product">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">اسم المنتج <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="product_name" id="add_factory_product_name" required placeholder="أدخل اسم المنتج">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">الصنف <span class="text-danger">*</span></label>
                                <select class="form-control" name="category_id" id="add_factory_category_id" required>
                                    <option value="">اختر الصنف</option>
                                    <?php if (!empty($productCategories)): ?>
                                        <?php foreach ($productCategories as $cat): ?>
                                            <option value="<?php echo intval($cat['id']); ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <option value="1">عسل</option>
                                        <option value="2">زيت زيتون</option>
                                        <option value="3">كريمات</option>
                                        <option value="4">زيوت</option>
                                        <option value="5">اخري</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3" id="add_factory_custom_category_div" style="display: none;">
                            <label class="form-label">أدخل الصنف يدوياً <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="custom_category" id="add_factory_custom_category" placeholder="أدخل اسم الصنف">
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">تاريخ الإنتاج <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="production_date" id="add_factory_production_date" required value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">تاريخ انتهاء الصلاحية</label>
                                <input type="date" class="form-control" name="expiry_date" id="add_factory_expiry_date">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">الكمية المنتجة <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="quantity_produced" id="add_factory_quantity" required min="0.01" step="0.01" placeholder="أدخل الكمية">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">سعر الوحدة</label>
                                <input type="number" class="form-control" name="unit_price" id="add_factory_unit_price" min="0" step="0.01" placeholder="أدخل سعر الوحدة">
                            </div>
                        </div>
                        <div class="alert alert-info mb-3">
                            <i class="bi bi-info-circle me-2"></i>
                            سيتم توليد رقم باركود تلقائياً بصيغة: تاريخ اليوم - رقم عشوائي من 6 أرقام
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle me-1"></i>إضافة المنتج
                            </button>
                            <button type="button" class="btn btn-secondary" id="cancelAddFactoryProduct">
                                <i class="bi bi-x-circle me-1"></i>إلغاء
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- شريط البحث والفلترة لمنتجات المصنع -->
            <div class="mb-3 p-3 bg-light rounded" style="border: 1px solid #dee2e6;">
                <div class="row g-3">
                    <div class="col-6 col-md-6">
                        <label class="form-label small mb-1"><i class="bi bi-search me-1"></i>البحث</label>
                        <input type="text" 
                               class="form-control form-control-sm" 
                               id="factorySearchInput" 
                               placeholder="اسم المنتج، رقم تشغيلة..." 
                               autocomplete="off">
                    </div>
                    <div class="col-6 col-md-6">
                        <label class="form-label small mb-1"><i class="bi bi-folder me-1"></i>الصنف</label>
                        <select class="form-control form-control-sm" id="factoryCategoryFilter">
                            <option value="">جميع الأصناف</option>
                            <?php if (!empty($productCategories)): ?>
                                <?php foreach ($productCategories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat['name']); ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="عسل">عسل</option>
                                <option value="زيت زيتون">زيت زيتون</option>
                                <option value="كريمات">كريمات</option>
                                <option value="زيوت">زيوت</option>
                                <option value="اخري">اخري</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-6">
                        <label class="form-label small mb-1"><i class="bi bi-currency-dollar me-1"></i>سعر من</label>
                        <input type="number" 
                               class="form-control form-control-sm" 
                               id="factoryMinPrice" 
                               placeholder="من" 
                               step="0.01" 
                               min="0">
                    </div>
                    <div class="col-6 col-md-6">
                        <label class="form-label small mb-1"><i class="bi bi-currency-dollar me-1"></i>سعر إلى</label>
                        <input type="number" 
                               class="form-control form-control-sm" 
                               id="factoryMaxPrice" 
                               placeholder="إلى" 
                               step="0.01" 
                               min="0">
                    </div>
                </div>
            </div>
            
            <div id="factoryProductsStats" style="<?php echo empty($factoryProducts) ? 'display:none;' : ''; ?>">
                <div class="total-value-box">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold">القيمة الإجمالية لمنتجات المصنع:</span>
                        <span class="text-success fw-bold" id="factoryTotalValue"><?php echo formatCurrency($totalFactoryValue); ?></span>
                    </div>
                </div>
            </div>
            
            <style>
                body {
                    font-family: 'Cairo', sans-serif;
                }

                .products-grid {
                    padding: 25px;
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
                    gap: 20px;
                    width: 100%;
                    max-width: 100%;
                }

                .product-card {
                    background: white;
                    padding: 25px;
                    border-radius: 18px;
                    box-shadow: 0px 4px 20px rgba(0,0,0,0.07);
                    border: 1px solid #e2e6f3;
                    position: relative;
                    width: 100%;
                    max-width: 100%;
                    overflow: hidden;
                    box-sizing: border-box;
                }
                
                /* تحسين عرض البطاقات على الهواتف */
                @media (max-width: 768px) {
                    .products-grid {
                        padding: 15px;
                        grid-template-columns: 1fr !important;
                        gap: 15px;
                        width: 100% !important;
                        max-width: 100% !important;
                        box-sizing: border-box;
                    }
                    
                    .product-card {
                        width: 100% !important;
                        max-width: 100% !important;
                        min-width: 0 !important;
                        padding: 20px;
                        margin: 0;
                        box-sizing: border-box;
                    }
                    
                    .product-actions {
                        flex-direction: row !important;
                        flex-wrap: wrap;
                    }
                    
                    .product-actions button,
                    .product-actions .btn-view {
                        flex: 1 1 auto !important;
                        min-width: calc(50% - 5px) !important;
                    }
                }
                
                @media (max-width: 576px) {
                    .products-grid {
                        padding: 12px;
                        gap: 12px;
                    }
                    
                    .product-card {
                        padding: 15px;
                        border-radius: 12px;
                    }
                    
                    .product-actions {
                        gap: 8px;
                    }
                    
                    .product-actions button,
                    .product-actions .btn-view {
                        min-width: calc(50% - 4px) !important;
                        font-size: 12px !important;
                        padding: 8px 12px !important;
                    }
                }

                .product-status {
                    position: absolute;
                    top: 15px;
                    left: 15px;
                    background: #2e89ff;
                    padding: 6px 14px;
                    border-radius: 20px;
                    color: white;
                    font-size: 12px;
                    font-weight: bold;
                }

                .product-name {
                    font-size: 18px;
                    font-weight: bold;
                    color: #0d2f66;
                    margin-bottom: 6px;
                }

                .product-batch-id {
                    color: #2767ff;
                    font-weight: bold;
                    text-decoration: none;
                }

                .product-barcode-box {
                    background: #f8faff;
                    border: 1px solid #d7e1f3;
                    padding: 15px;
                    border-radius: 12px;
                    text-align: center;
                    margin: 15px 0;
                }

                .product-barcode-id {
                    font-weight: bold;
                    margin-top: 8px;
                    color: #123c90;
                }

                .product-detail-row {
                    font-size: 14px;
                    margin-top: 5px;
                    color: #4b5772;
                    display: flex;
                    justify-content: space-between;
                }

                .product-barcode-container {
                    width: 100%;
                    min-height: 60px;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                }

                .product-barcode-container svg {
                    max-width: 100%;
                    height: auto;
                }
            </style>

            <!-- تحميل مكتبة JsBarcode -->
            <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>

            <div id="factoryProductsContainer">
            <?php if (empty($factoryProducts)): ?>
                <div style="padding: 25px;">
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        لا توجد منتجات مصنع حالياً
                    </div>
                </div>
            <?php else: ?>
                <div class="products-grid" id="factoryProductsGrid">
                    <?php foreach ($factoryProducts as $product): ?>
                        <?php
                            $batchNumber = $product['batch_number'] ?? '';
                            $productName = htmlspecialchars($product['product_name'] ?? 'غير محدد');
                            $category = htmlspecialchars($product['product_category'] ?? '—');
                            $productionDate = !empty($product['production_date']) ? htmlspecialchars(formatDate($product['production_date'])) : '—';
                            $quantityProduced = (float)($product['quantity_produced'] ?? 0);
                            $availableQuantity = (float)($product['available_quantity'] ?? $quantityProduced);
                            $quantity = number_format($availableQuantity, 2);
                            $unitPrice = floatval($product['unit_price'] ?? 0);
                            // حساب إجمالي القيمة بناءً على الكمية المتاحة فقط
                            $totalPrice = $unitPrice * $availableQuantity;
                        ?>
                        <div class="product-card">
                            <div class="product-status">
                                <i class="bi bi-building me-1"></i>مصنع
                            </div>

                            <div class="product-name"><?php echo $productName; ?></div>
                            <?php if ($batchNumber && $batchNumber !== '—'): ?>
                                <a href="#" class="product-batch-id"><?php echo htmlspecialchars($batchNumber); ?></a>
                            <?php else: ?>
                                <span class="product-batch-id">—</span>
                            <?php endif; ?>

                            <div class="product-barcode-box">
                                <?php if ($batchNumber && $batchNumber !== '—'): ?>
                                    <div class="product-barcode-container" data-batch="<?php echo htmlspecialchars($batchNumber); ?>">
                                        <svg class="barcode-svg" style="width: 100%; height: 50px;"></svg>
                                    </div>
                                    <div class="product-barcode-id"><?php echo htmlspecialchars($batchNumber); ?></div>
                                <?php else: ?>
                                    <div class="product-barcode-id" style="color: #999;">لا يوجد باركود</div>
                                <?php endif; ?>
                            </div>

                            <div class="product-detail-row"><span>الفئة:</span> <span><?php echo $category; ?></span></div>
                            <div class="product-detail-row"><span>تاريخ الإنتاج:</span> <span><?php echo $productionDate; ?></span></div>
                            <div class="product-detail-row"><span>الكمية:</span> <span><strong><?php echo $quantity; ?></strong></span></div>
                            <div class="product-detail-row"><span>سعر الوحدة:</span> <span><?php echo formatCurrency($unitPrice); ?></span></div>
                            <div class="product-detail-row"><span>إجمالي القيمة:</span> <span><strong class="text-success"><?php echo formatCurrency($totalPrice); ?></strong></span></div>

                            <?php if ($batchNumber && $batchNumber !== '—'): ?>
                                <?php
                                    $viewUrl = getRelativeUrl('production.php?page=batch_numbers&batch_number=' . urlencode($batchNumber));
                                    $batchId = $product['id'] ?? 0;
                                ?>
                                <div class="product-actions" style="display: flex; gap: 10px; margin-top: 15px; flex-wrap: wrap;">
                                    <button type="button" 
                                            class="btn-view js-batch-details" 
                                            style="border: none; cursor: pointer; flex: 1; min-width: calc(50% - 5px); background: #0c2c80; color: white; padding: 10px 16px; border-radius: 10px; font-weight: bold; font-size: 13px;"
                                            data-batch="<?php echo htmlspecialchars($batchNumber); ?>"
                                            data-product="<?php echo htmlspecialchars($productName); ?>"
                                            data-view-url="<?php echo htmlspecialchars($viewUrl); ?>">
                                        <i class="bi bi-eye me-1"></i>عرض التفاصيل
                                    </button>
                                    <button type="button" 
                                            class="btn-view js-print-barcode" 
                                            style="border: none; cursor: pointer; flex: 1; min-width: calc(50% - 5px); background: #28a745; color: white; padding: 10px 16px; border-radius: 10px; font-weight: bold; font-size: 13px;"
                                            data-batch="<?php echo htmlspecialchars($batchNumber); ?>"
                                            data-product="<?php echo htmlspecialchars($productName); ?>"
                                            data-quantity="<?php echo htmlspecialchars($quantity); ?>">
                                        <i class="bi bi-printer me-1"></i>طباعة الباركود
                                    </button>
                                    <?php if (!$isProductionRole): ?>
                                    <button type="button" 
                                            class="btn-view js-edit-factory-category" 
                                            style="border: none; cursor: pointer; flex: 1; min-width: calc(50% - 5px); background: #ffc107; color: #000; padding: 10px 16px; border-radius: 10px; font-weight: bold; font-size: 13px;"
                                            data-batch-id="<?php echo $batchId; ?>"
                                            data-product="<?php echo htmlspecialchars($productName); ?>"
                                            data-category="<?php echo htmlspecialchars($category); ?>">
                                        <i class="bi bi-pencil me-1"></i>تعديل الصنف
                                    </button>
                                    <button type="button" 
                                            class="btn-view js-edit-factory-price" 
                                            style="border: none; cursor: pointer; flex: 1; min-width: calc(50% - 5px); background: #17a2b8; color: #fff; padding: 10px 16px; border-radius: 10px; font-weight: bold; font-size: 13px;"
                                            data-batch-id="<?php echo $batchId; ?>"
                                            data-product="<?php echo htmlspecialchars($productName); ?>"
                                            data-unit-price="<?php echo htmlspecialchars($unitPrice); ?>">
                                        <i class="bi bi-currency-dollar me-1"></i>تعديل السعر
                                    </button>
                                    <?php endif; ?>
                                    <button type="button" 
                                            class="btn-view js-add-quantity-factory" 
                                            style="border: none; cursor: pointer; flex: 1; min-width: calc(50% - 5px); background: #6f42c1; color: #fff; padding: 10px 16px; border-radius: 10px; font-weight: bold; font-size: 13px;"
                                            data-batch-id="<?php echo $batchId; ?>"
                                            data-product="<?php echo htmlspecialchars($productName); ?>"
                                            data-quantity="<?php echo htmlspecialchars($availableQuantity); ?>">
                                        <i class="bi bi-plus-circle me-1"></i>إضافة كمية
                                    </button>
                                </div>
                            <?php else: ?>
                                <span class="btn-view" style="opacity: 0.5; cursor: not-allowed; display: inline-block; margin-top: 15px; background: #0c2c80; color: white; padding: 10px 16px; border-radius: 10px; font-weight: bold; font-size: 13px;">
                                    <i class="bi bi-eye me-1"></i>عرض التفاصيل
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
                
                <!-- توليد الباركودات -->
                <script>
                (function() {
                    var maxRetries = 50;
                    var retryCount = 0;
                    
                    function generateAllBarcodes() {
                        if (typeof JsBarcode === 'undefined') {
                            retryCount++;
                            if (retryCount < maxRetries) {
                                setTimeout(generateAllBarcodes, 100);
                            } else {
                                console.error('JsBarcode library failed to load');
                                document.querySelectorAll('.product-barcode-container[data-batch]').forEach(function(container) {
                                    var batchNumber = container.getAttribute('data-batch');
                                    var svg = container.querySelector('svg');
                                    if (svg) {
                                        svg.innerHTML = '<text x="50%" y="50%" text-anchor="middle" font-size="14" fill="#666" font-family="Arial">' + batchNumber + '</text>';
                                    }
                                });
                            }
                            return;
                        }
                        
                        var containers = document.querySelectorAll('.product-barcode-container[data-batch]');
                        if (containers.length === 0) {
                            return;
                        }
                        
                        containers.forEach(function(container) {
                            var batchNumber = container.getAttribute('data-batch');
                            var svg = container.querySelector('svg.barcode-svg');
                            
                            if (svg && batchNumber && batchNumber.trim() !== '') {
                                try {
                                    svg.innerHTML = '';
                                    JsBarcode(svg, batchNumber, {
                                        format: "CODE128",
                                        width: 2,
                                        height: 50,
                                        displayValue: false,
                                        margin: 5,
                                        background: "#ffffff",
                                        lineColor: "#000000"
                                    });
                                } catch (error) {
                                    console.error('Error generating barcode for ' + batchNumber + ':', error);
                                    svg.innerHTML = '<text x="50%" y="50%" text-anchor="middle" font-size="12" fill="#666" font-family="Arial">' + batchNumber + '</text>';
                                }
                            }
                        });
                    }
                    
                    if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', function() {
                            setTimeout(generateAllBarcodes, 200);
                        });
                    } else {
                        setTimeout(generateAllBarcodes, 200);
                    }
                })();
                </script>
            <?php endif; ?>
        </div>
    </div>

    <!-- قسم المنتجات الخارجية -->
    <div class="card company-card" id="externalProductsSection">
        <div class="section-header">
            <h5>
                <i class="bi bi-cart4"></i>
                المنتجات الخارجية
            </h5>
            <div class="d-flex gap-2 align-items-center">
                <span class="badge" id="externalProductsCount"><?php echo $totalExternalProducts; ?> منتج</span>
                <button type="button" class="btn btn-success-custom btn-sm" onclick="showAddExternalProductModal()">
                    <i class="bi bi-plus-circle me-1"></i>إضافة منتج خارجي
                </button>
            </div>
        </div>
        <div class="card-body">
            <!-- شريط البحث والفلترة للمنتجات الخارجية -->
            <div class="mb-3 p-3 bg-light rounded" style="border: 1px solid #dee2e6;">
                <div class="row g-3">
                    <div class="col-6 col-md-6">
                        <label class="form-label small mb-1"><i class="bi bi-search me-1"></i>البحث</label>
                        <input type="text" 
                               class="form-control form-control-sm" 
                               id="externalSearchInput" 
                               placeholder="اسم المنتج..." 
                               autocomplete="off">
                    </div>
                    <div class="col-6 col-md-6">
                        <label class="form-label small mb-1"><i class="bi bi-folder me-1"></i>الصنف</label>
                        <select class="form-control form-control-sm" id="externalCategoryFilter">
                            <option value="">جميع الأصناف</option>
                            <?php if (!empty($productCategories)): ?>
                                <?php foreach ($productCategories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat['name']); ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="عسل">عسل</option>
                                <option value="زيت زيتون">زيت زيتون</option>
                                <option value="كريمات">كريمات</option>
                                <option value="زيوت">زيوت</option>
                                <option value="اخري">اخري</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-6">
                        <label class="form-label small mb-1"><i class="bi bi-currency-dollar me-1"></i>سعر من</label>
                        <input type="number" 
                               class="form-control form-control-sm" 
                               id="externalMinPrice" 
                               placeholder="من" 
                               step="0.01" 
                               min="0">
                    </div>
                    <div class="col-6 col-md-6">
                        <label class="form-label small mb-1"><i class="bi bi-currency-dollar me-1"></i>سعر إلى</label>
                        <input type="number" 
                               class="form-control form-control-sm" 
                               id="externalMaxPrice" 
                               placeholder="إلى" 
                               step="0.01" 
                               min="0">
                    </div>
                    <div class="col-6 col-md-6">
                        <label class="form-label small mb-1"><i class="bi bi-box me-1"></i>كمية من</label>
                        <input type="number" 
                               class="form-control form-control-sm" 
                               id="externalMinQuantity" 
                               placeholder="من" 
                               step="0.01" 
                               min="0">
                    </div>
                    <div class="col-6 col-md-6">
                        <label class="form-label small mb-1"><i class="bi bi-box me-1"></i>كمية إلى</label>
                        <input type="number" 
                               class="form-control form-control-sm" 
                               id="externalMaxQuantity" 
                               placeholder="إلى" 
                               step="0.01" 
                               min="0">
                    </div>
                </div>
            </div>
            
            <div id="externalProductsStats" style="<?php echo empty($externalProducts) ? 'display:none;' : ''; ?>">
                <div class="total-value-box">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold">القيمة الإجمالية للمنتجات الخارجية:</span>
                        <span class="text-success fw-bold" id="externalTotalValue"><?php echo formatCurrency($totalExternalValue); ?></span>
                    </div>
                </div>
            </div>
            
            <div id="externalProductsContainer">
            <?php if (empty($externalProducts)): ?>
                <div style="padding: 25px;">
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        لا توجد منتجات خارجية. قم بإضافة منتج جديد باستخدام الزر أعلاه.
                    </div>
                </div>
            <?php else: ?>
                <div class="products-grid" id="externalProductsGrid">
                    <?php foreach ($externalProducts as $product): ?>
                        <?php
                            $productName = htmlspecialchars($product['name'] ?? 'غير محدد');
                            $quantity = number_format((float)($product['quantity'] ?? 0), 2);
                            $unit = htmlspecialchars($product['unit'] ?? 'قطعة');
                            $unitPrice = floatval($product['unit_price'] ?? 0);
                            $totalValue = floatval($product['total_value'] ?? 0);
                        ?>
                        <div class="product-card">
                            <div class="product-status" style="background: #10b981;">
                                <i class="bi bi-cart4 me-1"></i>خارجي
                            </div>

                            <div class="product-name"><?php echo $productName; ?></div>
                            <div style="color: #94a3b8; font-size: 13px; margin-bottom: 10px;">منتج خارجي</div>

                            <?php $extCategory = htmlspecialchars($product['category'] ?? '—'); ?>
                            <div class="product-detail-row"><span>الصنف:</span> <span><?php echo $extCategory; ?></span></div>
                            <div class="product-detail-row"><span>الكمية:</span> <span><strong><?php echo $quantity; ?> <?php echo $unit; ?></strong></span></div>
                            <div class="product-detail-row"><span>سعر الوحدة:</span> <span><?php echo formatCurrency($unitPrice); ?></span></div>
                            <div class="product-detail-row"><span>الإجمالي:</span> <span><strong class="text-success"><?php echo formatCurrency($totalValue); ?></strong></span></div>
                            
                            <div class="product-actions" style="display: flex; gap: 10px; margin-top: 15px; flex-wrap: wrap;">
                            <?php if ($currentUser['role'] !== 'accountant' && !$isProductionRole): ?>
                                <button type="button" 
                                        class="btn btn-outline-primary js-edit-external" 
                                        style="flex: 1; min-width: calc(50% - 5px); border-radius: 10px; padding: 10px 16px; font-weight: bold; font-size: 13px;"
                                        data-id="<?php echo $product['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>"
                                        data-quantity="<?php echo $product['quantity']; ?>"
                                        data-unit="<?php echo htmlspecialchars($product['unit'] ?? 'قطعة', ENT_QUOTES); ?>"
                                        data-price="<?php echo $product['unit_price']; ?>"
                                        data-category="<?php echo htmlspecialchars($product['category'] ?? '', ENT_QUOTES); ?>">
                                    <i class="bi bi-pencil me-1"></i>تعديل
                                </button>
                                <button type="button" 
                                        class="btn btn-outline-danger js-delete-external" 
                                        style="flex: 1; min-width: calc(50% - 5px); border-radius: 10px; padding: 10px 16px; font-weight: bold; font-size: 13px;"
                                        data-id="<?php echo $product['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>">
                                    <i class="bi bi-trash me-1"></i>حذف
                                </button>
                            <?php endif; ?>
                                <button type="button" 
                                        class="btn btn-outline-secondary js-add-quantity-external" 
                                        style="flex: 1; min-width: calc(50% - 5px); border-radius: 10px; padding: 10px 16px; font-weight: bold; font-size: 13px;"
                                        data-product-id="<?php echo $product['id']; ?>"
                                        data-product-name="<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>"
                                        data-quantity="<?php echo $product['quantity']; ?>">
                                    <i class="bi bi-plus-circle me-1"></i>إضافة كمية
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Modal إضافة منتج خارجي -->
<!-- Modal للكمبيوتر فقط -->
<div class="modal fade d-none d-md-block" id="addExternalProductModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>إضافة منتج خارجي جديد</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="addExternalProductForm">
                <input type="hidden" name="action" value="create_external_product">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم المنتج <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="product_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الصنف <span class="text-danger">*</span></label>
                        <select class="form-control" name="category_id" id="add_category_id" required>
                            <option value="">اختر الصنف</option>
                            <?php if (!empty($productCategories)): ?>
                                <?php foreach ($productCategories as $cat): ?>
                                    <option value="<?php echo intval($cat['id']); ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="1">عسل</option>
                                <option value="2">زيت زيتون</option>
                                <option value="3">كريمات</option>
                                <option value="4">زيوت</option>
                                <option value="5">اخري</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="mb-3" id="add_custom_category_div" style="display: none;">
                        <label class="form-label">أدخل الصنف يدوياً <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="custom_category" id="add_custom_category" placeholder="أدخل اسم الصنف">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">الكمية</label>
                            <input type="number" step="0.01" class="form-control" name="quantity" value="0" min="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">الوحدة</label>
                            <input type="text" class="form-control" name="unit" value="قطعة">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">سعر الوحدة</label>
                        <input type="number" step="0.01" class="form-control" name="unit_price" value="0" min="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary-custom">حفظ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تعديل منتج خارجي -->
<!-- Modal للكمبيوتر فقط -->
<div class="modal fade d-none d-md-block" id="editExternalProductModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>تعديل منتج خارجي</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editExternalProductForm">
                <input type="hidden" name="action" value="update_external_product">
                <input type="hidden" name="product_id" id="edit_product_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم المنتج <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="product_name" id="edit_product_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الصنف <span class="text-danger">*</span></label>
                        <select class="form-control" name="category_id" id="edit_category_id" required>
                            <option value="">اختر الصنف</option>
                            <?php if (!empty($productCategories)): ?>
                                <?php foreach ($productCategories as $cat): ?>
                                    <option value="<?php echo intval($cat['id']); ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="1">عسل</option>
                                <option value="2">زيت زيتون</option>
                                <option value="3">كريمات</option>
                                <option value="4">زيوت</option>
                                <option value="5">اخري</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="mb-3" id="edit_custom_category_div" style="display: none;">
                        <label class="form-label">أدخل الصنف يدوياً <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="custom_category" id="edit_custom_category" placeholder="أدخل اسم الصنف">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">الكمية</label>
                            <input type="number" step="0.01" class="form-control" name="quantity" id="edit_quantity" min="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">الوحدة</label>
                            <input type="text" class="form-control" name="unit" id="edit_unit">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">سعر الوحدة</label>
                        <input type="number" step="0.01" class="form-control" name="unit_price" id="edit_unit_price" min="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary-custom">حفظ التغييرات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تعديل صنف منتج المصنع -->
<!-- Modal للكمبيوتر فقط -->
<div class="modal fade d-none d-md-block" id="editFactoryProductCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>تعديل صنف منتج المصنع</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editFactoryCategoryForm">
                <input type="hidden" name="action" value="update_factory_product_category">
                <input type="hidden" name="batch_id" id="edit_factory_batch_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم المنتج</label>
                        <input type="text" class="form-control" id="edit_factory_product_name" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الصنف <span class="text-danger">*</span></label>
                        <select class="form-control" name="category_id" id="edit_factory_category_id" required>
                            <option value="">اختر الصنف</option>
                            <?php if (!empty($productCategories)): ?>
                                <?php foreach ($productCategories as $cat): ?>
                                    <option value="<?php echo intval($cat['id']); ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="1">عسل</option>
                                <option value="2">زيت زيتون</option>
                                <option value="4">زيوت</option>
                                <option value="5">اخري</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="mb-3" id="edit_factory_custom_category_div" style="display: none;">
                        <label class="form-label">أدخل الصنف يدوياً <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="custom_category" id="edit_factory_custom_category" placeholder="أدخل اسم الصنف">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-warning">حفظ التغييرات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تعديل سعر منتج المصنع (للمدير والمحاسب) -->
<div class="modal fade d-none d-md-block" id="editFactoryProductPriceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="bi bi-currency-dollar me-2"></i>تعديل سعر منتج المصنع</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editFactoryPriceForm">
                <input type="hidden" name="action" value="update_factory_product_price">
                <input type="hidden" name="batch_id" id="edit_factory_price_batch_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم المنتج</label>
                        <input type="text" class="form-control" id="edit_factory_price_product_name" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">سعر الوحدة <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0" class="form-control" name="unit_price" id="edit_factory_price_unit_price" required placeholder="أدخل سعر الوحدة">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-info text-white">حفظ السعر</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal إضافة كمية لمنتج مصنع -->
<div class="modal fade d-none d-md-block" id="addQuantityFactoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-secondary text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>إضافة كمية لمنتج المصنع</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="addQuantityFactoryForm">
                <input type="hidden" name="action" value="add_quantity_factory_product">
                <input type="hidden" name="batch_id" id="add_quantity_factory_batch_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">المنتج</label>
                        <input type="text" class="form-control" id="add_quantity_factory_product_name" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الكمية الحالية</label>
                        <input type="text" class="form-control" id="add_quantity_factory_current_qty" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الكمية المضافة <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0.01" class="form-control" name="quantity_to_add" id="add_quantity_factory_to_add" required placeholder="أدخل الكمية">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-secondary text-white">إضافة</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal إضافة كمية لمنتج خارجي -->
<div class="modal fade d-none d-md-block" id="addQuantityExternalModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-secondary text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>إضافة كمية للمنتج الخارجي</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="addQuantityExternalForm">
                <input type="hidden" name="action" value="add_quantity_external_product">
                <input type="hidden" name="product_id" id="add_quantity_external_product_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">المنتج</label>
                        <input type="text" class="form-control" id="add_quantity_external_product_name" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الكمية الحالية</label>
                        <input type="text" class="form-control" id="add_quantity_external_current_qty" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الكمية المضافة <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0.01" class="form-control" name="quantity_to_add" id="add_quantity_external_to_add" required placeholder="أدخل الكمية">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-secondary text-white">إضافة</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal حذف منتج خارجي -->
<!-- Modal للكمبيوتر فقط -->
<div class="modal fade d-none d-md-block" id="deleteExternalProductModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>تأكيد الحذف</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="delete_external_product">
                <input type="hidden" name="product_id" id="delete_product_id">
                <div class="modal-body">
                    <p>هل أنت متأكد من حذف المنتج <strong id="delete_product_name"></strong>؟</p>
                    <p class="text-danger mb-0"><small>لا يمكن التراجع عن هذه العملية.</small></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-danger">حذف</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal طباعة الباركود -->
<!-- Modal للكمبيوتر فقط -->
<div class="modal fade d-none d-md-block" id="printBarcodesModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-printer me-2"></i>طباعة الباركود</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-success">
                    <i class="bi bi-check-circle me-2"></i>
                    جاهز للطباعة
                </div>
                <div class="mb-3">
                    <label class="form-label">اسم المنتج</label>
                    <input type="text" class="form-control" id="barcode_product_name" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label">عدد الباركودات المراد طباعتها</label>
                    <input type="number" class="form-control" id="barcode_print_quantity" min="1" value="1">
                    <small class="text-muted">سيتم طباعة نفس رقم التشغيلة بعدد المرات المحدد</small>
                </div>
                <div class="mb-3">
                    <label class="form-label">أرقام التشغيلة</label>
                    <div class="border rounded p-3" style="max-height: 200px; overflow-y: auto;">
                        <div id="batch_numbers_list"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                <button type="button" class="btn btn-primary" onclick="printBarcodes()">
                    <i class="bi bi-printer me-2"></i>طباعة
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Card طباعة الباركود - للموبايل فقط -->
<div class="card shadow-sm mb-4 d-md-none" id="printBarcodesCard" style="display: none;">
    <div class="card-header bg-success text-white">
        <h5 class="mb-0">
            <i class="bi bi-printer me-2"></i>طباعة الباركود
        </h5>
    </div>
    <div class="card-body">
        <div class="alert alert-success">
            <i class="bi bi-check-circle me-2"></i>
            جاهز للطباعة
        </div>
        <div class="mb-3">
            <label class="form-label">اسم المنتج</label>
            <input type="text" class="form-control" id="barcodeCardProductName" readonly>
        </div>
        <div class="mb-3">
            <label class="form-label">عدد الباركودات المراد طباعتها</label>
            <input type="number" class="form-control" id="barcodeCardPrintQuantity" min="1" value="1">
            <small class="text-muted">سيتم طباعة نفس رقم التشغيلة بعدد المرات المحدد</small>
        </div>
        <div class="mb-3">
            <label class="form-label">أرقام التشغيلة</label>
            <div class="border rounded p-3" style="max-height: 200px; overflow-y: auto;">
                <div id="batchNumbersListCard"></div>
            </div>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-primary" onclick="printBarcodesFromCard()">
                <i class="bi bi-printer me-2"></i>طباعة
            </button>
            <button type="button" class="btn btn-secondary" onclick="closePrintBarcodesCard()">إغلاق</button>
        </div>
    </div>
</div>

<!-- Card للموبايل - إضافة منتج خارجي -->
<div class="card shadow-sm mb-4 d-md-none" id="addExternalProductCard" style="display: none;">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>إضافة منتج خارجي جديد</h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="create_external_product">
            <div class="mb-3">
                <label class="form-label">اسم المنتج <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="product_name" required>
            </div>
            <div class="mb-3">
                <label class="form-label">الصنف <span class="text-danger">*</span></label>
                <select class="form-control" name="category_id" id="addCard_category_id" required>
                    <option value="">اختر الصنف</option>
                    <?php if (!empty($productCategories)): ?>
                        <?php foreach ($productCategories as $cat): ?>
                            <option value="<?php echo intval($cat['id']); ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="1">عسل</option>
                        <option value="2">زيت زيتون</option>
                        <option value="3">كريمات</option>
                        <option value="4">زيوت</option>
                        <option value="5">اخري</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="mb-3" id="addCard_custom_category_div" style="display: none;">
                <label class="form-label">أدخل الصنف يدوياً <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="custom_category" id="addCard_custom_category" placeholder="أدخل اسم الصنف">
            </div>
            <div class="row">
                <div class="col-6 mb-3">
                    <label class="form-label">الكمية</label>
                    <input type="number" step="0.01" class="form-control" name="quantity" value="0" min="0">
                </div>
                <div class="col-6 mb-3">
                    <label class="form-label">الوحدة</label>
                    <input type="text" class="form-control" name="unit" value="قطعة">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">سعر الوحدة</label>
                <input type="number" step="0.01" class="form-control" name="unit_price" value="0" min="0">
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary-custom">حفظ</button>
                <button type="button" class="btn btn-secondary" onclick="closeAddExternalProductCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- Card للموبايل - تعديل منتج خارجي -->
<div class="card shadow-sm mb-4 d-md-none" id="editExternalProductCard" style="display: none;">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="bi bi-pencil me-2"></i>تعديل منتج خارجي</h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="update_external_product">
            <input type="hidden" name="product_id" id="editCard_product_id">
            <div class="mb-3">
                <label class="form-label">اسم المنتج <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="product_name" id="editCard_product_name" required>
            </div>
            <div class="mb-3">
                <label class="form-label">الصنف <span class="text-danger">*</span></label>
                <select class="form-control" name="category_id" id="editCard_category_id" required>
                    <option value="">اختر الصنف</option>
                    <?php if (!empty($productCategories)): ?>
                        <?php foreach ($productCategories as $cat): ?>
                            <option value="<?php echo intval($cat['id']); ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="1">عسل</option>
                        <option value="2">زيت زيتون</option>
                        <option value="3">كريمات</option>
                        <option value="4">زيوت</option>
                        <option value="5">اخري</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="mb-3" id="editCard_custom_category_div" style="display: none;">
                <label class="form-label">أدخل الصنف يدوياً <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="custom_category" id="editCard_custom_category" placeholder="أدخل اسم الصنف">
            </div>
            <div class="row">
                <div class="col-6 mb-3">
                    <label class="form-label">الكمية</label>
                    <input type="number" step="0.01" class="form-control" name="quantity" id="editCard_quantity" min="0">
                </div>
                <div class="col-6 mb-3">
                    <label class="form-label">الوحدة</label>
                    <input type="text" class="form-control" name="unit" id="editCard_unit">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">سعر الوحدة</label>
                <input type="number" step="0.01" class="form-control" name="unit_price" id="editCard_unit_price" min="0">
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary-custom">حفظ التغييرات</button>
                <button type="button" class="btn btn-secondary" onclick="closeEditExternalProductCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- Card للموبايل - حذف منتج خارجي -->
<div class="card shadow-sm mb-4 d-md-none" id="deleteExternalProductCard" style="display: none;">
    <div class="card-header bg-danger text-white">
        <h5 class="mb-0"><i class="bi bi-exclamation-triangle me-2"></i>تأكيد الحذف</h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="delete_external_product">
            <input type="hidden" name="product_id" id="deleteCard_product_id">
            <p>هل أنت متأكد من حذف المنتج <strong id="deleteCard_product_name"></strong>؟</p>
            <p class="text-danger mb-3"><small>لا يمكن التراجع عن هذه العملية.</small></p>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-danger">حذف</button>
                <button type="button" class="btn btn-secondary" onclick="closeDeleteExternalProductCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal تفاصيل التشغيلة -->
<div class="modal fade d-none d-md-block" id="batchDetailsModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تفاصيل التشغيلة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div id="batchDetailsLoading" class="d-flex justify-content-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">جارٍ التحميل...</span>
                    </div>
                </div>
                <div id="batchDetailsError" class="alert alert-danger d-none" role="alert"></div>
                <div id="batchDetailsContent" class="d-none">
                    <div id="batchSummarySection" class="mb-4"></div>
                    <div id="batchMaterialsSection" class="mb-4"></div>
                    <div id="batchRawMaterialsSection" class="mb-4"></div>
                    <div id="batchWorkersSection" class="mb-0"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<!-- Card تفاصيل التشغيلة - للموبايل فقط -->
<div class="card shadow-sm mb-4 d-md-none" id="batchDetailsCard" style="display: none;">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">
            <i class="bi bi-info-circle me-2"></i>تفاصيل التشغيلة
        </h5>
    </div>
    <div class="card-body">
        <div id="batchDetailsCardLoading" class="d-flex justify-content-center py-4">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">جارٍ التحميل...</span>
            </div>
        </div>
        <div id="batchDetailsCardError" class="alert alert-danger d-none" role="alert"></div>
        <div id="batchDetailsCardContent" class="d-none">
            <div id="batchDetailsCardSummarySection" class="mb-4"></div>
            <div id="batchDetailsCardMaterialsSection" class="mb-4"></div>
            <div id="batchDetailsCardRawMaterialsSection" class="mb-4"></div>
            <div id="batchDetailsCardWorkersSection" class="mb-0"></div>
        </div>
        <div class="d-flex gap-2 mt-3">
            <button type="button" class="btn btn-secondary" onclick="closeBatchDetailsCard()">إغلاق</button>
        </div>
    </div>
</div>

<script>
// ===== دوال أساسية =====

function isMobile() {
    return window.innerWidth <= 768;
}

function scrollToElement(element) {
    if (!element) return;
    
    setTimeout(function() {
        const rect = element.getBoundingClientRect();
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const elementTop = rect.top + scrollTop;
        const offset = 80;
        
        requestAnimationFrame(function() {
            window.scrollTo({
                top: Math.max(0, elementTop - offset),
                behavior: 'smooth'
            });
        });
    }, 200);
}

function closeAllForms() {
    const cards = [
        'addExternalProductCard', 
        'editExternalProductCard', 
        'deleteExternalProductCard',
        'batchDetailsCard',
        'printBarcodesCard'
    ];
    cards.forEach(function(cardId) {
        const card = document.getElementById(cardId);
        if (card && card.style.display !== 'none') {
            card.style.display = 'none';
            const form = card.querySelector('form');
            if (form) form.reset();
        }
    });
    
    const modals = [
        'addExternalProductModal', 
        'editExternalProductModal', 
        'deleteExternalProductModal',
        'batchDetailsModal',
        'printBarcodesModal',
        'editFactoryProductCategoryModal',
        'editFactoryProductPriceModal',
        'addQuantityFactoryModal',
        'addQuantityExternalModal'
    ];
    modals.forEach(function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            const modalInstance = bootstrap.Modal.getInstance(modal);
            if (modalInstance) modalInstance.hide();
        }
    });
}

// ===== دوال فتح النماذج =====

function showAddExternalProductModal() {
    closeAllForms();
    
    if (isMobile()) {
        const card = document.getElementById('addExternalProductCard');
        if (card) {
            card.style.display = 'block';
            setTimeout(function() {
                scrollToElement(card);
            }, 50);
        }
    } else {
        const modal = document.getElementById('addExternalProductModal');
        if (modal) {
            const modalInstance = new bootstrap.Modal(modal);
            modalInstance.show();
        }
    }
}

// ===== دوال إغلاق Cards =====

function closeAddExternalProductCard() {
    const card = document.getElementById('addExternalProductCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}

function closeEditExternalProductCard() {
    const card = document.getElementById('editExternalProductCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}

function closeBatchDetailsCard() {
    const card = document.getElementById('batchDetailsCard');
    if (card) {
        card.style.display = 'none';
    }
}

function closePrintBarcodesCard() {
    const card = document.getElementById('printBarcodesCard');
    if (card) {
        card.style.display = 'none';
    }
}

function printBarcodesFromCard() {
    const batchNumbers = window.batchNumbersToPrint || [];
    if (batchNumbers.length === 0) {
        alert('لا توجد أرقام تشغيلة للطباعة');
        return;
    }

    const quantityInput = document.getElementById('barcodeCardPrintQuantity');
    const printQuantity = quantityInput ? parseInt(quantityInput.value, 10) : 1;
    
    if (!printQuantity || printQuantity < 1) {
        alert('يرجى إدخال عدد صحيح للطباعة');
        return;
    }

    const batchNumber = batchNumbers[0];
    const printUrl = `${PRINT_BARCODE_URL}?batch=${encodeURIComponent(batchNumber)}&quantity=${printQuantity}&print=1`;
    window.open(printUrl, '_blank');
}

function closeDeleteExternalProductCard() {
    const card = document.getElementById('deleteExternalProductCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}

// ===== دوال موجودة - تعديلها لدعم الموبايل =====

// تهيئة المتغيرات والدوال
const PRINT_BARCODE_URL = <?php echo json_encode(getRelativeUrl('print_barcode.php')); ?>;
if (typeof window !== 'undefined') {
    window.PRINT_BARCODE_URL = PRINT_BARCODE_URL;
}

function showBarcodePrintModal(batchNumber, productName, defaultQuantity) {
    if (typeof closeAllForms === 'function') {
        closeAllForms();
    }
    
    const quantity = defaultQuantity > 0 ? defaultQuantity : 1;
    window.batchNumbersToPrint = [batchNumber];
    
    const isMobileDevice = isMobile();
    
    if (isMobileDevice) {
        // على الموبايل: استخدام Card فقط
        const card = document.getElementById('printBarcodesCard');
        if (!card) {
            console.error('printBarcodesCard not found');
            const fallbackUrl = `${PRINT_BARCODE_URL}?batch=${encodeURIComponent(batchNumber)}&quantity=${quantity}&print=1`;
            window.open(fallbackUrl, '_blank');
            return;
        }
        
        const productNameInput = document.getElementById('barcodeCardProductName');
        if (productNameInput) {
            productNameInput.value = productName || '';
        }
        
        const quantityInput = document.getElementById('barcodeCardPrintQuantity');
        if (quantityInput) {
            quantityInput.value = quantity;
        }
        
        const batchListContainer = document.getElementById('batchNumbersListCard');
        if (batchListContainer) {
            batchListContainer.innerHTML = `
                <div class="alert alert-info mb-0">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>رقم التشغيلة:</strong> ${batchNumber}<br>
                    <small>ستتم طباعة نفس رقم التشغيلة بعدد ${quantity} باركود</small>
                </div>
            `;
        }
        
        card.style.display = 'block';
        setTimeout(function() {
            scrollToElement(card);
        }, 50);
    } else {
        // على الكمبيوتر: استخدام Modal
        const modalElement = document.getElementById('printBarcodesModal');
        if (!modalElement) {
            console.error('Modal printBarcodesModal not found');
            const fallbackUrl = `${PRINT_BARCODE_URL}?batch=${encodeURIComponent(batchNumber)}&quantity=${quantity}&print=1`;
            window.open(fallbackUrl, '_blank');
            return;
        }

        const productNameInput = document.getElementById('barcode_product_name');
        if (productNameInput) {
            productNameInput.value = productName || '';
        }

        const quantityInput = document.getElementById('barcode_print_quantity');
        if (quantityInput) {
            quantityInput.value = quantity;
        }

        const batchListContainer = document.getElementById('batch_numbers_list');
        if (batchListContainer) {
            batchListContainer.innerHTML = `
                <div class="alert alert-info mb-0">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>رقم التشغيلة:</strong> ${batchNumber}<br>
                    <small>ستتم طباعة نفس رقم التشغيلة بعدد ${quantity} باركود</small>
                </div>
            `;
        }

        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
            modal.show();
        } else {
            const fallbackUrl = `${PRINT_BARCODE_URL}?batch=${encodeURIComponent(batchNumber)}&quantity=${quantity}&print=1`;
            window.open(fallbackUrl, '_blank');
        }
    }
}

function printBarcodes() {
    const batchNumbers = window.batchNumbersToPrint || [];
    if (batchNumbers.length === 0) {
        alert('لا توجد أرقام تشغيلة للطباعة');
        return;
    }

    const quantityInput = document.getElementById('barcode_print_quantity');
    const printQuantity = quantityInput ? parseInt(quantityInput.value, 10) : 1;
    
    if (!printQuantity || printQuantity < 1) {
        alert('يرجى إدخال عدد صحيح للطباعة');
        return;
    }

    const batchNumber = batchNumbers[0];
    const printUrl = `${PRINT_BARCODE_URL}?batch=${encodeURIComponent(batchNumber)}&quantity=${printQuantity}&print=1`;
    window.open(printUrl, '_blank');
}

window.showBarcodePrintModal = showBarcodePrintModal;
window.printBarcodes = printBarcodes;

// وظيفة عرض تفاصيل التشغيلة
const batchDetailsEndpoint = <?php echo json_encode(getRelativeUrl('api/production/get_batch_details.php')); ?>;
let batchDetailsIsLoading = false;

// تخزين مؤقت (Cache) لتفاصيل التشغيلات
const batchDetailsCache = new Map();
const CACHE_DURATION = 10 * 60 * 1000; // 10 دقائق بالملي ثانية
const MAX_CACHE_SIZE = 50; // أقصى عدد من التشغيلات في cache

/**
 * تنظيف cache من البيانات المنتهية الصلاحية
 */
function cleanBatchDetailsCache() {
    const now = Date.now();
    const keysToDelete = [];
    
    batchDetailsCache.forEach((value, key) => {
        if (now - value.timestamp > CACHE_DURATION) {
            keysToDelete.push(key);
        }
    });
    
    keysToDelete.forEach(key => batchDetailsCache.delete(key));
    
    // إذا كان حجم cache كبيراً، احذف أقدم العناصر
    if (batchDetailsCache.size > MAX_CACHE_SIZE) {
        const entries = Array.from(batchDetailsCache.entries());
        entries.sort((a, b) => a[1].timestamp - b[1].timestamp);
        
        const toRemove = entries.slice(0, entries.length - MAX_CACHE_SIZE);
        toRemove.forEach(([key]) => batchDetailsCache.delete(key));
    }
}

/**
 * الحصول على تفاصيل التشغيلة من cache
 */
function getBatchDetailsFromCache(batchNumber) {
    cleanBatchDetailsCache();
    const cached = batchDetailsCache.get(batchNumber);
    
    if (cached) {
        const now = Date.now();
        if (now - cached.timestamp < CACHE_DURATION) {
            return cached.data;
        } else {
            // حذف البيانات المنتهية الصلاحية
            batchDetailsCache.delete(batchNumber);
        }
    }
    
    return null;
}

/**
 * حفظ تفاصيل التشغيلة في cache
 */
function setBatchDetailsInCache(batchNumber, data) {
    cleanBatchDetailsCache();
    batchDetailsCache.set(batchNumber, {
        data: data,
        timestamp: Date.now()
    });
}

function showBatchDetailsModal(batchNumber, productName, retryCount = 0) {
    if (!batchNumber || typeof batchNumber !== 'string' || batchNumber.trim() === '') {
        console.error('Invalid batch number');
        return;
    }
    
    if (batchDetailsIsLoading) {
        return;
    }
    
    // إغلاق جميع النماذج المفتوحة أولاً
    if (typeof closeAllForms === 'function') {
        closeAllForms();
    }
    
    const isMobileDevice = isMobile();
    
    if (isMobileDevice) {
        // على الموبايل: استخدام Card فقط
        const card = document.getElementById('batchDetailsCard');
        if (!card) {
            console.error('batchDetailsCard not found');
            return;
        }
        
        const loader = card.querySelector('#batchDetailsCardLoading');
        const errorAlert = card.querySelector('#batchDetailsCardError');
        const contentWrapper = card.querySelector('#batchDetailsCardContent');
        
        // التحقق من وجود البيانات في cache
        const cachedData = getBatchDetailsFromCache(batchNumber);
        if (cachedData) {
            if (loader) loader.classList.add('d-none');
            if (errorAlert) errorAlert.classList.add('d-none');
            renderBatchDetailsCard(cachedData);
            if (contentWrapper) contentWrapper.classList.remove('d-none');
            card.style.display = 'block';
            setTimeout(function() {
                scrollToElement(card);
            }, 50);
            return;
        }
        
        // إذا لم تكن البيانات موجودة في cache، جلبها من الخادم
        if (loader) loader.classList.remove('d-none');
        if (errorAlert) errorAlert.classList.add('d-none');
        if (contentWrapper) contentWrapper.classList.add('d-none');
        batchDetailsIsLoading = true;
        
        card.style.display = 'block';
        setTimeout(function() {
            scrollToElement(card);
        }, 50);
        
        // تحميل البيانات
        fetch(batchDetailsEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ batch_number: batchNumber })
        })
        .then(response => response.json())
        .then(data => {
            if (loader) loader.classList.add('d-none');
            batchDetailsIsLoading = false;
            
            if (data.success && data.batch) {
                setBatchDetailsInCache(batchNumber, data.batch);
                renderBatchDetailsCard(data.batch);
                if (contentWrapper) contentWrapper.classList.remove('d-none');
            } else {
                if (errorAlert) {
                    errorAlert.textContent = data.message || 'تعذر تحميل تفاصيل التشغيلة';
                    errorAlert.classList.remove('d-none');
                }
            }
        })
        .catch(error => {
            if (loader) loader.classList.add('d-none');
            if (errorAlert) {
                errorAlert.textContent = 'حدث خطأ أثناء تحميل التفاصيل';
                errorAlert.classList.remove('d-none');
            }
            batchDetailsIsLoading = false;
            console.error('Error loading batch details:', error);
        });
        return;
    }
    
    // على الكمبيوتر: استخدام Modal
    if (typeof bootstrap === 'undefined' || typeof bootstrap.Modal === 'undefined') {
        alert('تعذر فتح تفاصيل التشغيلة. يرجى تحديث الصفحة.');
        return;
    }
    
    const modalElement = document.getElementById('batchDetailsModal');
    if (!modalElement) {
        // محاولة مرة أخرى بعد فترة قصيرة إذا كان النموذج غير موجود
        if (retryCount < 3) {
            setTimeout(function() {
                showBatchDetailsModal(batchNumber, productName, retryCount + 1);
            }, 200);
            return;
        }
        console.error('batchDetailsModal not found after retries');
        return;
    }
    
    const modalInstance = bootstrap.Modal.getOrCreateInstance(modalElement);
    const loader = modalElement.querySelector('#batchDetailsLoading');
    const errorAlert = modalElement.querySelector('#batchDetailsError');
    const contentWrapper = modalElement.querySelector('#batchDetailsContent');
    const modalTitle = modalElement.querySelector('.modal-title');
    
    // التحقق من وجود العناصر المطلوبة
    // loader و contentWrapper ضروريان، errorAlert اختياري
    if (!loader || !contentWrapper) {
        // محاولة مرة أخرى بعد فترة قصيرة إذا كانت العناصر غير موجودة
        if (retryCount < 3) {
            setTimeout(function() {
                showBatchDetailsModal(batchNumber, productName, retryCount + 1);
            }, 200);
            return;
        }
        console.error('Required modal elements not found after retries', {
            loader: !!loader,
            errorAlert: !!errorAlert,
            contentWrapper: !!contentWrapper
        });
        alert('تعذر فتح تفاصيل التشغيلة. يرجى تحديث الصفحة.');
        return;
    }
    
    // إنشاء errorAlert ديناميكياً إذا لم يكن موجوداً
    if (!errorAlert) {
        const modalBody = modalElement.querySelector('.modal-body');
        if (modalBody) {
            const errorDivElement = document.createElement('div');
            errorDivElement.className = 'alert alert-danger d-none';
            errorDivElement.id = 'batchDetailsError';
            errorDivElement.setAttribute('role', 'alert');
            // إدراجه بعد loader
            if (loader.nextSibling) {
                modalBody.insertBefore(errorDivElement, loader.nextSibling);
            } else {
                modalBody.appendChild(errorDivElement);
            }
        }
    }
    
    const finalErrorAlert = modalElement.querySelector('#batchDetailsError');
    
    if (modalTitle) {
        modalTitle.textContent = productName ? `تفاصيل التشغيلة - ${productName}` : 'تفاصيل التشغيلة';
    }
    
    // التحقق من وجود البيانات في cache
    const cachedData = getBatchDetailsFromCache(batchNumber);
    if (cachedData) {
        // استخدام البيانات من cache مباشرة
        loader.classList.add('d-none');
        if (finalErrorAlert) finalErrorAlert.classList.add('d-none');
        renderBatchDetails(cachedData);
        if (contentWrapper) contentWrapper.classList.remove('d-none');
        modalInstance.show();
        return;
    }
    
    // إذا لم تكن البيانات موجودة في cache، جلبها من الخادم
    loader.classList.remove('d-none');
    if (finalErrorAlert) finalErrorAlert.classList.add('d-none');
    contentWrapper.classList.add('d-none');
    batchDetailsIsLoading = true;
    
    modalInstance.show();
    
    fetch(batchDetailsEndpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ batch_number: batchNumber })
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.json();
    })
    .then(data => {
        if (loader) loader.classList.add('d-none');
        batchDetailsIsLoading = false;
        
        const errorAlertEl = modalElement.querySelector('#batchDetailsError');
        
        if (data.success && data.batch) {
            // حفظ البيانات في cache
            setBatchDetailsInCache(batchNumber, data.batch);
            
            renderBatchDetails(data.batch);
            if (contentWrapper) contentWrapper.classList.remove('d-none');
            if (errorAlertEl) errorAlertEl.classList.add('d-none');
        } else {
            if (errorAlertEl) {
                errorAlertEl.textContent = data.message || 'تعذر تحميل تفاصيل التشغيلة';
                errorAlertEl.classList.remove('d-none');
            }
            if (contentWrapper) contentWrapper.classList.add('d-none');
        }
    })
    .catch(error => {
        if (loader) loader.classList.add('d-none');
        const errorAlertEl = modalElement.querySelector('#batchDetailsError');
        if (errorAlertEl) {
            errorAlertEl.textContent = 'حدث خطأ أثناء تحميل التفاصيل: ' + (error.message || 'خطأ غير معروف');
            errorAlertEl.classList.remove('d-none');
        }
        if (contentWrapper) contentWrapper.classList.add('d-none');
        batchDetailsIsLoading = false;
        console.error('Error loading batch details:', error);
    });
}

function renderBatchDetails(data) {
    const summarySection = document.getElementById('batchSummarySection');
    const materialsSection = document.getElementById('batchMaterialsSection');
    const rawMaterialsSection = document.getElementById('batchRawMaterialsSection');
    const workersSection = document.getElementById('batchWorkersSection');

    const batchNumber = data.batch_number ?? '—';
    const summaryRows = [
        ['رقم التشغيلة', batchNumber],
        ['المنتج', data.product_name ?? '—'],
        ['تاريخ الإنتاج', data.production_date ? data.production_date : '—'],
        ['الكمية المنتجة', data.quantity_produced ?? data.quantity ?? '—']
    ];

    if (data.honey_supplier_name) {
        summaryRows.push(['مورد العسل', data.honey_supplier_name]);
    }
    
    // عرض موردين أدوات التعبئة - دعم أكثر من مورد
    let packagingSuppliersDisplay = '—';
    if (data.packaging_suppliers_list && Array.isArray(data.packaging_suppliers_list) && data.packaging_suppliers_list.length > 0) {
        // استخدام قائمة الموردين من packaging_materials
        packagingSuppliersDisplay = data.packaging_suppliers_list.join('، ');
    } else if (data.packaging_supplier_name) {
        // استخدام المورد الافتراضي إذا لم تكن هناك قائمة
        packagingSuppliersDisplay = data.packaging_supplier_name;
    }
    
    if (packagingSuppliersDisplay !== '—') {
        summaryRows.push(['مورد أدوات التعبئة', packagingSuppliersDisplay]);
    }
    if (data.notes) {
        summaryRows.push(['ملاحظات', data.notes]);
    }

    summarySection.innerHTML = `
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>ملخص التشغيلة</h6>
            </div>
            <div class="card-body">
                <div class="table-responsive dashboard-table-wrapper">
                    <table class="table dashboard-table dashboard-table--compact align-middle mb-0">
                        <tbody>
                            ${summaryRows.map(([label, value]) => `
                                <tr>
                                    <th class="w-25">${label}</th>
                                    <td>${value}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    `;

    const packagingItems = Array.isArray(data.packaging_materials) 
        ? data.packaging_materials 
        : (Array.isArray(data.materials) ? data.materials : []);
    materialsSection.innerHTML = `
        <div class="card shadow-sm">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bi bi-box-seam me-2"></i>مواد التعبئة</h6>
            </div>
            <div class="card-body">
                ${packagingItems.length > 0 ? `
                    <div class="table-responsive dashboard-table-wrapper">
                        <table class="table dashboard-table dashboard-table--compact align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>المادة</th>
                                    <th>الكمية</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${packagingItems.map(item => {
                                    const materialName = item.name ?? item.material_name ?? '—';
                                    let quantityDisplay = '—';
                                    
                                    if (item.details) {
                                        // إذا كان الحقل details موجود (من materials)
                                        quantityDisplay = item.details;
                                    } else {
                                        // إذا كانت البيانات منفصلة (من packaging_materials)
                                        const quantity = item.quantity_used ?? item.quantity ?? null;
                                        const unit = item.unit ?? '';
                                        quantityDisplay = quantity !== null && quantity !== undefined 
                                            ? `${quantity} ${unit}`.trim() 
                                            : '—';
                                    }
                                    
                                    return `
                                    <tr>
                                        <td>${materialName}</td>
                                        <td>${quantityDisplay}</td>
                                    </tr>
                                `;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                ` : `
                    <div class="text-center text-muted py-3">
                        <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                        لا توجد مواد تعبئة مسجلة
                    </div>
                `}
            </div>
        </div>
    `;

    const rawMaterialsItems = Array.isArray(data.raw_materials) ? data.raw_materials : [];
    rawMaterialsSection.innerHTML = `
        <div class="card shadow-sm">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bi bi-flower1 me-2"></i>الخامات</h6>
            </div>
            <div class="card-body">
                ${rawMaterialsItems.length > 0 ? `
                    <div class="table-responsive dashboard-table-wrapper">
                        <table class="table dashboard-table dashboard-table--compact align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>المادة</th>
                                    <th>الكمية</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${rawMaterialsItems.map(item => {
                                    const materialName = item.name ?? item.material_name ?? '—';
                                    const quantity = item.quantity_used ?? item.quantity ?? null;
                                    const unit = item.unit ?? '';
                                    const quantityDisplay = quantity !== null && quantity !== undefined 
                                        ? `${quantity} ${unit}`.trim() 
                                        : '—';
                                    return `
                                    <tr>
                                        <td>${materialName}</td>
                                        <td>${quantityDisplay}</td>
                                    </tr>
                                `;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                ` : `
                    <div class="text-center text-muted py-3">
                        <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                        لا توجد خامات مسجلة
                    </div>
                `}
            </div>
        </div>
    `;

    const workers = Array.isArray(data.workers) ? data.workers : [];
    workersSection.innerHTML = `
        <div class="card shadow-sm">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bi bi-people me-2"></i>العمال</h6>
            </div>
            <div class="card-body">
                ${workers.length > 0 ? `
                    <ul class="list-unstyled mb-0">
                        ${workers.map(worker => {
                            const workerName = worker.full_name ?? worker.name ?? '—';
                            return `
                            <li class="mb-2">
                                <i class="bi bi-person-circle me-2 text-primary"></i>
                                ${workerName}
                            </li>
                        `;
                        }).join('')}
                    </ul>
                ` : `
                    <div class="text-center text-muted py-3">
                        <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                        لا يوجد عمال مسجلون
                    </div>
                `}
            </div>
        </div>
    `;
}

function renderBatchDetailsCard(data) {
    const summarySection = document.getElementById('batchDetailsCardSummarySection');
    const materialsSection = document.getElementById('batchDetailsCardMaterialsSection');
    const rawMaterialsSection = document.getElementById('batchDetailsCardRawMaterialsSection');
    const workersSection = document.getElementById('batchDetailsCardWorkersSection');

    const batchNumber = data.batch_number ?? '—';
    const summaryRows = [
        ['رقم التشغيلة', batchNumber],
        ['المنتج', data.product_name ?? '—'],
        ['تاريخ الإنتاج', data.production_date ? data.production_date : '—'],
        ['الكمية المنتجة', data.quantity_produced ?? data.quantity ?? '—']
    ];

    if (data.honey_supplier_name) {
        summaryRows.push(['مورد العسل', data.honey_supplier_name]);
    }
    
    let packagingSuppliersDisplay = '—';
    if (data.packaging_suppliers_list && Array.isArray(data.packaging_suppliers_list) && data.packaging_suppliers_list.length > 0) {
        packagingSuppliersDisplay = data.packaging_suppliers_list.join('، ');
    } else if (data.packaging_supplier_name) {
        packagingSuppliersDisplay = data.packaging_supplier_name;
    }
    
    if (packagingSuppliersDisplay !== '—') {
        summaryRows.push(['مورد أدوات التعبئة', packagingSuppliersDisplay]);
    }
    if (data.notes) {
        summaryRows.push(['ملاحظات', data.notes]);
    }

    if (summarySection) {
        summarySection.innerHTML = `
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>ملخص التشغيلة</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive dashboard-table-wrapper">
                        <table class="table dashboard-table dashboard-table--compact align-middle mb-0">
                            <tbody>
                                ${summaryRows.map(([label, value]) => `
                                    <tr>
                                        <th class="w-25">${label}</th>
                                        <td>${value}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        `;
    }

    const packagingItems = Array.isArray(data.packaging_materials) 
        ? data.packaging_materials 
        : (Array.isArray(data.materials) ? data.materials : []);
    if (materialsSection) {
        materialsSection.innerHTML = `
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h6 class="mb-0"><i class="bi bi-box-seam me-2"></i>مواد التعبئة</h6>
                </div>
                <div class="card-body">
                    ${packagingItems.length > 0 ? `
                        <div class="table-responsive dashboard-table-wrapper">
                            <table class="table dashboard-table dashboard-table--compact align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>المادة</th>
                                        <th>الكمية</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${packagingItems.map(item => {
                                        const materialName = item.name ?? item.material_name ?? '—';
                                        let quantityDisplay = '—';
                                        
                                        if (item.details) {
                                            quantityDisplay = item.details;
                                        } else {
                                            const quantity = item.quantity_used ?? item.quantity ?? null;
                                            const unit = item.unit ?? '';
                                            quantityDisplay = quantity !== null && quantity !== undefined 
                                                ? `${quantity} ${unit}`.trim() 
                                                : '—';
                                        }
                                        
                                        return `
                                        <tr>
                                            <td>${materialName}</td>
                                            <td>${quantityDisplay}</td>
                                        </tr>
                                    `;
                                    }).join('')}
                                </tbody>
                            </table>
                        </div>
                    ` : `
                        <div class="text-center text-muted py-3">
                            <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                            لا توجد مواد تعبئة مسجلة
                        </div>
                    `}
                </div>
            </div>
        `;
    }

    const rawMaterialsItems = Array.isArray(data.raw_materials) ? data.raw_materials : [];
    if (rawMaterialsSection) {
        rawMaterialsSection.innerHTML = `
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h6 class="mb-0"><i class="bi bi-flower1 me-2"></i>الخامات</h6>
                </div>
                <div class="card-body">
                    ${rawMaterialsItems.length > 0 ? `
                        <div class="table-responsive dashboard-table-wrapper">
                            <table class="table dashboard-table dashboard-table--compact align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>المادة</th>
                                        <th>الكمية</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${rawMaterialsItems.map(item => {
                                        const materialName = item.name ?? item.material_name ?? '—';
                                        const quantity = item.quantity_used ?? item.quantity ?? null;
                                        const unit = item.unit ?? '';
                                        const quantityDisplay = quantity !== null && quantity !== undefined 
                                            ? `${quantity} ${unit}`.trim() 
                                            : '—';
                                        return `
                                        <tr>
                                            <td>${materialName}</td>
                                            <td>${quantityDisplay}</td>
                                        </tr>
                                    `;
                                    }).join('')}
                                </tbody>
                            </table>
                        </div>
                    ` : `
                        <div class="text-center text-muted py-3">
                            <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                            لا توجد خامات مسجلة
                        </div>
                    `}
                </div>
            </div>
        `;
    }

    const workers = Array.isArray(data.workers) ? data.workers : [];
    if (workersSection) {
        workersSection.innerHTML = `
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h6 class="mb-0"><i class="bi bi-people me-2"></i>العمال</h6>
                </div>
                <div class="card-body">
                    ${workers.length > 0 ? `
                        <ul class="list-unstyled mb-0">
                            ${workers.map(worker => {
                                const workerName = worker.full_name ?? worker.name ?? '—';
                                return `
                                <li class="mb-2">
                                    <i class="bi bi-person-circle me-2 text-primary"></i>
                                    ${workerName}
                                </li>
                            `;
                            }).join('')}
                        </ul>
                    ` : `
                        <div class="text-center text-muted py-3">
                            <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                            لا يوجد عمال مسجلون
                        </div>
                    `}
                </div>
            </div>
        `;
    }
}

// دالة لمسح cache يدوياً
function clearBatchDetailsCache(batchNumber = null) {
    if (batchNumber) {
        // مسح تشغيلة محددة
        batchDetailsCache.delete(batchNumber);
    } else {
        // مسح جميع البيانات من cache
        batchDetailsCache.clear();
    }
}

// تنظيف cache تلقائياً كل 5 دقائق
setInterval(() => {
    cleanBatchDetailsCache();
}, 5 * 60 * 1000); // 5 دقائق

// تنظيف cache عند تحميل الصفحة
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', cleanBatchDetailsCache);
} else {
    cleanBatchDetailsCache();
}

// جعل الدوال متاحة عالمياً
window.showBatchDetailsModal = showBatchDetailsModal;
window.showBarcodePrintModal = showBarcodePrintModal;
window.printBarcodes = printBarcodes;
window.clearBatchDetailsCache = clearBatchDetailsCache;

// ربط الأحداث للأزرار - انتظار تحميل DOM بالكامل
function initBatchDetailsEventListeners() {
    // انتظار تحميل الصفحة بالكامل (CSS + JS) قبل ربط الأحداث
    function waitForResources() {
        if (document.readyState === 'complete') {
            setTimeout(attachBatchDetailsListeners, 200);
        } else {
            window.addEventListener('load', function() {
                setTimeout(attachBatchDetailsListeners, 200);
            });
        }
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', waitForResources);
    } else {
        waitForResources();
    }
    
    // إضافة event listener للنموذج عند فتحه لضمان جاهزية العناصر
    const modalElement = document.getElementById('batchDetailsModal');
    if (modalElement) {
        modalElement.addEventListener('shown.bs.modal', function() {
            // استخدام requestAnimationFrame لضمان أن النموذج أصبح مرئياً بالكامل في DOM
            requestAnimationFrame(function() {
                requestAnimationFrame(function() {
                    // التأكد من وجود العناصر
                    const loader = modalElement.querySelector('#batchDetailsLoading');
                    const errorAlert = modalElement.querySelector('#batchDetailsError');
                    const contentWrapper = modalElement.querySelector('#batchDetailsContent');
                    
                    // إنشاء errorAlert ديناميكياً إذا لم يكن موجوداً
                    if (!errorAlert) {
                        const modalBody = modalElement.querySelector('.modal-body');
                        if (modalBody) {
                            const errorDivElement = document.createElement('div');
                            errorDivElement.className = 'alert alert-danger d-none';
                            errorDivElement.id = 'batchDetailsError';
                            errorDivElement.setAttribute('role', 'alert');
                            // إدراجه بعد loader
                            if (loader && loader.nextSibling) {
                                modalBody.insertBefore(errorDivElement, loader.nextSibling);
                            } else if (loader) {
                                modalBody.insertBefore(errorDivElement, loader);
                            } else {
                                modalBody.appendChild(errorDivElement);
                            }
                        }
                    }
                });
            });
        });
    }
}

// تهيئة الأحداث
initBatchDetailsEventListeners();

let batchDetailsListenerAttached = false;

function attachBatchDetailsListeners() {
    // التأكد من إضافة المستمع مرة واحدة فقط
    if (batchDetailsListenerAttached) {
        return;
    }
    batchDetailsListenerAttached = true;
    
    document.addEventListener('click', function(event) {
        // زر تفاصيل التشغيلة
        const detailsButton = event.target.closest('.js-batch-details');
        if (detailsButton) {
            event.preventDefault();
            event.stopPropagation();
            const batchNumber = detailsButton.getAttribute('data-batch') || detailsButton.dataset.batch;
            const productName = detailsButton.getAttribute('data-product') || detailsButton.dataset.product || '';
            if (batchNumber && batchNumber.trim() !== '') {
                if (typeof showBatchDetailsModal === 'function') {
                    showBatchDetailsModal(batchNumber, productName);
                } else {
                    console.error('showBatchDetailsModal function not found');
                }
            }
            return;
        }

        // زر طباعة الباركود
        const printButton = event.target.closest('.js-print-barcode');
        if (printButton) {
            event.preventDefault();
            event.stopPropagation();
            const batchNumber = printButton.getAttribute('data-batch') || printButton.dataset.batch;
            const productName = printButton.getAttribute('data-product') || printButton.dataset.product || '';
            const quantity = parseFloat(printButton.getAttribute('data-quantity') || printButton.dataset.quantity || '1');
            if (batchNumber && batchNumber.trim() !== '') {
                if (typeof window.showBarcodePrintModal === 'function') {
                    window.showBarcodePrintModal(batchNumber, productName, quantity);
                } else {
                    console.error('showBarcodePrintModal function not found');
                }
            }
            return;
        }
    });
}

// معالجة تعديل المنتجات الخارجية
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        initEditExternalButtons();
    });
} else {
    initEditExternalButtons();
}

function initEditExternalButtons() {
    document.querySelectorAll('.js-edit-external').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const name = this.dataset.name;
            const quantity = this.dataset.quantity;
            const unit = this.dataset.unit;
            const price = this.dataset.price;
            const category = this.dataset.category || '';
            
            document.getElementById('edit_product_id').value = id;
            closeAllForms();
            
            if (isMobile()) {
                // على الموبايل: استخدام Card
                const card = document.getElementById('editExternalProductCard');
                if (card) {
                    document.getElementById('editCard_product_id').value = id;
                    document.getElementById('editCard_product_name').value = name;
                    document.getElementById('editCard_quantity').value = quantity;
                    document.getElementById('editCard_unit').value = unit;
                    document.getElementById('editCard_unit_price').value = price;
                    
                    // تحديد الصنف
                    const categorySelect = document.getElementById('editCard_category_id');
                    if (categorySelect && category) {
                        for (let i = 0; i < categorySelect.options.length; i++) {
                            if (categorySelect.options[i].textContent.trim() === category.trim()) {
                                categorySelect.selectedIndex = i;
                                break;
                            }
                        }
                    }
                    
                    card.style.display = 'block';
                    setTimeout(function() {
                        scrollToElement(card);
                    }, 50);
                }
            } else {
                // على الكمبيوتر: استخدام Modal
                document.getElementById('edit_product_id').value = id;
                document.getElementById('edit_product_name').value = name;
                document.getElementById('edit_quantity').value = quantity;
                document.getElementById('edit_unit').value = unit;
                document.getElementById('edit_unit_price').value = price;
                
                // تحديد الصنف
                const categorySelect = document.getElementById('edit_category_id');
                if (categorySelect && category) {
                    for (let i = 0; i < categorySelect.options.length; i++) {
                        if (categorySelect.options[i].textContent.trim() === category.trim()) {
                            categorySelect.selectedIndex = i;
                            // إظهار حقل الإدخال اليدوي إذا كان "اخري"
                            if (categorySelect.options[i].textContent.trim() === 'اخري') {
                                const customDiv = document.getElementById('edit_custom_category_div');
                                if (customDiv) customDiv.style.display = 'block';
                            }
                            break;
                        }
                    }
                }
                
                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    new bootstrap.Modal(document.getElementById('editExternalProductModal')).show();
                } else {
                    console.error('Bootstrap Modal not available');
                }
            }
        });
    });

    // معالجة حذف المنتجات الخارجية
    document.querySelectorAll('.js-delete-external').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const name = this.dataset.name;
            
            closeAllForms();
            
            if (isMobile()) {
                // على الموبايل: استخدام Card
                const card = document.getElementById('deleteExternalProductCard');
                if (card) {
                    document.getElementById('deleteCard_product_id').value = id;
                    document.getElementById('deleteCard_product_name').textContent = name;
                    
                    card.style.display = 'block';
                    setTimeout(function() {
                        scrollToElement(card);
                    }, 50);
                }
            } else {
                // على الكمبيوتر: استخدام Modal
                document.getElementById('delete_product_id').value = id;
                document.getElementById('delete_product_name').textContent = name;
                
                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    new bootstrap.Modal(document.getElementById('deleteExternalProductModal')).show();
                } else {
                    console.error('Bootstrap Modal not available');
                }
            }
        });
    });
}

// إزالة معاملات success/error من URL بعد عرض الرسالة (بدون إعادة تحميل)
(function() {
    const successAlert = document.getElementById('successAlert');
    const errorAlert = document.getElementById('errorAlert');
    
    // إذا كانت هناك رسالة نجاح أو خطأ، إزالة المعاملات من URL
    if (successAlert || errorAlert) {
        if (window.history && window.history.replaceState) {
            const url = new URL(window.location);
            if (url.searchParams.has('success') || url.searchParams.has('error')) {
                url.searchParams.delete('success');
                url.searchParams.delete('error');
                // إزالة معاملات _v و _t أيضاً إذا كانت موجودة
                url.searchParams.delete('_v');
                url.searchParams.delete('_t');
                window.history.replaceState({}, '', url);
            }
        }
    }
})();

// ===== البحث والفلترة الديناميكية =====
(function() {
    let factorySearchTimeout = null;
    let externalSearchTimeout = null;
    const API_URL = '<?php echo getRelativeUrl("api/search_company_products.php"); ?>';
    
    // عناصر البحث والفلترة لمنتجات المصنع
    const factorySearchInput = document.getElementById('factorySearchInput');
    const factoryCategoryFilter = document.getElementById('factoryCategoryFilter');
    const factoryMinPrice = document.getElementById('factoryMinPrice');
    const factoryMaxPrice = document.getElementById('factoryMaxPrice');
    const resetFactoryFiltersBtn = document.getElementById('resetFactoryFiltersBtn');
    
    // عناصر البحث والفلترة للمنتجات الخارجية
    const externalSearchInput = document.getElementById('externalSearchInput');
    const externalCategoryFilter = document.getElementById('externalCategoryFilter');
    const externalMinPrice = document.getElementById('externalMinPrice');
    const externalMaxPrice = document.getElementById('externalMaxPrice');
    const externalMinQuantity = document.getElementById('externalMinQuantity');
    const externalMaxQuantity = document.getElementById('externalMaxQuantity');
    const resetExternalFiltersBtn = document.getElementById('resetExternalFiltersBtn');
    
    // عناصر العرض
    const factoryProductsContainer = document.getElementById('factoryProductsContainer');
    const externalProductsContainer = document.getElementById('externalProductsContainer');
    const factoryProductsCount = document.getElementById('factoryProductsCount');
    const externalProductsCount = document.getElementById('externalProductsCount');
    const factoryTotalValue = document.getElementById('factoryTotalValue');
    const externalTotalValue = document.getElementById('externalTotalValue');
    const factoryProductsStats = document.getElementById('factoryProductsStats');
    const externalProductsStats = document.getElementById('externalProductsStats');
    
    // دالة جلب منتجات المصنع من API
    async function fetchFactoryProducts() {
        const params = new URLSearchParams();
        params.append('product_type', 'factory');
        
        if (factorySearchInput && factorySearchInput.value.trim()) {
            params.append('search', factorySearchInput.value.trim());
        }
        
        if (factoryCategoryFilter && factoryCategoryFilter.value) {
            params.append('category', factoryCategoryFilter.value);
        }
        
        if (factoryMinPrice && factoryMinPrice.value) {
            params.append('min_price', factoryMinPrice.value);
        }
        
        if (factoryMaxPrice && factoryMaxPrice.value) {
            params.append('max_price', factoryMaxPrice.value);
        }
        
        try {
            const response = await fetch(API_URL + '?' + params.toString());
            const data = await response.json();
            
            if (data.success) {
                updateFactoryProducts(data.factory_products || []);
                updateFactoryStatistics(data.statistics || {});
            } else {
                console.error('Error fetching factory products:', data.message);
            }
        } catch (error) {
            console.error('Error fetching factory products:', error);
        }
    }
    
    // دالة جلب المنتجات الخارجية من API
    async function fetchExternalProducts() {
        const params = new URLSearchParams();
        params.append('product_type', 'external');
        
        if (externalSearchInput && externalSearchInput.value.trim()) {
            params.append('search', externalSearchInput.value.trim());
        }
        
        if (externalCategoryFilter && externalCategoryFilter.value) {
            params.append('category', externalCategoryFilter.value);
        }
        
        if (externalMinPrice && externalMinPrice.value) {
            params.append('min_price', externalMinPrice.value);
        }
        
        if (externalMaxPrice && externalMaxPrice.value) {
            params.append('max_price', externalMaxPrice.value);
        }
        
        if (externalMinQuantity && externalMinQuantity.value) {
            params.append('min_quantity', externalMinQuantity.value);
        }
        
        if (externalMaxQuantity && externalMaxQuantity.value) {
            params.append('max_quantity', externalMaxQuantity.value);
        }
        
        try {
            const response = await fetch(API_URL + '?' + params.toString());
            const data = await response.json();
            
            if (data.success) {
                updateExternalProducts(data.external_products || []);
                updateExternalStatistics(data.statistics || {});
            } else {
                console.error('Error fetching external products:', data.message);
            }
        } catch (error) {
            console.error('Error fetching external products:', error);
        }
    }
    
    // تحديث منتجات المصنع
    function updateFactoryProducts(products) {
        if (!factoryProductsContainer) return;
        
        if (products.length === 0) {
            factoryProductsContainer.innerHTML = `
                <div style="padding: 25px;">
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        لا توجد منتجات مصنع تطابق البحث
                    </div>
                </div>
            `;
            return;
        }
        
        const productsHTML = products.map(product => {
            const batchNumber = product.batch_number || '';
            const productName = product.product_name || 'غير محدد';
            const category = product.product_category || '—';
            const productionDate = product.production_date ? formatDate(product.production_date) : '—';
            const quantity = parseFloat(product.available_quantity || 0).toFixed(2);
            const unitPrice = parseFloat(product.unit_price || 0);
            const totalPrice = parseFloat(product.total_price || 0);
            
            const batchDisplay = batchNumber && batchNumber !== '—' 
                ? `<a href="#" class="product-batch-id">${escapeHtml(batchNumber)}</a>`
                : `<span class="product-batch-id">—</span>`;
            
            const barcodeHTML = batchNumber && batchNumber !== '—'
                ? `
                    <div class="product-barcode-box">
                        <div class="product-barcode-container" data-batch="${escapeHtml(batchNumber)}">
                            <svg class="barcode-svg" style="width: 100%; height: 50px;"></svg>
                        </div>
                        <div class="product-barcode-id">${escapeHtml(batchNumber)}</div>
                    </div>
                `
                : `
                    <div class="product-barcode-box">
                        <div class="product-barcode-id" style="color: #999;">لا يوجد باركود</div>
                    </div>
                `;
            
            const isProductionRole = <?php echo json_encode($isProductionRole); ?>;
            const editButtonsHTML = !isProductionRole ? `
                        <button type="button" class="btn-view js-edit-factory-category" style="border: none; cursor: pointer; flex: 1; min-width: calc(50% - 5px); background: #ffc107; color: #000; padding: 10px 16px; border-radius: 10px; font-weight: bold; font-size: 13px;" data-batch-id="${product.id || 0}" data-product="${escapeHtml(productName)}" data-category="${escapeHtml(category)}"><i class="bi bi-pencil me-1"></i>تعديل الصنف</button>
                        <button type="button" class="btn-view js-edit-factory-price" style="border: none; cursor: pointer; flex: 1; min-width: calc(50% - 5px); background: #17a2b8; color: #fff; padding: 10px 16px; border-radius: 10px; font-weight: bold; font-size: 13px;" data-batch-id="${product.id || 0}" data-product="${escapeHtml(productName)}" data-unit-price="${product.unit_price != null ? product.unit_price : ''}"><i class="bi bi-currency-dollar me-1"></i>تعديل السعر</button>
            ` : '';
            const actionsHTML = batchNumber && batchNumber !== '—'
                ? `
                    <div class="product-actions" style="display: flex; gap: 10px; margin-top: 15px; flex-wrap: wrap;">
                        <button type="button" 
                                class="btn-view js-batch-details" 
                                style="border: none; cursor: pointer; flex: 1; min-width: calc(50% - 5px); background: #0c2c80; color: white; padding: 10px 16px; border-radius: 10px; font-weight: bold; font-size: 13px;"
                                data-batch="${escapeHtml(batchNumber)}"
                                data-product="${escapeHtml(productName)}"
                                data-view-url="<?php echo getRelativeUrl('production.php?page=batch_numbers&batch_number='); ?>${encodeURIComponent(batchNumber)}">
                            <i class="bi bi-eye me-1"></i>عرض التفاصيل
                        </button>
                        <button type="button" 
                                class="btn-view js-print-barcode" 
                                style="border: none; cursor: pointer; flex: 1; min-width: calc(50% - 5px); background: #28a745; color: white; padding: 10px 16px; border-radius: 10px; font-weight: bold; font-size: 13px;"
                                data-batch="${escapeHtml(batchNumber)}"
                                data-product="${escapeHtml(productName)}"
                                data-quantity="${escapeHtml(quantity)}">
                            <i class="bi bi-printer me-1"></i>طباعة الباركود
                        </button>
                        ${editButtonsHTML}
                        <button type="button" class="btn-view js-add-quantity-factory" style="border: none; cursor: pointer; flex: 1; min-width: calc(50% - 5px); background: #6f42c1; color: #fff; padding: 10px 16px; border-radius: 10px; font-weight: bold; font-size: 13px;" data-batch-id="${product.id || 0}" data-product="${escapeHtml(productName)}" data-quantity="${quantity}"><i class="bi bi-plus-circle me-1"></i>إضافة كمية</button>
                    </div>
                `
                : `
                    <span class="btn-view" style="opacity: 0.5; cursor: not-allowed; display: inline-block; margin-top: 15px; background: #0c2c80; color: white; padding: 10px 16px; border-radius: 10px; font-weight: bold; font-size: 13px;">
                        <i class="bi bi-eye me-1"></i>عرض التفاصيل
                    </span>
                `;
            
            return `
                <div class="product-card">
                    <div class="product-status">
                        <i class="bi bi-building me-1"></i>مصنع
                    </div>
                    <div class="product-name">${escapeHtml(productName)}</div>
                    ${batchDisplay}
                    ${barcodeHTML}
                    <div class="product-detail-row"><span>الصنف:</span> <span>${escapeHtml(category)}</span></div>
                    <div class="product-detail-row"><span>تاريخ الإنتاج:</span> <span>${escapeHtml(productionDate)}</span></div>
                    <div class="product-detail-row"><span>الكمية:</span> <span><strong>${quantity}</strong></span></div>
                    <div class="product-detail-row"><span>سعر الوحدة:</span> <span>${formatCurrency(unitPrice)}</span></div>
                    <div class="product-detail-row"><span>إجمالي القيمة:</span> <span><strong class="text-success">${formatCurrency(totalPrice)}</strong></span></div>
                    ${actionsHTML}
                </div>
            `;
        }).join('');
        
        factoryProductsContainer.innerHTML = `<div class="products-grid" id="factoryProductsGrid">${productsHTML}</div>`;
        
        // توليد الباركودات
        generateBarcodes();
        
        // إعادة إرفاق event listeners
        attachBatchDetailsListeners();
    }
    
    // تحديث المنتجات الخارجية
    function updateExternalProducts(products) {
        if (!externalProductsContainer) return;
        
        if (products.length === 0) {
            externalProductsContainer.innerHTML = `
                <div style="padding: 25px;">
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        لا توجد منتجات خارجية تطابق البحث
                    </div>
                </div>
            `;
            return;
        }
        
        const productsHTML = products.map(product => {
            const productName = product.name || 'غير محدد';
            const quantity = parseFloat(product.quantity || 0).toFixed(2);
            const unit = product.unit || 'قطعة';
            const unitPrice = parseFloat(product.unit_price || 0);
            const totalValue = parseFloat(product.total_value || 0);
            const category = product.category || '—';
            
            const canEditExternal = <?php echo json_encode($currentUser['role'] !== 'accountant' && !$isProductionRole); ?>;
            const editDeleteHTML = canEditExternal ? `
                        <button type="button" class="btn btn-outline-primary js-edit-external" style="flex: 1; min-width: calc(50% - 5px); border-radius: 10px; padding: 10px 16px; font-weight: bold; font-size: 13px;" data-id="${product.id}" data-name="${escapeHtml(productName)}" data-quantity="${product.quantity}" data-unit="${escapeHtml(unit)}" data-price="${product.unit_price}" data-category="${escapeHtml(category)}"><i class="bi bi-pencil me-1"></i>تعديل</button>
                        <button type="button" class="btn btn-outline-danger js-delete-external" style="flex: 1; min-width: calc(50% - 5px); border-radius: 10px; padding: 10px 16px; font-weight: bold; font-size: 13px;" data-id="${product.id}" data-name="${escapeHtml(productName)}"><i class="bi bi-trash me-1"></i>حذف</button>
            ` : '';
            const actionsHTML = `
                    <div class="product-actions" style="display: flex; gap: 10px; margin-top: 15px; flex-wrap: wrap;">
                        ${editDeleteHTML}
                        <button type="button" class="btn btn-outline-secondary js-add-quantity-external" style="flex: 1; min-width: calc(50% - 5px); border-radius: 10px; padding: 10px 16px; font-weight: bold; font-size: 13px;" data-product-id="${product.id}" data-product-name="${escapeHtml(productName)}" data-quantity="${product.quantity}"><i class="bi bi-plus-circle me-1"></i>إضافة كمية</button>
                    </div>
                `;
            
            return `
                <div class="product-card">
                    <div class="product-status" style="background: #10b981;">
                        <i class="bi bi-cart4 me-1"></i>خارجي
                    </div>
                    <div class="product-name">${escapeHtml(productName)}</div>
                    <div style="color: #94a3b8; font-size: 13px; margin-bottom: 10px;">منتج خارجي</div>
                    <div class="product-detail-row"><span>الصنف:</span> <span>${escapeHtml(category)}</span></div>
                    <div class="product-detail-row"><span>الكمية:</span> <span><strong>${quantity} ${escapeHtml(unit)}</strong></span></div>
                    <div class="product-detail-row"><span>سعر الوحدة:</span> <span>${formatCurrency(unitPrice)}</span></div>
                    <div class="product-detail-row"><span>الإجمالي:</span> <span><strong class="text-success">${formatCurrency(totalValue)}</strong></span></div>
                    ${actionsHTML}
                </div>
            `;
        }).join('');
        
        externalProductsContainer.innerHTML = `<div class="products-grid" id="externalProductsGrid">${productsHTML}</div>`;
        
        // إعادة إرفاق event listeners
        initEditExternalButtons();
    }
    
    // تحديث إحصائيات منتجات المصنع
    function updateFactoryStatistics(stats) {
        if (factoryProductsCount) {
            factoryProductsCount.textContent = (stats.total_factory_products || 0) + ' منتج';
        }
        
        if (factoryTotalValue) {
            factoryTotalValue.textContent = formatCurrency(stats.total_factory_value || 0);
        }
        
        if (factoryProductsStats) {
            factoryProductsStats.style.display = (stats.total_factory_products || 0) > 0 ? '' : 'none';
        }
    }
    
    // تحديث إحصائيات المنتجات الخارجية
    function updateExternalStatistics(stats) {
        if (externalProductsCount) {
            externalProductsCount.textContent = (stats.total_external_products || 0) + ' منتج';
        }
        
        if (externalTotalValue) {
            externalTotalValue.textContent = formatCurrency(stats.total_external_value || 0);
        }
        
        if (externalProductsStats) {
            externalProductsStats.style.display = (stats.total_external_products || 0) > 0 ? '' : 'none';
        }
    }
    
    // دالة توليد الباركودات
    function generateBarcodes() {
        if (typeof JsBarcode === 'undefined') {
            setTimeout(generateBarcodes, 100);
            return;
        }
        
        const containers = document.querySelectorAll('.product-barcode-container[data-batch]');
        containers.forEach(function(container) {
            const batchNumber = container.getAttribute('data-batch');
            const svg = container.querySelector('svg.barcode-svg');
            
            if (svg && batchNumber && batchNumber.trim() !== '') {
                try {
                    svg.innerHTML = '';
                    JsBarcode(svg, batchNumber, {
                        format: "CODE128",
                        width: 2,
                        height: 50,
                        displayValue: false,
                        margin: 5,
                        background: "#ffffff",
                        lineColor: "#000000"
                    });
                } catch (error) {
                    console.error('Error generating barcode:', error);
                    svg.innerHTML = '<text x="50%" y="50%" text-anchor="middle" font-size="12" fill="#666" font-family="Arial">' + batchNumber + '</text>';
                }
            }
        });
    }
    
    // دوال مساعدة
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function formatCurrency(amount) {
        if (typeof amount === 'string') {
            amount = parseFloat(amount) || 0;
        }
        if (!Number.isFinite(amount)) {
            amount = 0;
        }
        // استخدام نفس تنسيق PHP: number_format($amount, 2, '.', ',') . ' ' . $currencySymbol
        const formatted = amount.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return formatted + ' <?php echo addslashes(getCurrencySymbol()); ?>';
    }
    
    function formatDate(dateString) {
        if (!dateString) return '—';
        const date = new Date(dateString);
        return date.toLocaleDateString('ar-EG', {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
    }
    
    // دوال البحث مع تأخير (debounce)
    function performFactorySearch() {
        clearTimeout(factorySearchTimeout);
        factorySearchTimeout = setTimeout(() => {
            fetchFactoryProducts();
        }, 300);
    }
    
    function performExternalSearch() {
        clearTimeout(externalSearchTimeout);
        externalSearchTimeout = setTimeout(() => {
            fetchExternalProducts();
        }, 300);
    }
    
    // إرفاق event listeners لمنتجات المصنع
    if (factorySearchInput) {
        factorySearchInput.addEventListener('input', performFactorySearch);
    }
    
    if (factoryCategoryFilter) {
        factoryCategoryFilter.addEventListener('change', performFactorySearch);
    }
    
    if (factoryMinPrice) {
        factoryMinPrice.addEventListener('input', performFactorySearch);
    }
    
    if (factoryMaxPrice) {
        factoryMaxPrice.addEventListener('input', performFactorySearch);
    }
    
    // إرفاق event listeners للمنتجات الخارجية
    if (externalSearchInput) {
        externalSearchInput.addEventListener('input', performExternalSearch);
    }
    
    if (externalCategoryFilter) {
        externalCategoryFilter.addEventListener('change', performExternalSearch);
    }
    
    if (externalMinPrice) {
        externalMinPrice.addEventListener('input', performExternalSearch);
    }
    
    if (externalMaxPrice) {
        externalMaxPrice.addEventListener('input', performExternalSearch);
    }
    
    if (externalMinQuantity) {
        externalMinQuantity.addEventListener('input', performExternalSearch);
    }
    
    if (externalMaxQuantity) {
        externalMaxQuantity.addEventListener('input', performExternalSearch);
    }
})();

// ===== معالجة اختيار الصنف "اخري" =====
(function() {
    // معالجة نموذج إضافة منتج خارجي
    const addCategorySelect = document.getElementById('add_category_id');
    const addCustomCategoryDiv = document.getElementById('add_custom_category_div');
    const addCustomCategoryInput = document.getElementById('add_custom_category');
    
    if (addCategorySelect) {
        addCategorySelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const isOther = selectedOption && selectedOption.textContent.trim() === 'اخري';
            
            if (addCustomCategoryDiv) {
                addCustomCategoryDiv.style.display = isOther ? 'block' : 'none';
            }
            if (addCustomCategoryInput) {
                addCustomCategoryInput.required = isOther;
                if (!isOther) {
                    addCustomCategoryInput.value = '';
                }
            }
        });
    }
    
    // معالجة نموذج تعديل منتج خارجي
    const editCategorySelect = document.getElementById('edit_category_id');
    const editCustomCategoryDiv = document.getElementById('edit_custom_category_div');
    const editCustomCategoryInput = document.getElementById('edit_custom_category');
    
    if (editCategorySelect) {
        editCategorySelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const isOther = selectedOption && selectedOption.textContent.trim() === 'اخري';
            
            if (editCustomCategoryDiv) {
                editCustomCategoryDiv.style.display = isOther ? 'block' : 'none';
            }
            if (editCustomCategoryInput) {
                editCustomCategoryInput.required = isOther;
                if (!isOther) {
                    editCustomCategoryInput.value = '';
                }
            }
        });
    }
    
    // معالجة نموذج تعديل صنف منتج المصنع
    const editFactoryCategorySelect = document.getElementById('edit_factory_category_id');
    const editFactoryCustomCategoryDiv = document.getElementById('edit_factory_custom_category_div');
    const editFactoryCustomCategoryInput = document.getElementById('edit_factory_custom_category');
    
    if (editFactoryCategorySelect) {
        editFactoryCategorySelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const isOther = selectedOption && selectedOption.textContent.trim() === 'اخري';
            
            if (editFactoryCustomCategoryDiv) {
                editFactoryCustomCategoryDiv.style.display = isOther ? 'block' : 'none';
            }
            if (editFactoryCustomCategoryInput) {
                editFactoryCustomCategoryInput.required = isOther;
                if (!isOther) {
                    editFactoryCustomCategoryInput.value = '';
                }
            }
        });
    }
    
    // إدارة بطاقة إضافة منتج مصنع جديد
    const addFactoryCategorySelect = document.getElementById('add_factory_category_id');
    const addFactoryCustomCategoryDiv = document.getElementById('add_factory_custom_category_div');
    const addFactoryCustomCategoryInput = document.getElementById('add_factory_custom_category');
    const addFactoryProductForm = document.getElementById('addFactoryProductForm');
    const addFactoryProductCard = document.getElementById('addFactoryProductCard');
    const toggleAddFactoryProductCardBtn = document.getElementById('toggleAddFactoryProductCard');
    const cancelAddFactoryProductBtn = document.getElementById('cancelAddFactoryProduct');
    
    // إظهار/إخفاء البطاقة
    if (toggleAddFactoryProductCardBtn && addFactoryProductCard) {
        toggleAddFactoryProductCardBtn.addEventListener('click', function() {
            if (addFactoryProductCard.style.display === 'none') {
                addFactoryProductCard.style.display = 'block';
                // التمرير إلى البطاقة
                addFactoryProductCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } else {
                addFactoryProductCard.style.display = 'none';
                resetAddFactoryForm();
            }
        });
    }
    
    // إلغاء وإخفاء البطاقة
    if (cancelAddFactoryProductBtn && addFactoryProductCard) {
        cancelAddFactoryProductBtn.addEventListener('click', function() {
            addFactoryProductCard.style.display = 'none';
            resetAddFactoryForm();
        });
    }
    
    // إدارة حقل الصنف المخصص
    if (addFactoryCategorySelect) {
        addFactoryCategorySelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const isOther = selectedOption && selectedOption.textContent.trim() === 'اخري';
            
            if (addFactoryCustomCategoryDiv) {
                addFactoryCustomCategoryDiv.style.display = isOther ? 'block' : 'none';
            }
            if (addFactoryCustomCategoryInput) {
                addFactoryCustomCategoryInput.required = isOther;
                if (!isOther) {
                    addFactoryCustomCategoryInput.value = '';
                }
            }
        });
    }
    
    // دالة إعادة تعيين النموذج
    function resetAddFactoryForm() {
        if (addFactoryProductForm) {
            addFactoryProductForm.reset();
            // إعادة تعيين تاريخ الإنتاج إلى اليوم
            const productionDateInput = document.getElementById('add_factory_production_date');
            if (productionDateInput) {
                productionDateInput.value = new Date().toISOString().split('T')[0];
            }
            // إخفاء حقل الصنف المخصص
            if (addFactoryCustomCategoryDiv) {
                addFactoryCustomCategoryDiv.style.display = 'none';
            }
            if (addFactoryCustomCategoryInput) {
                addFactoryCustomCategoryInput.required = false;
                addFactoryCustomCategoryInput.value = '';
            }
        }
    }
    
    // معالجة نموذج إضافة منتج خارجي للموبايل
    const addCardCategorySelect = document.getElementById('addCard_category_id');
    const addCardCustomCategoryDiv = document.getElementById('addCard_custom_category_div');
    const addCardCustomCategoryInput = document.getElementById('addCard_custom_category');
    
    if (addCardCategorySelect) {
        addCardCategorySelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const isOther = selectedOption && selectedOption.textContent.trim() === 'اخري';
            
            if (addCardCustomCategoryDiv) {
                addCardCustomCategoryDiv.style.display = isOther ? 'block' : 'none';
            }
            if (addCardCustomCategoryInput) {
                addCardCustomCategoryInput.required = isOther;
                if (!isOther) {
                    addCardCustomCategoryInput.value = '';
                }
            }
        });
    }
    
    // معالجة نموذج تعديل منتج خارجي للموبايل
    const editCardCategorySelect = document.getElementById('editCard_category_id');
    const editCardCustomCategoryDiv = document.getElementById('editCard_custom_category_div');
    const editCardCustomCategoryInput = document.getElementById('editCard_custom_category');
    
    if (editCardCategorySelect) {
        editCardCategorySelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const isOther = selectedOption && selectedOption.textContent.trim() === 'اخري';
            
            if (editCardCustomCategoryDiv) {
                editCardCustomCategoryDiv.style.display = isOther ? 'block' : 'none';
            }
            if (editCardCustomCategoryInput) {
                editCardCustomCategoryInput.required = isOther;
                if (!isOther) {
                    editCardCustomCategoryInput.value = '';
                }
            }
        });
    }
    
    // معالجة زر تعديل صنف منتج المصنع
    document.addEventListener('click', function(e) {
        const editBtn = e.target.closest('.js-edit-factory-category');
        if (editBtn) {
            const batchId = editBtn.getAttribute('data-batch-id');
            const productName = editBtn.getAttribute('data-product');
            const currentCategory = editBtn.getAttribute('data-category');
            
            if (batchId && document.getElementById('edit_factory_batch_id')) {
                document.getElementById('edit_factory_batch_id').value = batchId;
                document.getElementById('edit_factory_product_name').value = productName || '';
                
                // تحديد الصنف الحالي في القائمة المنسدلة
                if (editFactoryCategorySelect && currentCategory) {
                    // البحث عن الصنف في القائمة
                    for (let i = 0; i < editFactoryCategorySelect.options.length; i++) {
                        if (editFactoryCategorySelect.options[i].textContent.trim() === currentCategory.trim()) {
                            editFactoryCategorySelect.selectedIndex = i;
                            break;
                        }
                    }
                }
                
                // إخفاء حقل الإدخال اليدوي
                if (editFactoryCustomCategoryDiv) {
                    editFactoryCustomCategoryDiv.style.display = 'none';
                }
                if (editFactoryCustomCategoryInput) {
                    editFactoryCustomCategoryInput.value = '';
                    editFactoryCustomCategoryInput.required = false;
                }
                
                // فتح النموذج
                const modal = new bootstrap.Modal(document.getElementById('editFactoryProductCategoryModal'));
                modal.show();
            }
        }
        // معالجة زر تعديل سعر منتج المصنع (للمدير والمحاسب)
        const editPriceBtn = e.target.closest('.js-edit-factory-price');
        if (editPriceBtn) {
            const batchId = editPriceBtn.getAttribute('data-batch-id');
            const productName = editPriceBtn.getAttribute('data-product');
            const unitPrice = editPriceBtn.getAttribute('data-unit-price') || '0';
            const batchIdEl = document.getElementById('edit_factory_price_batch_id');
            const productNameEl = document.getElementById('edit_factory_price_product_name');
            const unitPriceEl = document.getElementById('edit_factory_price_unit_price');
            if (batchId && batchIdEl && productNameEl && unitPriceEl) {
                batchIdEl.value = batchId;
                productNameEl.value = productName || '';
                unitPriceEl.value = unitPrice;
                const modal = new bootstrap.Modal(document.getElementById('editFactoryProductPriceModal'));
                modal.show();
            }
        }
        // معالجة زر إضافة كمية لمنتج مصنع
        const addQtyFactoryBtn = e.target.closest('.js-add-quantity-factory');
        if (addQtyFactoryBtn) {
            const batchId = addQtyFactoryBtn.getAttribute('data-batch-id');
            const productName = addQtyFactoryBtn.getAttribute('data-product');
            const currentQty = addQtyFactoryBtn.getAttribute('data-quantity') || '0';
            const batchIdEl = document.getElementById('add_quantity_factory_batch_id');
            const productNameEl = document.getElementById('add_quantity_factory_product_name');
            const currentQtyEl = document.getElementById('add_quantity_factory_current_qty');
            const toAddEl = document.getElementById('add_quantity_factory_to_add');
            if (batchId && batchIdEl && productNameEl && currentQtyEl && toAddEl) {
                batchIdEl.value = batchId;
                productNameEl.value = productName || '';
                currentQtyEl.value = currentQty;
                toAddEl.value = '';
                const modal = new bootstrap.Modal(document.getElementById('addQuantityFactoryModal'));
                modal.show();
            }
        }
        // معالجة زر إضافة كمية لمنتج خارجي
        const addQtyExternalBtn = e.target.closest('.js-add-quantity-external');
        if (addQtyExternalBtn) {
            const productId = addQtyExternalBtn.getAttribute('data-product-id');
            const productName = addQtyExternalBtn.getAttribute('data-product-name');
            const currentQty = addQtyExternalBtn.getAttribute('data-quantity') || '0';
            const productIdEl = document.getElementById('add_quantity_external_product_id');
            const productNameEl = document.getElementById('add_quantity_external_product_name');
            const currentQtyEl = document.getElementById('add_quantity_external_current_qty');
            const toAddEl = document.getElementById('add_quantity_external_to_add');
            if (productId && productIdEl && productNameEl && currentQtyEl && toAddEl) {
                productIdEl.value = productId;
                productNameEl.value = productName || '';
                currentQtyEl.value = currentQty;
                toAddEl.value = '';
                const modal = new bootstrap.Modal(document.getElementById('addQuantityExternalModal'));
                modal.show();
            }
        }
    });
})();
</script>

