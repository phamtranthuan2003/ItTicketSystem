<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/functions.php';

if (!empty($_SESSION['admin_id'])) redirect(app_path('/admin/'));
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $loginKey = hash_hmac('sha256', strtolower($username) . '|' . client_ip_hash(), APP_KEY);
    try {
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare('INSERT IGNORE INTO auth_attempts (login_key, failed_attempts, locked_until) VALUES (?, 0, NULL)')->execute([$loginKey]);
        $attemptStmt = $pdo->prepare('SELECT failed_attempts, locked_until FROM auth_attempts WHERE login_key = ? FOR UPDATE');
        $attemptStmt->execute([$loginKey]);
        $attempt = $attemptStmt->fetch();
        $locked = !empty($attempt['locked_until']) && strtotime($attempt['locked_until']) > time();
        $userStmt = $pdo->prepare('SELECT id, username, password_hash, role FROM admins WHERE username = ? AND is_active = 1 LIMIT 1');
        $userStmt->execute([$username]);
        $user = $userStmt->fetch();

        if ($locked || !$user || !password_verify($password, $user['password_hash'])) {
            $failedCount = $locked ? (int)$attempt['failed_attempts'] : (int)$attempt['failed_attempts'] + 1;
            $lockedUntil = $locked
                ? $attempt['locked_until']
                : ($failedCount >= 5 ? date('Y-m-d H:i:s', time() + 900) : null);
            $pdo->prepare('UPDATE auth_attempts SET failed_attempts = ?, locked_until = ? WHERE login_key = ?')->execute([$failedCount, $lockedUntil, $loginKey]);
            $pdo->commit();
            $error = $locked ? 'Tài khoản đang bị khóa tạm thời. Vui lòng thử lại sau 15 phút.' : 'Tên đăng nhập hoặc mật khẩu không đúng.';
        } else {
            $pdo->prepare('DELETE FROM auth_attempts WHERE login_key = ?')->execute([$loginKey]);
            $pdo->prepare('UPDATE admins SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
            $cursor = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM tickets')->fetchColumn();
            $pdo->commit();
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int)$user['id'];
            $_SESSION['admin_username'] = $user['username'];
            $_SESSION['admin_role'] = $user['role'];
            $_SESSION['ticket_cursor'] = $cursor;
            redirect(app_path('/admin/'));
        }
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        error_log('Admin login error: ' . $exception->getMessage());
        $error = 'Không thể đăng nhập lúc này. Vui lòng thử lại sau.';
    }
}

?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Đăng nhập quản trị | Everland Group</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap">
    <link rel="stylesheet" href="<?= e(app_path('/assets/css/style.css?v=everland20261106-8')) ?>">
</head>
<body class="admin-login-page">
    <main class="admin-login-main">
        <section class="admin-login-card" aria-labelledby="admin-login-title">
            <img class="admin-login-logo" src="<?= e(app_path('/assets/img/everland-1668155348.png')) ?>" alt="Everland Group">
            <p class="admin-login-eyebrow">CỔNG QUẢN TRỊ</p>
            <h1 id="admin-login-title">Đăng nhập</h1>
            <p class="admin-login-subtitle">Hệ thống quản lý yêu cầu nội bộ</p>
            <?php if ($error !== ''): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
            <form method="post" class="admin-login-form">
                <?= csrf_field() ?>
                <label for="admin-username">Tên đăng nhập</label>
                <input id="admin-username" name="username" required autocomplete="username" maxlength="100" autofocus>
                <label for="admin-password">Mật khẩu</label>
                <input id="admin-password" type="password" name="password" required autocomplete="current-password">
                <button type="submit">Đăng nhập</button>
            </form>
        </section>
    </main>
</body>
</html>
