<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/functions.php';
$admin = require_permission('permissions.manage');
$pdo = db();
$roles = [
    'admin' => 'Admin',
    'staff' => 'Staff',
];
$catalog = permission_catalog();
$roleCounts = $pdo->query("SELECT role, COUNT(*) AS account_count FROM admins WHERE is_active = 1 AND role IN ('admin', 'staff') GROUP BY role")->fetchAll(PDO::FETCH_KEY_PAIR);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $submittedPermissions = $_POST['permissions'] ?? [];
    if (!is_array($submittedPermissions)) {
        admin_flash('error', 'Dữ liệu phân quyền không hợp lệ.');
        redirect(app_path('/admin/permissions.php'));
    }

    try {
        $pdo->beginTransaction();
        $save = $pdo->prepare(
            'INSERT INTO role_permissions (role, permission, is_granted) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted)'
        );
        foreach ($roles as $role => $_label) {
            $selected = $submittedPermissions[$role] ?? [];
            if (!is_array($selected)) {
                throw new InvalidArgumentException('Dữ liệu quyền được chọn không hợp lệ.');
            }
            foreach ($selected as $permission) {
                if (!is_string($permission)) {
                    throw new InvalidArgumentException('Dữ liệu quyền được chọn không hợp lệ.');
                }
            }
            $selected = array_unique(array_map('strval', $selected));
            if (array_diff($selected, array_keys($catalog))) {
                throw new InvalidArgumentException('Có quyền không hợp lệ trong biểu mẫu.');
            }
            if (in_array('tickets.manage', $selected, true)) {
                $selected[] = 'tickets.view';
                $selected[] = 'tickets.update';
            }
            if (in_array('tickets.update', $selected, true) || in_array('tickets.delete', $selected, true)) {
                $selected[] = 'tickets.view';
            }
            foreach (['accounts.create', 'accounts.manage_password', 'accounts.delete'] as $permission) {
                if (in_array($permission, $selected, true)) {
                    $selected[] = 'accounts.view';
                }
            }
            $selected = array_unique($selected);
            foreach ($catalog as $permission => $_definition) {
                $save->execute([$role, $permission, in_array($permission, $selected, true) ? 1 : 0]);
            }
        }
        $pdo->commit();
        admin_flash('success', 'Đã lưu quyền cho các vai trò.');
    } catch (InvalidArgumentException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        admin_flash('error', $exception->getMessage());
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Role permissions update error: ' . $exception->getMessage());
        admin_flash('error', 'Không thể lưu cấu hình phân quyền lúc này.');
    }
    redirect(app_path('/admin/permissions.php'));
}

$stmt = $pdo->query('SELECT role, permission FROM role_permissions WHERE is_granted = 1');
$grants = ['admin' => [], 'staff' => []];
foreach ($stmt->fetchAll() as $grant) {
    if (isset($grants[$grant['role']])) {
        $grants[$grant['role']][] = (string)$grant['permission'];
    }
}

page_header('Phân quyền theo vai trò', true);
show_flash();
?>
<section class="card">
    <h2>Cấu hình quyền dùng chung theo vai trò</h2>
    <p>Chọn những thao tác mỗi vai trò được phép thực hiện. Tất cả tài khoản đang mang vai trò đó sẽ dùng chung cấu hình này. Quyền của Super Admin <strong>thuanpt</strong> luôn được bảo vệ và không cấu hình tại màn hình này.</p>
    <p>Hiện có <?= (int)($roleCounts['admin'] ?? 0) ?> tài khoản Admin và <?= (int)($roleCounts['staff'] ?? 0) ?> tài khoản Staff đang hoạt động.</p>
    <form method="post">
        <?= csrf_field() ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Chức năng</th><th>Mô tả</th><th>Admin</th><th>Staff</th></tr>
                </thead>
                <tbody>
                    <?php $currentGroup = ''; foreach ($catalog as $permission => $definition): ?>
                        <?php if ($currentGroup !== $definition['group']): $currentGroup = $definition['group']; ?>
                            <tr><th colspan="4"><?= e($currentGroup) ?></th></tr>
                        <?php endif; ?>
                        <tr>
                            <td><strong><?= e($definition['label']) ?></strong></td>
                            <td><?= e($definition['description']) ?></td>
                            <?php foreach ($roles as $role => $roleLabel): ?>
                                <td><label><input type="checkbox" name="permissions[<?= e($role) ?>][]" value="<?= e($permission) ?>" aria-label="<?= e($roleLabel . ': ' . $definition['label']) ?>" <?= in_array($permission, $grants[$role], true) ? 'checked' : '' ?>></label></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p><small>Quyền phụ thuộc được bật tự động: chỉnh sửa đầy đủ hoặc xóa ticket yêu cầu quyền xem; cập nhật riêng yêu cầu quyền xem; quản lý tài khoản yêu cầu quyền xem trang tài khoản.</small></p>
        <button type="submit">Lưu phân quyền</button>
    </form>
</section>
<?php page_footer(); ?>
