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
    
    // حساب hash شامل لجميع الملفات المهمة مع مراقبة:
    // 1. محتوى الملفات (hash)
    // 2. حجم الملفات (bytes)
    // 3. عدد الأسطر في الملفات
    // 4. تاريخ آخر تعديل
    
    $baseDir = __DIR__ . '/..';
    $currentHash = '';
    $maxMtime = 0;
    
    // قائمة المجلدات والملفات المهمة للمراقبة
    $watchDirs = [
        'includes' => ['*.php'],
        'templates' => ['*.php'],
        'modules' => ['**/*.php'],
        'api' => ['*.php'],
        'assets/js' => ['*.js'],
        'assets/css' => ['*.css'],
    ];
    
    // الملفات الرئيسية (يتم مراقبتها دائماً)
    $mainFiles = [
        'index.php',
        'version.json',
    ];
    
    // دالة لحساب hash شامل للملف (محتوى + حجم + عدد الأسطر)
    $getFileHash = function($filePath) {
        if (!file_exists($filePath)) {
            return '';
        }
        
        $hash = '';
        
        // 1. Hash المحتوى
        $hash .= md5_file($filePath);
        
        // 2. حجم الملف (bytes)
        $fileSize = filesize($filePath);
        $hash .= $fileSize;
        
        // 3. عدد الأسطر في الملف
        $lineCount = 0;
        if (is_readable($filePath)) {
            $handle = @fopen($filePath, 'r');
            if ($handle) {
                while (!feof($handle)) {
                    fgets($handle);
                    $lineCount++;
                }
                fclose($handle);
            }
        }
        $hash .= $lineCount;
        
        // 4. تاريخ آخر تعديل
        $mtime = filemtime($filePath);
        $hash .= $mtime;
        
        return $hash;
    };
    
    // مراقبة الملفات الرئيسية
    foreach ($mainFiles as $mainFile) {
        $filePath = $baseDir . '/' . $mainFile;
        if (file_exists($filePath)) {
            $currentHash .= $getFileHash($filePath);
            $mtime = filemtime($filePath);
            if ($mtime > $maxMtime) {
                $maxMtime = $mtime;
            }
        }
    }
    
    // مراقبة الملفات في المجلدات المهمة
    // استخدام RecursiveDirectoryIterator للبحث بشكل أسرع
    foreach ($watchDirs as $dir => $patterns) {
        $dirPath = $baseDir . '/' . $dir;
        if (!is_dir($dirPath)) {
            continue;
        }
        
        foreach ($patterns as $pattern) {
            // تحويل pattern إلى regex للبحث بشكل أسرع
            $regexPattern = str_replace(['*', '**'], ['.*', '.*'], $pattern);
            $regexPattern = '/^' . str_replace('/', '\/', $regexPattern) . '$/';
            
            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dirPath, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                
                foreach ($iterator as $file) {
                    if ($file->isFile()) {
                        $relativePath = str_replace($dirPath . '/', '', $file->getPathname());
                        // التحقق من تطابق pattern
                        if (preg_match($regexPattern, $relativePath) || 
                            fnmatch($pattern, $relativePath, FNM_PATHNAME)) {
                            $filePath = $file->getPathname();
                            $currentHash .= $getFileHash($filePath);
                            $mtime = $file->getMTime();
                            if ($mtime > $maxMtime) {
                                $maxMtime = $mtime;
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                // في حالة الخطأ، استخدم glob كبديل
                $files = glob($dirPath . '/' . $pattern, GLOB_BRACE);
                if (is_array($files)) {
                    foreach ($files as $file) {
                        if (is_file($file)) {
                            $currentHash .= $getFileHash($file);
                            $mtime = filemtime($file);
                            if ($mtime > $maxMtime) {
                                $maxMtime = $mtime;
                            }
                        }
                    }
                }
            }
        }
    }
    
    // إضافة آخر تاريخ تعديل شامل
    $currentHash .= $maxMtime;
    
    // التحقق من Git commit hash إذا كان متوفراً
    $gitHeadFile = $baseDir . '/.git/HEAD';
    if (file_exists($gitHeadFile)) {
        $gitHead = trim(file_get_contents($gitHeadFile));
        if (strpos($gitHead, 'ref:') === 0) {
            $refPath = trim(str_replace('ref:', '', $gitHead));
            $refFile = $baseDir . '/.git/' . $refPath;
            if (file_exists($refFile)) {
                $commitHash = trim(file_get_contents($refFile));
                $currentHash .= substr($commitHash, 0, 12);
            }
        } else {
            $currentHash .= substr($gitHead, 0, 12);
        }
    }
    
    // حساب hash نهائي
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

/**
 * الحصول على الإصدار الحالي من version.json (عداد يدوي)
 * لا يتأثر بأي تحديثات تلقائية
 * @return string رقم الإصدار مع v في البداية (مثل: v1.9.3)
 */
function getCurrentVersion(): string {
    $versionFile = __DIR__ . '/../version.json';
    
    // قراءة الإصدار من version.json فقط
    if (file_exists($versionFile)) {
        try {
            $versionData = json_decode(file_get_contents($versionFile), true);
            if (isset($versionData['version']) && !empty($versionData['version'])) {
                $version = trim($versionData['version']);
                // إضافة v في البداية إذا لم تكن موجودة
                if (strpos($version, 'v') !== 0) {
                    $version = 'v' . $version;
                }
                return $version;
            }
        } catch (Exception $e) {
            // في حالة الخطأ، إرجاع إصدار افتراضي
            error_log('Error reading version.json: ' . $e->getMessage());
        }
    }
    
    // إصدار افتراضي في حالة عدم وجود الملف
    return 'v1.0.0';
}

