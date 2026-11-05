<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/functions.php';
$admin = require_admin_role();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'create') {
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $role = (string)($_POST['role'] ?? 'staff');
            if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $username) || strlen($password) < 12 || !in_array($role, ['admin', 'staff'], true)) {
                throw new InvalidArgumentException('Tên đăng nhập cần 3–100 ký tự hợp lệ và mật khẩu ít nhất 12 ký tự.');
            }
            $stmt = $pdo->prepare('INSERT INTO admins (username, password_hash, role) VALUES (?, ?, ?)');
            $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role]);
            admin_flash('success', 'Đã tạo tài khoản.');
        } elseif ($action === 'password') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $password = (string)($_POST['password'] ?? '');
            if ($userId < 1 || strlen($password) < 12) throw new InvalidArgumentException('Mật khẩu mới phải có ít nhất 12 ký tự.');
            $stmt = $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?');
            $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
            if (!$stmt->rowCount()) throw new InvalidArgumentException('Không tìm thấy tài khoản.');
            admin_flash('success', 'Đã đổi mật khẩu.');
        } elseif ($action === 'delete') {
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId === $admin['id']) throw new InvalidArgumentException('Không thể xóa tài khoản đang đăng nhập.');
            $target = $pdo->prepare('SELECT role FROM admins WHERE id = ?');
            $target->execute([$userId]);
            $targetRole = $target->fetchColumn();
            if ($targetRole === false) throw new InvalidArgumentException('Không tìm thấy tài khoản.');
            if ($targetRole === 'admin' && (int)$pdo->query("SELECT COUNT(*) FROM admins WHERE role = 'admin' AND is_active = 1")->fetchColumn() <= 1) {
                throw new InvalidArgumentException('Không thể xóa admin cuối cùng.');
            }
            $pdo->prepare('DELETE FROM admins WHERE id = ?')->execute([$userId]);
            admin_flash('success', 'Đã xóa tài khoản.');
        } else {
            throw new InvalidArgumentException('Thao tác không hợp lệ.');
        }
    } catch (InvalidArgumentException $exception) {
        admin_flash('error', $exception->getMessage());
    } catch (PDOException $exception) {
        error_log('Admin account error: ' . $exception->getMessage());
        admin_flash('error', 'Không thể lưu tài khoản. Tên đăng nhập có thể đã tồn tại.');
    }
    redirect('/ticket/admin/users.php');
}

$users = $pdo->query('SELECT id, username, role, created_at, last_login_at FROM admins ORDER BY id')->fetchAll();
page_header('Quản lý tài khoản', true);
show_flash();
?>
<section class="card">
    <h2>Thêm tài khoản</h2>
    <form method="post" class="form-grid compact">
        <?= csrf_field() ?><input type="hidden" name="action" value="create">
        <label>Tên đăng nhập<input name="username" required minlength="3" maxlength="100" pattern="[A-Za-z0-9._-]+"></label>
        <label>Mật khẩu tạm (ít nhất 12 ký tự)<input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
        <label>Vai trò<select name="role"><option value="staff">Staff — xử lý ticket</option><option value="admin">Admin — toàn quyền</option></select></label>
        <div><button type="submit">Tạo tài khoản</button></div>
    </form>
</section>
<section class="card"><h2>Tài khoản hiện có</h2><div class="table-wrap"><table><thead><tr><th>Tên đăng nhập</th><th>Vai trò</th><th>Tạo lúc</th><th>Đăng nhập gần nhất</th><th>Đổi mật khẩu</th><th></th></tr></thead><tbody>
<?php foreach ($users as $user): ?><tr><td><?= e($user['username']) ?><?= (int)$user['id'] === $admin['id'] ? ' (bạn)' : '' ?></td><td><?= e($user['role']) ?></td><td><?= e($user['created_at']) ?></td><td><?= e($user['last_login_at'] ?? '—') ?></td>
<td><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="password"><input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>"><input type="password" name="password" minlength="12" required placeholder="Mật khẩu mới"><button type="submit" class="small">Đổi</button></form></td>
<td><?php if ((int)$user['id'] !== $admin['id']): ?><form method="post" onsubmit="return confirm('Xóa tài khoản này?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>"><button type="submit" class="danger small">Xóa</button></form><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php page_footer(); ?>
