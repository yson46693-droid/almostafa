<?php
/**
 * مساعد إدارة الإصدار
 * يتحقق من التعديلات ويحدث الإصدار تلقائياً
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';

/**
 * التحقق من التعديلات وتحديث الإصدار إذا لزم الأمر
 * @param bool $forceIncrement فرض تحديث الإصدار حتى لو لم تكن هناك تعديلات
 * @return string رقم الإصدار الحالي
 */
function checkAndUpdateVersion(bool $forceIncrement = false): string {
    $versionFile = __DIR__ . '/../version.json';
    $lastCheckFile = __DIR__ . '/../storage/last_version_check.txt';
    $deployTriggerFile = __DIR__ . '/../storage/deploy_trigger.txt';
    
    // إنشاء مجلد storage إذا لم يكن موجوداً
    $storageDir = __DIR__ . '/../storage';
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0755, true);
    }
    
    // التحقق من وجود ملف trigger للـ deploy (يمكن إنشاؤه عند رفع تحديثات من GitHub)
    if (file_exists($deployTriggerFile)) {
        $triggerTime = (int)@file_get_contents($deployTriggerFile);
        $lastVersionUpdate = 0;
        if (file_exists($versionFile)) {
            try {
                $versionData = json_decode(file_get_contents($versionFile), true);
                $lastUpdated = $versionData['last_updated'] ?? '';
                if (!empty($lastUpdated)) {
                    $lastVersionUpdate = strtotime($lastUpdated);
                }
            } catch (Exception $e) {
                // تجاهل الخطأ
            }
        }
        
        // إذا كان trigger أحدث من آخر تحديث للإصدار، فرض التحديث
        if ($triggerTime > $lastVersionUpdate) {
            $forceIncrement = true;
            // حذف ملف trigger بعد الاستخدام
            @unlink($deployTriggerFile);
        }
    }
    
    // قراءة آخر hash للملفات
    $lastHash = '';
    if (file_exists($lastCheckFile)) {
        $lastHash = trim(file_get_contents($lastCheckFile));
    }
    
    // حساب hash للملفات الرئيسية والملفات المهمة
    // استخدام طريقة أفضل للكشف عن التحديثات من GitHub
    $mainFiles = [
        __DIR__ . '/../templates/header.php',
        __DIR__ . '/../templates/footer.php',
        __DIR__ . '/../includes/config.php',
        __DIR__ . '/../includes/db.php',
        __DIR__ . '/../includes/auth.php',
        __DIR__ . '/../index.php',
    ];
    
    // حساب hash من محتوى الملفات + تاريخ آخر تعديل
    $currentHash = '';
    $maxMtime = 0; // آخر تاريخ تعديل
    
    foreach ($mainFiles as $file) {
        if (file_exists($file)) {
            // إضافة hash المحتوى
            $currentHash .= md5_file($file);
            // تتبع آخر تاريخ تعديل
            $mtime = filemtime($file);
            if ($mtime > $maxMtime) {
                $maxMtime = $mtime;
            }
        }
    }
    
    // إضافة آخر تاريخ تعديل للملفات الرئيسية في hash
    $currentHash .= $maxMtime;
    
    // التحقق من وجود ملف .git (Git repository) واستخدام commit hash إذا كان متوفراً
    $gitHeadFile = __DIR__ . '/../.git/HEAD';
    if (file_exists($gitHeadFile)) {
        $gitHead = trim(file_get_contents($gitHeadFile));
        // إذا كان HEAD يشير إلى branch، احصل على commit hash
        if (strpos($gitHead, 'ref:') === 0) {
            $refPath = trim(str_replace('ref:', '', $gitHead));
            $refFile = __DIR__ . '/../.git/' . $refPath;
            if (file_exists($refFile)) {
                $commitHash = trim(file_get_contents($refFile));
                $currentHash .= substr($commitHash, 0, 8); // أول 8 أحرف من commit hash
            }
        } else {
            // HEAD يحتوي على commit hash مباشرة
            $currentHash .= substr($gitHead, 0, 8);
        }
    }
    
    // إضافة hash من آخر 5 ملفات معدلة في مجلد includes
    $includesDir = __DIR__ . '/../includes';
    if (is_dir($includesDir)) {
        $includeFiles = glob($includesDir . '/*.php');
        if (is_array($includeFiles) && count($includeFiles) > 0) {
            usort($includeFiles, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            $recentIncludeFiles = array_slice($includeFiles, 0, 5);
            foreach ($recentIncludeFiles as $file) {
                if (file_exists($file)) {
                    $currentHash .= filemtime($file); // استخدام تاريخ التعديل فقط لتسريع العملية
                }
            }
        }
    }
    
    $currentHash = md5($currentHash);
    
    // إذا تغير hash أو تم فرض التحديث، حدث الإصدار
    if ($forceIncrement || $currentHash !== $lastHash) {
        // حفظ hash الحالي
        @file_put_contents($lastCheckFile, $currentHash);
        
        // تحديث الإصدار
        if (function_exists('incrementVersionBuild')) {
            return incrementVersionBuild();
        }
    }
    
    // إرجاع الإصدار الحالي
    if (function_exists('getCurrentVersion')) {
        return getCurrentVersion();
    }
    
    return 'v1.0';
}

/**
 * إنشاء ملف trigger لتحديث الإصدار عند رفع تحديثات من GitHub
 * يمكن استدعاء هذه الدالة من سكريبت deploy أو hook
 * @return bool نجح العملية أم لا
 */
function triggerVersionUpdate(): bool {
    $deployTriggerFile = __DIR__ . '/../storage/deploy_trigger.txt';
    $storageDir = dirname($deployTriggerFile);
    
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0755, true);
    }
    
    return @file_put_contents($deployTriggerFile, time(), LOCK_EX) !== false;
}

