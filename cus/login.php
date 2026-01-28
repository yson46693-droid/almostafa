<?php
/**
 * صفحة تسجيل الدخول للعملاء المحليين
 * للمدير والمحاسب فقط
 */

// بدء الجلسة مع معالجة الأخطاء
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';

$error = '';
$success = '';

// إذا كان المستخدم مسجل دخول بالفعل وله صلاحية، توجهه إلى index.php
if (isLoggedIn()) {
    $currentUser = getCurrentUser();
    if ($currentUser && in_array(strtolower($currentUser['role']), ['manager', 'accountant', 'developer'])) {
        header('Location: index.php');
        exit;
    }
}

// معالجة تسجيل الدخول
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $rememberMe = isset($_POST['remember_me']) && $_POST['remember_me'] == '1';
    
    if (empty($username) || empty($password)) {
        $error = 'يرجى إدخال اسم المستخدم وكلمة المرور';
    } else {
        try {
            $result = login($username, $password, $rememberMe);
            
            if ($result['success']) {
                $user = $result['user'];
                $userRole = strtolower($user['role'] ?? '');
                
                // التحقق من أن المستخدم هو مدير أو محاسب
                if (!in_array($userRole, ['manager', 'accountant', 'developer'])) {
                    // تسجيل الخروج إذا لم يكن لديه الصلاحية
                    session_destroy();
                    $error = 'غير مصرح لك بالدخول إلى هذه الصفحة. هذه الصفحة متاحة للمدير والمحاسب فقط.';
                } else {
                    // تسجيل الدخول ناجح - إعادة التوجيه إلى index.php
                    header('Location: index.php');
                    exit;
                }
            } else {
                $error = $result['message'] ?? 'فشل تسجيل الدخول';
            }
        } catch (Throwable $e) {
            error_log('Login error in cus/login.php: ' . $e->getMessage());
            $error = 'حدث خطأ أثناء تسجيل الدخول. يرجى المحاولة مرة أخرى.';
        }
    }
}

// تحديد ASSETS_URL إذا لم يكن معرفاً
if (!defined('ASSETS_URL')) {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $parsedUri = parse_url($requestUri);
    $path = $parsedUri['path'] ?? '';
    $pathParts = explode('/', trim($path, '/'));
    
    // البحث عن cus في المسار
    $basePath = '';
    foreach ($pathParts as $part) {
        if ($part === 'cus') {
            break;
        }
        if ($part && $part !== 'index.php' && $part !== 'login.php') {
            $basePath .= '/' . $part;
        }
    }
    
    define('ASSETS_URL', ($basePath ? $basePath : '') . '/assets/');
}

if (!defined('APP_NAME')) {
    define('APP_NAME', 'نظام البركة');
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f1c40f">
    <title>تسجيل الدخول - العملاء المحليين - <?php echo APP_NAME; ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet" crossorigin="anonymous">
    
    <style>
        body {
            background: linear-gradient(135deg, #f4d03f 0%, #f1c40f 50%, #f4d03f 100%);
            background-size: 400% 400%;
            animation: gradientShift 8s ease infinite;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        
        .login-card {
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        }
        
        .login-card .card-body {
            background: white;
            border-radius: 15px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-11 col-sm-10 col-md-8 col-lg-6 col-xl-5">
                <div class="card shadow-lg border-0 login-card">
                    <div class="card-body p-4 p-md-5">
                        <div class="text-center mb-4">
                            <i class="bi bi-shield-lock-fill text-primary" style="font-size: 3rem;"></i>
                            <h3 class="mt-3 mb-1">تسجيل الدخول</h3>
                            <p class="text-muted">العملاء المحليين - للمدير والمحاسب فقط</p>
                        </div>
                        
                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                <?php echo htmlspecialchars($error); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="bi bi-check-circle-fill me-2"></i>
                                <?php echo htmlspecialchars($success); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="login">
                            
                            <div class="mb-3">
                                <label for="username" class="form-label">
                                    <i class="bi bi-person-fill me-2"></i>
                                    اسم المستخدم
                                </label>
                                <input type="text" class="form-control" id="username" name="username" 
                                       placeholder="أدخل اسم المستخدم" required autofocus>
                            </div>
                            
                            <div class="mb-3">
                                <label for="password" class="form-label">
                                    <i class="bi bi-key-fill me-2"></i>
                                    كلمة المرور
                                </label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="password" name="password" 
                                           placeholder="أدخل كلمة المرور" required>
                                    <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                        <i class="bi bi-eye" id="eyeIcon"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="remember_me" name="remember_me" value="1">
                                <label class="form-check-label" for="remember_me">
                                    <i class="bi bi-bookmark-check me-2"></i>
                                    تذكرني
                                </label>
                            </div>
                            
                            <div class="d-grid gap-2 mb-3">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="bi bi-box-arrow-in-right me-2"></i>
                                    تسجيل الدخول
                                </button>
                            </div>
                        </form>
                        
                        <div class="text-center mt-4">
                            <small class="text-muted">
                                <i class="bi bi-info-circle me-1"></i>
                                شركة البركة © <?php echo date('Y'); ?>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    
    <script>
        // تبديل إظهار/إخفاء كلمة المرور
        document.getElementById('togglePassword')?.addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const eyeIcon = document.getElementById('eyeIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                eyeIcon.classList.remove('bi-eye');
                eyeIcon.classList.add('bi-eye-slash');
            } else {
                passwordInput.type = 'password';
                eyeIcon.classList.remove('bi-eye-slash');
                eyeIcon.classList.add('bi-eye');
            }
        });
    </script>
</body>
</html>
