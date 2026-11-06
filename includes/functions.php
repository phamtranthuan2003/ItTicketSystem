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
    return $base . app_path($path);
}

function app_path(string $path = ''): string
{
    return rtrim(APP_BASE_PATH, '/') . '/' . ltrim($path, '/');
}

function ticket_departments(): array
{
    return [
        'BLD - Ban Lãnh Đạo',
        'IT - Phòng IT',
        'BPC - Ban Pháp Chế',
        'BKSNB - Ban Kiểm Soát Nội Bộ',
        'QLTK - Ban Quản Lý Thiết Kế',
        'BKT - Ban Kế Toán',
        'KTĐT - Ban Kinh Tế Đấu Thầu',
        'ĐTDA - Ban Đầu Tư Dự Án',
        'BHC - Ban Hành Chính',
        'BNS - Ban Nhân Sự',
        'BMC - Ban Marcom',
        'CT H&H',
    ];
}

function ticket_types(): array
{
    return [
        'pc' => 'PC',
        'laptop' => 'Laptop',
        'printer' => 'Máy in',
        'network' => 'Mạng Internet',
        'meeting_room' => 'Trực phòng họp',
    ];
}

function ticket_impact_scopes(): array
{
    return [
        'individual' => 'Cá nhân',
        'department' => 'Cả phòng',
        'company' => 'Toàn công ty',
    ];
}

function ticket_work_abilities(): array
{
    return [
        'can_work' => 'Vẫn làm được',
        'partial' => 'Một phần',
        'cannot_work' => 'Không làm được',
    ];
}

function calculate_ticket_priority(string $scope, string $workAbility, string $type, string $department, string $title, string $description): array
{
    $scopePoints = ['individual' => 0, 'department' => 3, 'company' => 6];
    $workPoints = ['can_work' => 0, 'partial' => 3, 'cannot_work' => 6];
    $score = ($scopePoints[$scope] ?? 0) + ($workPoints[$workAbility] ?? 0);
    $score += $type === 'network' ? 3 : (in_array($type, ['pc', 'laptop', 'printer'], true) ? 2 : 0);
    if (str_starts_with($department, 'IT -')) {
        $score += 2;
    }

    $text = mb_strtolower($title . ' ' . $description, 'UTF-8');
    $keywordGroups = [
        ['khẩn cấp', 'khẩn', 'gấp', 'urgent'],
        ['mất kết nối', 'mất mạng', 'gián đoạn', 'không truy cập', 'ngừng hoạt động'],
        ['virus', 'mã độc', 'ransomware', 'mất dữ liệu', 'rò rỉ dữ liệu'],
    ];
    $matchedGroups = 0;
    foreach ($keywordGroups as $keywords) {
        foreach ($keywords as $keyword) {
            if (mb_strpos($text, $keyword, 0, 'UTF-8') !== false) {
                $matchedGroups++;
                break;
            }
        }
    }
    $score += min(4, $matchedGroups * 2);

    return [
        'score' => $score,
        'priority' => $score >= 10 ? 'high' : ($score >= 5 ? 'medium' : 'low'),
    ];
}

function ticket_sla_minutes(string $priority): array
{
    return [
        'high' => ['response' => 15, 'resolution' => 120],
        'medium' => ['response' => 30, 'resolution' => 240],
        'low' => ['response' => 60, 'resolution' => 360],
    ][$priority] ?? throw new InvalidArgumentException('Invalid ticket priority.');
}

function ticket_sla_hours(string $priority): int
{
    return match ($priority) {
        'high' => 2,
        'medium' => 4,
        'low' => 6,
        default => 4,
    };
}

