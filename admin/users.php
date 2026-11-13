<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/functions.php';
$admin = require_permission('accounts.view');
$pdo = db();
$canAssignRoles = has_permission($admin, 'accounts.assign_roles');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'create') {
            if (!has_permission($admin, 'accounts.create')) {
                http_response_code(403);
                exit('Bạn không có quyền tạo tài khoản.');
            }
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $requestedRole = (string)($_POST['role'] ?? 'staff');
            $role = $canAssignRoles ? $requestedRole : 'staff';
            if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $username) || strlen($password) < 8 || !in_array($role, ['admin', 'staff'], true)) {
                throw new InvalidArgumentException('Tên đăng nhập cần 3–100 ký tự hợp lệ và mật khẩu ít nhất 8 ký tự.');
            }
            $stmt = $pdo->prepare('INSERT INTO admins (username, password_hash, role) VALUES (?, ?, ?)');
            $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role]);
            admin_flash('success', 'Đã tạo tài khoản.');
        } elseif ($action === 'password') {
            if (!has_permission($admin, 'accounts.manage_password')) {
                http_response_code(403);
                exit('Bạn không có quyền đổi mật khẩu tài khoản.');
            }
            $userId = (int)($_POST['user_id'] ?? 0);
            $password = (string)($_POST['password'] ?? '');
            if ($userId < 1 || strlen($password) < 8) {
                throw new InvalidArgumentException('Mật khẩu mới phải có ít nhất 8 ký tự.');
            }
            $target = $pdo->prepare('SELECT username, role FROM admins WHERE id = ?');
            $target->execute([$userId]);
            $targetAccount = $target->fetch();
            if (!$targetAccount) {
                throw new InvalidArgumentException('Không tìm thấy tài khoản.');
            }
            if (($targetAccount['role'] === 'super_admin' || strcasecmp($targetAccount['username'], 'thuanpt') === 0) && !$canAssignRoles) {
                throw new InvalidArgumentException('Chỉ thuanpt mới có quyền thay đổi tài khoản Super Admin.');
            }
            $stmt = $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?');
            $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
            if (!$stmt->rowCount()) {
                throw new InvalidArgumentException('Không tìm thấy tài khoản.');
            }
            admin_flash('success', 'Đã đổi mật khẩu.');
        } elseif ($action === 'role') {
            if (!$canAssignRoles) {
                http_response_code(403);
                exit('Chỉ tài khoản thuanpt mới có quyền phân vai trò.');
            }
            $userId = (int)($_POST['user_id'] ?? 0);
            $role = (string)($_POST['role'] ?? '');
            if ($userId < 1 || !in_array($role, ['admin', 'staff'], true)) {
                throw new InvalidArgumentException('Vai trò được chọn không hợp lệ.');
            }
            $target = $pdo->prepare('SELECT username, role FROM admins WHERE id = ?');
            $target->execute([$userId]);
            $targetAccount = $target->fetch();
            if (!$targetAccount) {
                throw new InvalidArgumentException('Không tìm thấy tài khoản.');
            }
            if ($targetAccount['role'] === 'super_admin' || strcasecmp($targetAccount['username'], 'thuanpt') === 0) {
                throw new InvalidArgumentException('Không thể thay đổi vai trò của tài khoản thuanpt.');
            }
            $pdo->prepare('UPDATE admins SET role = ? WHERE id = ?')->execute([$role, $userId]);
            admin_flash('success', 'Đã cập nhật quyền tài khoản.');
        } elseif ($action === 'delete') {
            if (!has_permission($admin, 'accounts.delete')) {
                http_response_code(403);
                exit('Bạn không có quyền xóa tài khoản.');
            }
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId === $admin['id']) {
                throw new InvalidArgumentException('Không thể xóa tài khoản đang đăng nhập.');
            }
            $target = $pdo->prepare('SELECT username, role FROM admins WHERE id = ?');
            $target->execute([$userId]);
            $targetAccount = $target->fetch();
            if (!$targetAccount) {
                throw new InvalidArgumentException('Không tìm thấy tài khoản.');
            }
            $targetRole = $targetAccount['role'];
            if ($targetRole === 'super_admin' || strcasecmp($targetAccount['username'], 'thuanpt') === 0) {
                throw new InvalidArgumentException('Không thể xóa tài khoản thuanpt.');
            }
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
    redirect(app_path('/admin/users.php'));
}

