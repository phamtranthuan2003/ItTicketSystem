<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/functions.php';
require_admin();
$_SESSION['new_ticket_count'] = 0;

$validStatuses = ['new', 'in_progress', 'completed', 'rejected'];
$validTypes = ['it', 'hr', 'admin', 'accounting', 'other'];
$validPriorities = ['low', 'medium', 'high'];
$filters = [
    'status' => (string)($_GET['status'] ?? ''),
    'type' => (string)($_GET['type'] ?? ''),
    'priority' => (string)($_GET['priority'] ?? ''),
    'from' => (string)($_GET['from'] ?? ''),
    'to' => (string)($_GET['to'] ?? ''),
    'q' => trim((string)($_GET['q'] ?? '')),
];
$where = [];
$params = [];
if (in_array($filters['status'], $validStatuses, true)) { $where[] = 't.status = ?'; $params[] = $filters['status']; }
if (in_array($filters['type'], $validTypes, true)) { $where[] = 't.type = ?'; $params[] = $filters['type']; }
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
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM tickets t' . $filterSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;
$sql = 'SELECT t.id, t.ticket_code, t.full_name, t.department, t.title, t.type, t.priority, t.status, t.created_at, a.username AS assignee
        FROM tickets t LEFT JOIN admins a ON a.id = t.assignee_id' . $filterSql . ' ORDER BY t.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll();
$query = $_GET;
page_header('Danh sách ticket', true);
?>
<section class="card">
    <form method="get" class="filters">
        <label>Tìm kiếm<input name="q" value="<?= e($filters['q']) ?>" placeholder="Mã, tên, tiêu đề"></label>
        <label>Trạng thái<select name="status"><option value="">Tất cả</option><?php foreach ($validStatuses as $item): ?><option value="<?= e($item) ?>" <?= $filters['status'] === $item ? 'selected' : '' ?>><?= e(ticket_status_label($item)) ?></option><?php endforeach; ?></select></label>
        <label>Loại<select name="type"><option value="">Tất cả</option><?php foreach ($validTypes as $item): ?><option value="<?= e($item) ?>" <?= $filters['type'] === $item ? 'selected' : '' ?>><?= e(ticket_type_label($item)) ?></option><?php endforeach; ?></select></label>
        <label>Ưu tiên<select name="priority"><option value="">Tất cả</option><?php foreach ($validPriorities as $item): ?><option value="<?= e($item) ?>" <?= $filters['priority'] === $item ? 'selected' : '' ?>><?= e(ticket_priority_label($item)) ?></option><?php endforeach; ?></select></label>
        <label>Từ ngày<input type="date" name="from" value="<?= e($filters['from']) ?>"></label>
        <label>Đến ngày<input type="date" name="to" value="<?= e($filters['to']) ?>"></label>
        <button type="submit">Lọc</button>
        <a class="button secondary" href="/ticket/admin/export.php?<?= e(http_build_query($filters)) ?>">Xuất CSV</a>
    </form>
</section>
<section class="card">
    <p>Tìm thấy <?= $total ?> ticket — trang <?= $page ?> / <?= $pages ?></p>
    <?php if (!$tickets): ?><p>Không có ticket phù hợp.</p><?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>Mã</th><th>Người gửi</th><th>Phòng ban</th><th>Tiêu đề</th><th>Loại</th><th>Ưu tiên</th><th>Trạng thái</th><th>Người xử lý</th><th>Ngày tạo</th></tr></thead><tbody>
    <?php foreach ($tickets as $ticket): ?><tr class="<?= $ticket['status'] === 'new' ? 'is-new' : '' ?> <?= $ticket['priority'] === 'high' ? 'is-high' : '' ?>">
        <td><a href="/ticket/admin/ticket.php?id=<?= (int)$ticket['id'] ?>"><?= e($ticket['ticket_code']) ?></a></td><td><?= e($ticket['full_name']) ?></td><td><?= e($ticket['department']) ?></td><td><?= e($ticket['title']) ?></td><td><?= e(ticket_type_label($ticket['type'])) ?></td><td><?= e(ticket_priority_label($ticket['priority'])) ?></td><td><?= e(ticket_status_label($ticket['status'])) ?></td><td><?= e($ticket['assignee'] ?? '—') ?></td><td><?= e($ticket['created_at']) ?></td>
    </tr><?php endforeach; ?></tbody></table></div>
    <div class="pagination"><?php if ($page > 1): $query['page'] = $page - 1; ?><a href="?<?= e(http_build_query($query)) ?>">« Trước</a><?php endif; ?><?php if ($page < $pages): $query['page'] = $page + 1; ?><a href="?<?= e(http_build_query($query)) ?>">Tiếp »</a><?php endif; ?></div>
    <?php endif; ?>
</section>
<?php page_footer(); ?>
