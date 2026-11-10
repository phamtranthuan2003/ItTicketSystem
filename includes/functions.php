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
    if (APP_BASE_URL !== '') {
        return rtrim(APP_BASE_URL, '/') . '/' . ltrim($path, '/');
    }
    return app_path($path);
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
        'other' => 'Khác',
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
    $scopeScore = $scopePoints[$scope] ?? 0;
    $workScore = $workPoints[$workAbility] ?? 0;
    $typeScore = $type === 'network' ? 3 : (in_array($type, ['pc', 'laptop', 'printer'], true) ? 2 : 0);
    $departmentScore = str_starts_with($department, 'IT -') ? 2 : 0;
    $departmentLabel = $departmentScore > 0
        ? 'Phòng ban IT: ' . $department
        : 'Phòng ban không phải IT: ' . $department;
    $breakdown = [
        ['label' => 'Phạm vi ảnh hưởng: ' . (ticket_impact_scopes()[$scope] ?? $scope), 'points' => $scopeScore],
        ['label' => 'Khả năng làm việc: ' . (ticket_work_abilities()[$workAbility] ?? $workAbility), 'points' => $workScore],
        ['label' => 'Loại yêu cầu: ' . (ticket_type_label($type) ?: $type), 'points' => $typeScore],
        ['label' => $departmentLabel, 'points' => $departmentScore],
    ];

    $text = mb_strtolower($title . ' ' . $description, 'UTF-8');
    $keywordGroups = [
        ['label' => 'Khẩn cấp', 'keywords' => ['khẩn cấp', 'khẩn', 'gấp', 'urgent']],
        ['label' => 'Gián đoạn/kết nối', 'keywords' => ['mất kết nối', 'mất mạng', 'gián đoạn', 'không truy cập', 'ngừng hoạt động']],
        ['label' => 'Bảo mật/mất dữ liệu', 'keywords' => ['virus', 'mã độc', 'ransomware', 'mất dữ liệu', 'rò rỉ dữ liệu']],
    ];
    $matchedGroups = [];
    foreach ($keywordGroups as $group) {
        foreach ($group['keywords'] as $keyword) {
            if (mb_strpos($text, $keyword, 0, 'UTF-8') !== false) {
                $matchedGroups[] = [
                    'label' => $group['label'],
                    'keyword' => $keyword,
                ];
                break;
            }
        }
    }
    $keywordScore = min(4, count($matchedGroups) * 2);
    $matchedKeywords = array_map(
        static fn(array $group): string => $group['label'] . ': ' . $group['keyword'],
        $matchedGroups
    );
    $breakdown[] = [
        'label' => 'Từ khóa trong tiêu đề/mô tả: ' . ($matchedKeywords ? implode(', ', $matchedKeywords) : 'không phát hiện'),
        'points' => $keywordScore,
    ];
    $score = $scopeScore + $workScore + $typeScore + $departmentScore + $keywordScore;

    return [
        'score' => $score,
        'priority' => $score >= 10 ? 'high' : ($score >= 5 ? 'medium' : 'low'),
        'breakdown' => $breakdown,
        'matched_keyword_groups' => $matchedGroups,
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

function add_business_minutes(DateTimeInterface $start, int $minutes): DateTimeImmutable
{
    if ($minutes < 0) {
        throw new InvalidArgumentException('Business minutes cannot be negative.');
    }

    $current = DateTimeImmutable::createFromInterface($start);
    $remainingSeconds = $minutes * 60;
    if ($remainingSeconds === 0) {
        return $current;
    }

    while (true) {
        $dayOfWeek = (int)$current->format('N');
        if ($dayOfWeek === 7) {
            $current = $current->modify('+1 day')->setTime(8, 0);
            continue;
        }

        $opensAt = $current->setTime(8, 0);
        $closesAt = $dayOfWeek === 6 ? $current->setTime(12, 0) : $current->setTime(17, 30);
        if ($current < $opensAt) {
            $current = $opensAt;
        } elseif ($current >= $closesAt) {
            $current = $current->modify('+1 day')->setTime(8, 0);
            continue;
        }

        $availableSeconds = $closesAt->getTimestamp() - $current->getTimestamp();
        if ($remainingSeconds <= $availableSeconds) {
            return $current->modify('+' . $remainingSeconds . ' seconds');
        }

        $remainingSeconds -= $availableSeconds;
        $current = $closesAt;
    }
}

function ticket_sla_deadlines(DateTimeInterface $start, string $priority): array
{
    $sla = ticket_sla_minutes($priority);
    return [
        'response' => add_business_minutes($start, $sla['response']),
        'resolution' => add_business_minutes($start, $sla['resolution']),
    ];
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

function permission_catalog(): array
{
    return [
        'tickets.view' => [
            'group' => 'Ticket',
            'label' => 'Xem danh sách và chi tiết ticket',
            'description' => 'Truy cập tổng quan, danh sách và nội dung ticket.',
        ],
        'tickets.update' => [
            'group' => 'Ticket',
            'label' => 'Cập nhật trạng thái, ưu tiên và ghi chú',
            'description' => 'Cập nhật thông tin xử lý giới hạn của ticket.',
        ],
        'tickets.manage' => [
            'group' => 'Ticket',
            'label' => 'Chỉnh sửa đầy đủ ticket và gán người xử lý',
            'description' => 'Sửa thông tin người gửi, nội dung, loại yêu cầu và người được giao.',
        ],
        'tickets.delete' => [
            'group' => 'Ticket',
            'label' => 'Xóa ticket',
            'description' => 'Xóa ticket và các tệp đính kèm liên quan.',
        ],
        'tickets.export' => [
            'group' => 'Ticket',
            'label' => 'Xuất danh sách ticket',
            'description' => 'Tải dữ liệu ticket dưới dạng CSV.',
        ],
        'accounts.view' => [
            'group' => 'Tài khoản',
            'label' => 'Xem và mở trang quản lý tài khoản',
            'description' => 'Xem danh sách tài khoản trong hệ thống.',
        ],
        'accounts.create' => [
            'group' => 'Tài khoản',
            'label' => 'Tạo tài khoản',
            'description' => 'Tạo tài khoản mới; chỉ thuanpt được chọn vai trò.',
        ],
        'accounts.manage_password' => [
            'group' => 'Tài khoản',
            'label' => 'Đổi mật khẩu tài khoản thường',
            'description' => 'Không cho phép đổi mật khẩu tài khoản thuanpt.',
        ],
        'accounts.delete' => [
            'group' => 'Tài khoản',
            'label' => 'Xóa tài khoản thường',
            'description' => 'Không cho phép xóa tài khoản thuanpt.',
        ],
    ];
}

function role_permissions(string $role): array
{
    $catalog = permission_catalog();
    if ($role === 'super_admin') {
        return array_merge(array_keys($catalog), ['accounts.assign_roles', 'permissions.manage']);
    }
    if (!in_array($role, ['admin', 'staff'], true)) {
        return [];
    }

    static $permissionsByRole = [];
    if (!array_key_exists($role, $permissionsByRole)) {
        $stmt = db()->prepare('SELECT permission FROM role_permissions WHERE role = ? AND is_granted = 1');
        $stmt->execute([$role]);
        $permissionsByRole[$role] = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    return array_values(array_intersect($permissionsByRole[$role], array_keys($catalog)));
}

function has_permission(array $user, string $permission): bool
{
    if (in_array($permission, ['accounts.assign_roles', 'permissions.manage'], true)) {
        return (string)($user['role'] ?? '') === 'super_admin'
            && strcasecmp((string)($user['username'] ?? ''), 'thuanpt') === 0;
    }

    if (!in_array($permission, role_permissions((string)($user['role'] ?? '')), true)) {
        return false;
    }

    return true;
}

function require_permission(string $permission): array
{
    $user = require_admin();
    if (!has_permission($user, $permission)) {
        http_response_code(403);
        exit('Bạn không có quyền thực hiện thao tác này.');
    }
    return $user;
}

function require_admin_role(): array
{
    return require_permission('accounts.view');
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
        'low' => 'Thấp',
        'medium' => 'Trung bình',
        'high' => 'Cao',
    ][$priority] ?? $priority;
}

function page_header(string $title, bool $isAdmin = false, bool $showPageTitle = true): void
{
    $user = $isAdmin ? require_admin() : null;
    $titleEscaped = e($title);
    $pageClass = $isAdmin ? 'admin-app-page' : ($title === 'Gửi yêu cầu' ? 'request-page' : 'public-app-page');
    echo '<!doctype html><html lang="vi"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . $titleEscaped . ' | Everland Group</title>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap">';
    echo '<link rel="stylesheet" href="' . e(app_path('/assets/css/style.css?v=everland20261106-10')) . '"></head><body class="' . e($pageClass) . '" data-app-base="' . e(APP_BASE_PATH) . '">';
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
        if (has_permission($user, 'accounts.view')) {
            echo '<a class="admin-nav-link' . ($currentPage === 'users.php' ? ' is-active' : '') . '" href="' . e(app_path('/admin/users.php')) . '"' . ($currentPage === 'users.php' ? ' aria-current="page"' : '') . '>'
                . '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M3 20v-1.5a6 6 0 0 1 12 0V20M16 5.2a3.5 3.5 0 0 1 0 6.6M18 14a4.5 4.5 0 0 1 3 4.25V20"/></svg><span>Tài khoản</span></a>';
        }
        if (has_permission($user, 'permissions.manage')) {
            echo '<a class="admin-nav-link' . ($currentPage === 'permissions.php' ? ' is-active' : '') . '" href="' . e(app_path('/admin/permissions.php')) . '"' . ($currentPage === 'permissions.php' ? ' aria-current="page"' : '') . '>'
                . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 6v5c0 5 3.4 8.3 8 10 4.6-1.7 8-5 8-10V6l-8-3Z"/><path d="m9 12 2 2 4-4"/></svg><span>Phân quyền</span></a>';
        }
        echo '</div><div class="admin-user-menu"><span class="admin-user-avatar" aria-hidden="true">' . e(mb_strtoupper(mb_substr($user['username'], 0, 1, 'UTF-8'), 'UTF-8')) . '</span><span class="admin-user-copy"><strong>' . e($user['username']) . '</strong><small>' . e($roleLabel) . '</small></span>'
            . '<form method="post" action="' . e(app_path('/admin/logout.php')) . '" class="logout-form">' . csrf_field() .
            '<button type="submit" class="nav-logout" aria-label="Đăng xuất khỏi tài khoản ' . e($user['username']) . '" title="Đăng xuất"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/></svg><span>Đăng xuất</span></button></form></div></nav>';
    } else {
        echo '<nav class="nav"><a href="' . e(app_path('/')) . '">Gửi yêu cầu</a><a href="' . e(app_path('/track.php')) . '">Tra cứu ticket</a></nav>';
    }
    echo '</header><main class="container">';
    if ($showPageTitle) {
        echo '<h1>' . $titleEscaped . '</h1>';
    } else {
        echo '<h1 class="visually-hidden">' . $titleEscaped . '</h1>';
    }
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
