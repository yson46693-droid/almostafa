<?php
/**
 * Session Logger - معطل تماماً
 * هذا الملف يمنع أي تسجيل في session_debug.log
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/**
 * تسجيل معلومات الجلسة
 * معطل تماماً - لا يفعل شيئاً
 */
function logSessionInfo($message, $data = []) {
    // معطل تماماً - لا تسجيل
    return;
}

/**
 * تسجيل فشل الجلسة
 * معطل تماماً - لا يفعل شيئاً
 */
function logSessionFailure($message, $data = []) {
    // معطل تماماً - لا تسجيل
    return;
}

/**
 * تسجيل 401 من API
 * معطل تماماً - لا يفعل شيئاً
 */
function logApi401($endpoint, $message, $data = []) {
    // معطل تماماً - لا تسجيل
    return;
}