function client_ip_hash(): string
{
    return hash_hmac('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), APP_KEY);
}

function require_admin(): array
{
    if (empty($_SESSION['admin_id'])) {
        redirect(app_path('/admin/login.php'));
    }
    $stmt = db()->prepare('SELECT username, role FROM admins WHERE id = ? AND is_active = 1');
    $stmt->execute([(int)$_SESSION['admin_id']]);
    $account = $stmt->fetch();
    if (!$account) {
        unset($_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['admin_role']);
        redirect(app_path('/admin/login.php'));
    }
    $_SESSION['admin_username'] = (string)$account['username'];
    $_SESSION['admin_role'] = (string)$account['role'];
    return [
        'id' => (int)$_SESSION['admin_id'],
        'username' => (string)$account['username'],
        'role' => (string)$account['role'],
    ];
}

function require_admin_role(): array
{
    $user = require_admin();
    if (!in_array($user['role'], ['admin', 'super_admin'], true)) {
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
    if ($type === 'accounting') {
        return 'PC';
    }
    return [
        'pc' => 'PC',
        'laptop' => 'Laptop',
        'printer' => 'Máy in',
        'network' => 'Mạng Internet',
        'meeting_room' => 'Trực phòng họp',
        'it' => 'IT',
        'hr' => 'Nhân sự',
        'admin' => 'Hành chính',
        'other' => 'Khác',
    ][$type] ?? $type;
}

function ticket_priority_label(string $priority): string
{
    return [
        'low' => 'P3 — Thấp',
        'medium' => 'P2 — Trung bình',
        'high' => 'P1 — Cao',
    ][$priority] ?? $priority;
}

function page_header(string $title, bool $isAdmin = false): void
{
    $user = $isAdmin ? require_admin() : null;
    $titleEscaped = e($title);
    $pageClass = $isAdmin ? 'admin-app-page' : ($title === 'Gửi yêu cầu' ? 'request-page' : 'public-app-page');
    echo '<!doctype html><html lang="vi"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . $titleEscaped . ' | Everland Group</title>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap">';
    echo '<link rel="stylesheet" href="' . e(app_path('/assets/css/style.css?v=everland20261106-8')) . '"></head><body class="' . e($pageClass) . '" data-app-base="' . e(APP_BASE_PATH) . '">';
    echo '<header class="topbar' . ($isAdmin ? ' admin-topbar' : '') . '"><a class="brand" href="' . e(app_path('/')) . '" aria-label="Everland Group — Trang chủ">'
        . '<span class="brand-mark"><img src="' . e(app_path('/assets/img/everland-1668155348.png')) . '" alt=""></span>'
        . '<span class="brand-copy"><strong>EVERLAND GROUP</strong><small>HỆ THỐNG HỖ TRỢ NỘI BỘ</small></span></a>';
    if ($isAdmin && $user) {
        $currentPage = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $roleLabel = ['super_admin' => 'SUPER ADMIN', 'admin' => 'QUẢN TRỊ VIÊN', 'staff' => 'KỸ THUẬT VIÊN'][$user['role']] ?? 'QUẢN TRỊ';
        echo '<nav class="nav admin-nav" aria-label="Điều hướng quản trị"><span class="admin-nav-caption">QUẢN TRỊ</span><div class="admin-nav-links">';
        echo '<a class="admin-nav-link' . ($currentPage === 'index.php' ? ' is-active' : '') . '" href="' . e(app_path('/admin/')) . '"' . ($currentPage === 'index.php' ? ' aria-current="page"' : '') . '>'
            . '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="8" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="11" width="7" height="10" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/></svg><span>Tổng quan</span></a>';
        echo '<a class="admin-nav-link' . ($currentPage === 'tickets.php' || $currentPage === 'ticket.php' || $currentPage === 'export.php' ? ' is-active' : '') . '" href="' . e(app_path('/admin/tickets.php')) . '"' . (in_array($currentPage, ['tickets.php', 'ticket.php', 'export.php'], true) ? ' aria-current="page"' : '') . '>'
            . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3.75h8l4 4V20a1 1 0 0 1-1 1H7a2 2 0 0 1-2-2V5.75a2 2 0 0 1 2-2Z"/><path d="M14 4v5h5M8.5 13h7M8.5 16.5h7"/></svg><span>Tickets</span><span id="new-ticket-badge" class="badge" title="Ticket mới chưa xử lý" aria-label="Ticket mới chưa xử lý" hidden>0</span></a>';
        if (in_array($user['role'], ['admin', 'super_admin'], true)) {
            echo '<a class="admin-nav-link' . ($currentPage === 'users.php' ? ' is-active' : '') . '" href="' . e(app_path('/admin/users.php')) . '"' . ($currentPage === 'users.php' ? ' aria-current="page"' : '') . '>'
                . '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M3 20v-1.5a6 6 0 0 1 12 0V20M16 5.2a3.5 3.5 0 0 1 0 6.6M18 14a4.5 4.5 0 0 1 3 4.25V20"/></svg><span>Tài khoản</span></a>';
        }
        echo '</div><div class="admin-user-menu"><span class="admin-user-avatar" aria-hidden="true">' . e(mb_strtoupper(mb_substr($user['username'], 0, 1, 'UTF-8'), 'UTF-8')) . '</span><span class="admin-user-copy"><strong>' . e($user['username']) . '</strong><small>' . e($roleLabel) . '</small></span>'
            . '<form method="post" action="' . e(app_path('/admin/logout.php')) . '" class="logout-form">' . csrf_field() .
            '<button type="submit" class="nav-logout" aria-label="Đăng xuất khỏi tài khoản ' . e($user['username']) . '" title="Đăng xuất"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/></svg><span>Đăng xuất</span></button></form></div></nav>';
    } else {
        echo '<nav class="nav"><a href="' . e(app_path('/')) . '">Gửi yêu cầu</a><a href="' . e(app_path('/track.php')) . '">Tra cứu ticket</a></nav>';
    }
    echo '</header><main class="container"><h1>' . $titleEscaped . '</h1>';
    if ($isAdmin) {
        echo '<script defer src="' . e(app_path('/assets/js/admin.js')) . '"></script>';
    }
}

function page_footer(): void
{
    echo '</main><footer class="footer"><img src="' . e(app_path('/assets/img/everland-1668155348.png')) . '" alt="Everland Group">'
        . '<span>EVERLAND GROUP <small>Hệ thống quản lý yêu cầu nội bộ</small></span></footer></body></html>';
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
