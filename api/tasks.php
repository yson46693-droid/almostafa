<?php
/**
 * API: الحصول على المهام كـ JSON للتحديث التلقائي
 */

define('ACCESS_ALLOWED', true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method Not Allowed'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/auth.php';
    
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Unauthorized'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $currentUser = getCurrentUser();
    if (!$currentUser) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'User not found'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $db = db();
    
    // قراءة معاملات الفلترة (نفس منطق الصفحة)
    $pageNum = isset($_GET['p']) ? max(1, (int) $_GET['p']) : 1;
    $perPage = 20;
    $offset = ($pageNum - 1) * $perPage;
    
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
    $priorityFilter = isset($_GET['priority']) ? trim($_GET['priority']) : '';
    $assignedFilter = isset($_GET['assigned']) ? (int) $_GET['assigned'] : 0;
    
    // دالة safe string
    function apiTasksSafeString($value) {
        if ($value === null || (!is_scalar($value) && $value !== '')) {
            return '';
        }
        $value = (string) $value;
        if ($value === '') {
            return '';
        }
        return trim($value);
    }
    
    $search = apiTasksSafeString($search);
    $statusFilter = apiTasksSafeString($statusFilter);
    $priorityFilter = apiTasksSafeString($priorityFilter);
    
    $whereConditions = [];
    $params = [];
    
    if ($search !== '') {
        $whereConditions[] = '(t.title LIKE ? OR t.description LIKE ?)';
        $searchParam = '%' . $search . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    if ($statusFilter !== '') {
        $whereConditions[] = 't.status = ?';
        $params[] = $statusFilter;
    } else {
        $whereConditions[] = "t.status != 'cancelled'";
    }
    
    if ($priorityFilter !== '' && in_array($priorityFilter, ['low', 'normal', 'high', 'urgent'], true)) {
        $whereConditions[] = 't.priority = ?';
        $params[] = $priorityFilter;
    }
    
    if ($assignedFilter > 0) {
        $whereConditions[] = 't.assigned_to = ?';
        $params[] = $assignedFilter;
    }
    
    $whereClause = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // حساب الإجمالي
    $totalRow = $db->queryOne('SELECT COUNT(*) AS total FROM tasks t ' . $whereClause, $params);
    $totalTasks = isset($totalRow['total']) ? (int) $totalRow['total'] : 0;
    
    // جلب المهام
    $taskSql = "SELECT t.*, 
        uAssign.full_name AS assigned_to_name,
        uCreate.full_name AS created_by_name,
        p.name AS product_name
    FROM tasks t
    LEFT JOIN users uAssign ON t.assigned_to = uAssign.id
    LEFT JOIN users uCreate ON t.created_by = uCreate.id
    LEFT JOIN products p ON t.product_id = p.id
    $whereClause
    ORDER BY 
        CASE t.priority
            WHEN 'urgent' THEN 1
            WHEN 'high' THEN 2
            WHEN 'normal' THEN 3
            WHEN 'low' THEN 4
            ELSE 5
        END,
        COALESCE(t.due_date, '9999-12-31') ASC,
        t.created_at DESC
    LIMIT ? OFFSET ?";
    
    $queryParams = array_merge($params, [$perPage, $offset]);
    $tasks = $db->query($taskSql, $queryParams);
    
    // معالجة المهام (استخراج العمال من notes)
    foreach ($tasks as &$task) {
        $notes = $task['notes'] ?? '';
        $allWorkers = [];
        
        // محاولة استخراج IDs من notes
        if (preg_match('/\[ASSIGNED_WORKERS_IDS\]:\s*([0-9,]+)/', $notes, $matches)) {
            $workerIds = array_filter(array_map('intval', explode(',', $matches[1])));
            if (!empty($workerIds)) {
                $placeholders = implode(',', array_fill(0, count($workerIds), '?'));
                $workers = $db->query(
                    "SELECT id, full_name FROM users WHERE id IN ($placeholders) ORDER BY full_name",
                    $workerIds
                );
                foreach ($workers as $worker) {
                    $allWorkers[] = $worker['full_name'];
                }
            }
        }
        
        // إذا لم نجد عمال من notes، استخدم assigned_to
        if (empty($allWorkers) && !empty($task['assigned_to_name'])) {
            $allWorkers[] = $task['assigned_to_name'];
        }
        
        $task['all_workers'] = $allWorkers;
        $task['workers_count'] = count($allWorkers);
        
        // تنظيف البيانات الحساسة
        unset($task['notes']); // إزالة notes من الاستجابة
    }
    unset($task);
    
    // حساب الإحصائيات
    $stats = [
        'total' => $totalTasks,
        'pending' => 0,
        'received' => 0,
        'in_progress' => 0,
        'completed' => 0,
        'cancelled' => 0
    ];
    
    $statsQuery = "SELECT status, COUNT(*) as count FROM tasks WHERE status != 'cancelled' GROUP BY status";
    $statsRows = $db->query($statsQuery);
    foreach ($statsRows as $row) {
        $status = $row['status'] ?? '';
        $count = (int) ($row['count'] ?? 0);
        if (isset($stats[$status])) {
            $stats[$status] = $count;
        }
    }
    
    // إضافة timestamp للتحديث
    $timestamp = time();
    
    echo json_encode([
        'success' => true,
        'data' => [
            'tasks' => $tasks,
            'stats' => $stats,
            'total' => $totalTasks,
            'page' => $pageNum,
            'per_page' => $perPage,
            'timestamp' => $timestamp
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Throwable $e) {
    error_log('Tasks API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error'
    ], JSON_UNESCAPED_UNICODE);
}
