<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/functions.php';
require_admin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(app_path('/admin/'));
}
verify_csrf();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], '', (bool)$params['secure'], (bool)$params['httponly']);
}
session_destroy();
redirect(app_path('/admin/login.php'));
