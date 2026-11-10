<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/functions.php';
$user = require_permission('tickets.view');

$validStatuses = ['new', 'in_progress', 'completed', 'rejected', 'not_completed'];
$validTypes = array_keys(ticket_types());
$departments = ticket_departments();
$validPriorities = ['low', 'medium', 'high'];
$filters = [
    'status' => (string)($_GET['status'] ?? ''),
    'type' => (string)($_GET['type'] ?? ''),
    'department' => (string)($_GET['department'] ?? ''),
    'priority' => (string)($_GET['priority'] ?? ''),
    'from' => (string)($_GET['from'] ?? ''),
    'to' => (string)($_GET['to'] ?? ''),
    'q' => trim((string)($_GET['q'] ?? '')),
];
$where = [];
$params = [];
if ($filters['status'] === 'not_completed') {
    $where[] = "t.status <> 'completed'";
} elseif (in_array($filters['status'], $validStatuses, true)) {
    $where[] = 't.status = ?';
    $params[] = $filters['status'];
}
if (in_array($filters['type'], $validTypes, true)) { $where[] = 't.type = ?'; $params[] = $filters['type']; }
if (in_array($filters['department'], $departments, true)) { $where[] = 't.department = ?'; $params[] = $filters['department']; }
if (in_array($filters['priority'], $validPriorities, true)) { $where[] = 't.priority = ?'; $params[] = $filters['priority']; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['from'])) { $where[] = 't.created_at >= ?'; $params[] = $filters['from'] . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['to'])) { $where[] = 't.created_at <= ?'; $params[] = $filters['to'] . ' 23:59:59'; }
if ($filters['q'] !== '') {
    $where[] = '(t.ticket_code LIKE ? OR t.full_name LIKE ? OR t.title LIKE ?)';
    $needle = '%' . $filters['q'] . '%';
    array_push($params, $needle, $needle, $needle);
}
$filterSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$pdo = db();
try {
    $pdo->exec("UPDATE tickets SET type = 'pc' WHERE type = 'accounting'");
} catch (Throwable) {}
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM tickets t' . $filterSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;
$firstItem = $total > 0 ? $offset + 1 : 0;
$lastItem = min($offset + $perPage, $total);
$sql = 'SELECT t.id, t.ticket_code, t.full_name, t.department, t.title, t.type, t.priority, t.status, t.created_at, t.response_due_at, t.resolution_due_at, a.username AS assignee
        FROM tickets t LEFT JOIN admins a ON a.id = t.assignee_id' . $filterSql . " ORDER BY CAST(t.ticket_code AS UNSIGNED) ASC, t.id ASC LIMIT " . $perPage . ' OFFSET ' . $offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll();
$query = $_GET;
$listQuery = http_build_query($query);
$pageUrl = static function (int $targetPage) use ($query): string {
    $params = $query;
    $params['page'] = $targetPage;
    return '?' . http_build_query($params);
};
page_header('Danh sách ticket', true);
show_flash();
?>
<section class="card">
    <form method="get" class="filters">
        <label>Tìm kiếm<input name="q" value="<?= e($filters['q']) ?>" placeholder="Mã, tên, tiêu đề"></label>
        <label>Trạng thái<select name="status"><option value="">Tất cả</option><?php foreach ($validStatuses as $item): ?><option value="<?= e($item) ?>" <?= $filters['status'] === $item ? 'selected' : '' ?>><?= e($item === 'not_completed' ? 'Chưa hoàn thành' : ticket_status_label($item)) ?></option><?php endforeach; ?></select></label>
        <label>Loại<select name="type"><option value="">Tất cả</option><?php foreach ($validTypes as $item): ?><option value="<?= e($item) ?>" <?= $filters['type'] === $item ? 'selected' : '' ?>><?= e(ticket_type_label($item)) ?></option><?php endforeach; ?></select></label>
        <label>Ban/Phòng ban<select name="department"><option value="">Tất cả</option><?php foreach ($departments as $item): ?><option value="<?= e($item) ?>" <?= $filters['department'] === $item ? 'selected' : '' ?>><?= e($item) ?></option><?php endforeach; ?></select></label>
        <label>Ưu tiên<select name="priority"><option value="">Tất cả</option><?php foreach ($validPriorities as $item): ?><option value="<?= e($item) ?>" <?= $filters['priority'] === $item ? 'selected' : '' ?>><?= e(ticket_priority_label($item)) ?></option><?php endforeach; ?></select></label>
        <div class="filter-date-range">
            <label>Từ ngày<input type="date" name="from" value="<?= e($filters['from']) ?>"></label>
            <label>Đến ngày<input type="date" name="to" value="<?= e($filters['to']) ?>"></label>
        </div>
        <div class="filter-actions">
            <button type="submit">Lọc</button>
            <?php if (has_permission($user, 'tickets.export')): ?><a class="button secondary" href="<?= e(app_path('/admin/export.php')) ?>?<?= e(http_build_query($filters)) ?>">Xuất CSV</a><?php endif; ?>
            <?php if (array_filter($filters)): ?>
                <a class="button secondary" href="<?= e(app_path('/admin/tickets.php')) ?>" title="Xóa bộ lọc để xem toàn bộ ticket">Đặt lại</a>
            <?php endif; ?>
        </div>
    </form>