$users = $pdo->query('SELECT id, username, role, created_at, last_login_at FROM admins ORDER BY id')->fetchAll();
page_header('Quản lý tài khoản', true);
show_flash();
?>
<section class="card">
    <h2>Thêm tài khoản</h2>
    <form method="post" class="account-create-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="create">
        <div class="account-create-fields">
            <label>Tên đăng nhập<input name="username" required minlength="3" maxlength="100" pattern="[A-Za-z0-9._-]+"></label>
            <label>Mật khẩu tạm (ít nhất 8 ký tự)<input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
            <?php if ($canAssignRoles): ?>
            <label>Vai trò
                <select name="role">
                    <option value="staff">Staff — xử lý ticket</option>
                    <option value="admin">Admin — quản lý ticket</option>
                </select>
            </label>
            <?php endif; ?>
        </div>
        <div class="account-create-actions"><button type="submit">Tạo tài khoản</button></div>
    </form>
</section>
<section class="card"><h2>Tài khoản hiện có</h2><div class="table-wrap"><table><thead><tr><th>Tên đăng nhập</th><th>Vai trò</th><?php if ($canAssignRoles): ?><th>Đổi quyền</th><?php endif; ?><th>Tạo lúc</th><th>Đăng nhập gần nhất</th><th>Đổi mật khẩu</th><th></th></tr></thead><tbody>
<?php foreach ($users as $user): $isProtectedAccount = $user['role'] === 'super_admin' || strcasecmp($user['username'], 'thuanpt') === 0; ?><tr><td><?= e($user['username']) ?><?= (int)$user['id'] === $admin['id'] ? ' (bạn)' : '' ?></td><td><?= $isProtectedAccount ? 'Super Admin' : e($user['role']) ?></td>
<?php if ($canAssignRoles): ?><td><?php if (!$isProtectedAccount && (int)$user['id'] !== $admin['id']): ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="role"><input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>"><select name="role" aria-label="Quyền của <?= e($user['username']) ?>"><option value="staff" <?= $user['role'] === 'staff' ? 'selected' : '' ?>>Staff</option><option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option></select><button type="submit" class="small">Lưu</button></form><?php else: ?>—<?php endif; ?></td><?php endif; ?>
<td><?= e($user['created_at']) ?></td><td><?= e($user['last_login_at'] ?? '—') ?></td>
<td><?php if (has_permission($admin, 'accounts.manage_password') && (!$isProtectedAccount || $canAssignRoles)): ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="password"><input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>"><input type="password" name="password" minlength="8" required placeholder="Mật khẩu mới"><button type="submit" class="small">Đổi</button></form><?php else: ?>—<?php endif; ?></td>
<td><?php if ((int)$user['id'] !== $admin['id'] && !$isProtectedAccount): ?><form method="post" onsubmit="return confirm('Xóa tài khoản này?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>"><button type="submit" class="danger small">Xóa</button></form><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php page_footer(); ?>


<style>
    form.account-create-form .account-create-fields {
        display: flex !important;
        flex-direction: row !important;
        flex-wrap: nowrap;
        gap: 16px;
        align-items: flex-end;
    }
    form.account-create-form .account-create-fields > label {
        display: flex !important;
        flex-direction: column;
        gap: 8px;
        flex: 1 1 0;
        min-width: 0;
        margin: 0;
    }
    form.account-create-form .account-create-fields input,
    form.account-create-form .account-create-fields select {
        width: 100%;
        box-sizing: border-box;
    }
    form.account-create-form .account-create-actions {
        margin-top: 16px;
    }
    @media (max-width: 768px) {
        form.account-create-form .account-create-fields {
            flex-direction: column !important;
        }
}
</style>