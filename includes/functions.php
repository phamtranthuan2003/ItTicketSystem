<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function app_url(string $path = ''): string
{
    $base = APP_BASE_URL !== '' ? rtrim(APP_BASE_URL, '/') : '';
    return $base . '/ticket/' . ltrim($path, '/');
}

function client_ip_hash(): string
{
    return hash_hmac('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), APP_KEY);
}

function require_admin(): array
{
    if (empty($_SESSION['admin_id'])) {
        redirect('/ticket/admin/login.php');
    }
    return [
        'id' => (int)$_SESSION['admin_id'],
        'username' => (string)$_SESSION['admin_username'],
        'role' => (string)$_SESSION['admin_role'],
    ];
}

function require_admin_role(): array
{
    $user = require_admin();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        exit('Bạn không có quyền thực hiện thao tác này.');
    }
    return $user;
}

function add_ticket_history(int $ticketId, ?int $adminId, string $action, string $details): void
{
    $stmt = db()->prepare(
        'INSERT INTO ticket_history (ticket_id, admin_id, action, details) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$ticketId, $adminId, $action, $details]);
}

function ticket_status_label(string $status): string
{
    return [
        'new' => 'Mới',
        'in_progress' => 'Đang xử lý',
        'completed' => 'Hoàn thành',
        'rejected' => 'Từ chối',
    ][$status] ?? $status;
}

function ticket_type_label(string $type): string
{
    return [
        'it' => 'IT',
        'hr' => 'Nhân sự',
        'admin' => 'Hành chính',
        'accounting' => 'Kế toán',
        'other' => 'Khác',
    ][$type] ?? $type;
}

function ticket_priority_label(string $priority): string
{
    return ['low' => 'Thấp', 'medium' => 'Trung bình', 'high' => 'Cao'][$priority] ?? $priority;
}

function page_header(string $title, bool $isAdmin = false): void
{
    $user = $isAdmin ? require_admin() : null;
    $titleEscaped = e($title);
    echo '<!doctype html><html lang="vi"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . $titleEscaped . ' | ' . e(APP_NAME) . '</title>';
    echo '<link rel="stylesheet" href="/ticket/assets/css/style.css"></head><body>';
    echo '<header class="topbar"><a class="brand" href="/ticket/">' . e(APP_NAME) . '</a>';
    if ($isAdmin && $user) {
        echo '<nav class="nav"><a href="/ticket/admin/">Tổng quan</a><a href="/ticket/admin/tickets.php">Tickets <span id="new-ticket-badge" class="badge" hidden>0</span></a>';
        if ($user['role'] === 'admin') {
            echo '<a href="/ticket/admin/users.php">Tài khoản</a>';
        }
        echo '<form method="post" action="/ticket/admin/logout.php" class="logout-form">' . csrf_field() .
            '<button type="submit" class="nav-logout">Đăng xuất (' . e($user['username']) . ')</button></form></nav>';
    } else {
        echo '<nav class="nav"><a href="/ticket/">Gửi yêu cầu</a><a href="/ticket/track.php">Tra cứu ticket</a></nav>';
    }
    echo '</header><main class="container"><h1>' . $titleEscaped . '</h1>';
    if ($isAdmin) {
        echo '<script defer src="/ticket/assets/js/admin.js"></script>';
    }
}

function page_footer(): void
{
    echo '</main><footer class="footer">' . e(APP_NAME) . '</footer></body></html>';
}

function admin_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function show_flash(): void
{
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        echo '<div class="alert ' . e($flash['type']) . '">' . e($flash['message']) . '</div>';
    }
}