</section>
<section class="card">
    <p class="ticket-list-summary">Hiển thị <?= $firstItem ?>–<?= $lastItem ?> trong <?= $total ?> ticket</p>
    <?php if (!$tickets): ?><p>Không có ticket phù hợp.</p><?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>Mã</th><th>Người gửi</th><th>Phòng ban</th><th>Tiêu đề</th><th>Loại</th><th>Ưu tiên</th><th>Hạn phản hồi</th><th>Hạn xử lý</th><th>Trạng thái</th><th>Người xử lý</th><th>Ngày tạo</th><th>Thao tác</th></tr></thead><tbody>
    <?php foreach ($tickets as $ticket): ?><tr id="ticket-<?= (int)$ticket['id'] ?>" class="<?= $ticket['status'] === 'new' ? 'is-new' : '' ?> <?= $ticket['priority'] === 'high' ? 'is-high' : '' ?>">
        <td><?= e($ticket['ticket_code']) ?></td><td><?= e($ticket['full_name']) ?></td><td><?= e($ticket['department']) ?></td><td><?= e($ticket['title']) ?></td><td><?= e(ticket_type_label($ticket['type'])) ?></td><td class="priority-cell priority-<?= e($ticket['priority']) ?>"><?= e(ticket_priority_label($ticket['priority'])) ?></td><td><?= e($ticket['response_due_at'] ?? '—') ?></td><td><?= e($ticket['resolution_due_at'] ?? '—') ?></td><td><?= e(ticket_status_label($ticket['status'])) ?></td><td><?= e($ticket['assignee'] ?? '—') ?></td><td><?= e($ticket['created_at']) ?></td>
        <td><div class="row-actions"><a class="button edit-button" href="<?= e(app_path('/admin/ticket.php')) ?>?<?= e(http_build_query(['id' => (int)$ticket['id'], 'return' => $listQuery])) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg>Sửa</a><?php if (has_permission($user, 'tickets.delete')): ?><form method="post" action="<?= e(app_path('/admin/ticket.php')) ?>" class="delete-form" onsubmit="return confirm('Bạn có chắc muốn xóa ticket <?= e($ticket['ticket_code']) ?>? Hành động này không thể hoàn tác.')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$ticket['id'] ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="return" value="<?= e($listQuery) ?>"><button type="submit" class="delete-button" aria-label="Xóa ticket <?= e($ticket['ticket_code']) ?>" title="Xóa ticket"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2m3 0-1 14H6L5 6m4 4v6m6-6v6"/></svg></button></form><?php endif; ?></div></td>
    </tr><?php endforeach; ?></tbody></table></div>
    <?php if ($pages > 1): ?>
        <nav class="pagination" aria-label="Phân trang danh sách ticket">
            <span class="pagination-info">Trang <?= $page ?> / <?= $pages ?></span>
            <div class="pagination-controls">
                <?php if ($page > 1): ?>
                    <a class="pagination-link pagination-direction" href="<?= e($pageUrl($page - 1)) ?>" rel="prev">Trước</a>
                <?php else: ?>
                    <span class="pagination-link pagination-direction is-disabled" aria-disabled="true">Trước</span>
                <?php endif; ?>
                <?php for ($pageNumber = 1; $pageNumber <= $pages; $pageNumber++): ?>
                    <?php if ($pageNumber === $page): ?>
                        <span class="pagination-link is-current" aria-current="page"><?= $pageNumber ?></span>
                    <?php else: ?>
                        <a class="pagination-link" href="<?= e($pageUrl($pageNumber)) ?>"><?= $pageNumber ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $pages): ?>
                    <a class="pagination-link pagination-direction" href="<?= e($pageUrl($page + 1)) ?>" rel="next">Sau</a>
                <?php else: ?>
                    <span class="pagination-link pagination-direction is-disabled" aria-disabled="true">Sau</span>
                <?php endif; ?>
            </div>
        </nav>
    <?php endif; ?>
    <?php endif; ?>
</section>
<?php page_footer(); ?>
