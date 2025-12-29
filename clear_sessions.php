<?php
/**
 * سكريبت لمسح جميع الجلسات
 * Script to clear all sessions
 */

define('ACCESS_ALLOWED', true);

// بدء الجلسة لمعرفة مسار الجلسات
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$deletedCount = 0;
$errors = [];

// 1. حذف الجلسات من المجلد المخصص (tmp/sessions) - تم تعطيله
// تم إزالة دعم المجلد المخصص للجلسات - استخدام المسار الافتراضي لـ PHP فقط
// Custom sessions folder support removed - using PHP default session path only
// $customSessionPath = __DIR__ . '/tmp/sessions';
// if (is_dir($customSessionPath)) {
//     $files = glob($customSessionPath . '/sess_*');
//     foreach ($files as $file) {
//         if (is_file($file)) {
//             if (@unlink($file)) {
//                 $deletedCount++;
//             } else {
//                 $errors[] = "فشل حذف: " . basename($file);
//             }
//         }
//     }
// }

// 2. حذف الجلسات من المسار الافتراضي لـ PHP
$defaultSessionPath = session_save_path();
if (empty($defaultSessionPath) || $defaultSessionPath === '') {
    // إذا كان المسار فارغاً، استخدم المسار الافتراضي لنظام التشغيل
    $defaultSessionPath = sys_get_temp_dir();
}

if ($defaultSessionPath && is_dir($defaultSessionPath)) {
    $files = glob($defaultSessionPath . '/sess_*');
    if ($files) {
        foreach ($files as $file) {
            if (is_file($file)) {
                if (@unlink($file)) {
                    $deletedCount++;
                } else {
                    $errors[] = "فشل حذف: " . basename($file);
                }
            }
        }
    }
}

// 3. حذف الجلسة الحالية
if (session_status() === PHP_SESSION_ACTIVE) {
    // مسح جميع بيانات الجلسة
    $_SESSION = [];
    
    // حذف كوكيز الجلسة
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    
    // تدمير الجلسة
    session_destroy();
}

// النتيجة
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مسح الجلسات</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            background: white;
            border-radius: 15px;
            padding: 30px;
            max-width: 600px;
            width: 100%;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        h1 {
            color: #333;
            margin-top: 0;
            text-align: center;
            border-bottom: 3px solid #667eea;
            padding-bottom: 15px;
        }
        .result {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
            border-right: 5px solid #28a745;
        }
        .result.success {
            border-right-color: #28a745;
            background: #d4edda;
        }
        .result.error {
            border-right-color: #dc3545;
            background: #f8d7da;
        }
        .count {
            font-size: 2em;
            font-weight: bold;
            color: #28a745;
            text-align: center;
            margin: 20px 0;
        }
        .error-list {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 5px;
            padding: 10px;
            margin-top: 15px;
        }
        .error-list ul {
            margin: 5px 0;
            padding-right: 25px;
        }
        .btn {
            display: inline-block;
            padding: 12px 30px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin-top: 20px;
            transition: background 0.3s;
            text-align: center;
        }
        .btn:hover {
            background: #5568d3;
        }
        .btn-container {
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>✅ تم مسح الجلسات</h1>
        
        <div class="result <?php echo empty($errors) ? 'success' : 'error'; ?>">
            <div class="count">
                <?php echo $deletedCount; ?> ملف جلسة
            </div>
            <p style="text-align: center; margin: 10px 0;">
                تم حذف <strong><?php echo $deletedCount; ?></strong> ملف جلسة بنجاح
            </p>
            
            <?php if (!empty($errors)): ?>
                <div class="error-list">
                    <strong>تحذيرات:</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <?php if ($deletedCount > 0 || session_status() === PHP_SESSION_DISABLED): ?>
                <p style="text-align: center; color: #28a745; margin-top: 15px;">
                    <strong>✓</strong> تم مسح جميع الجلسات بما في ذلك الجلسة الحالية
                </p>
            <?php endif; ?>
        </div>
        
        <div class="btn-container">
            <a href="index.php" class="btn">العودة إلى صفحة تسجيل الدخول</a>
        </div>
        
        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; text-align: center; color: #666; font-size: 0.9em;">
            <p>ملاحظة: سيحتاج جميع المستخدمين تسجيل الدخول مرة أخرى</p>
        </div>
    </div>
</body>
</html>