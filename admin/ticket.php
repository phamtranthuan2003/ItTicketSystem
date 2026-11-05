<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/functions.php';
$admin = require_admin();
$pdo = db();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$id) { http_response_code(400); exit('Mã ticket không hợp lệ.'); }

if (isset($_GET['download'])) {
    $ticketStmt = $pdo->prepare('SELECT attachment_name, attachment_original_name FROM tickets WHERE id = ?');
    $ticketStmt->execute([$id]);
    $fileRow = $ticketStmt->fetch();
    if (!$fileRow || !$fileRow['attachment_name']) { http_response_code(404); exit('Không tìm thấy tệp.'); }
    $path = dirname(__DIR__) . '/uploads/' . basename($fileRow['attachment_name']);
    if (!is_file($path)) { http_response_code(404); exit('Tệp không còn tồn tại.'); }
    header('Content-Type: application/octet-stream');
    $downloadName = preg_replace('/[\r\n"\\\\]/', '_', basename($fileRow['attachment_original_name'])) ?? 'attachment';
    header('Content-Disposition: attachment; filename="' . $downloadName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

$stmt = $pdo->prepare('SELECT t.*, a.username AS assignee_name FROM tickets t LEFT JOIN admins a ON a.id = t.assignee_id WHERE t.id = ?');
$stmt->execute([$id]);
$ticket = $stmt->fetch();
if (!$ticket) { http_response_code(404); exit('Không tìm thấy ticket.'); }
$statuses = ['new', 'in_progress', 'completed', 'rejected'];
$priorities = ['low', 'medium', 'high'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $status = (string)($_POST['status'] ?? '');
    $priority = (string)($_POST['priority'] ?? '');
    $assigneeId = (string)($_POST['assignee_id'] ?? '');
    $note = trim((string)($_POST['admin_note'] ?? ''));
    if (!in_array($status, $statuses, true) || !in_array($priority, $priorities, true) || mb_strlen($note) > 10000) {
        admin_flash('error', 'Thông tin cập nhật không hợp lệ.');
        redirect('/ticket/admin/ticket.php?id=' . $id);
    }
    $assignee = $assigneeId === '' ? null : (int)$assigneeId;
    if ($assignee !== null) {
        $assigneeCheck = $pdo->prepare('SELECT id FROM admins WHERE id = ? AND is_active = 1');
        $assigneeCheck->execute([$assignee]);
        if (!$assigneeCheck->fetchColumn()) {
            admin_flash('error', 'Người xử lý được chọn không tồn tại.');
            redirect('/ticket/admin/ticket.php?id=' . $id);
        }
    }

    $changes = [];
    if ($ticket['status'] !== $status) $changes[] = 'Trạng thái: ' . ticket_status_label($ticket['status']) . ' → ' . ticket_status_label($status);
    if ($ticket['priority'] !== $priority) $changes[] = 'Ưu tiên: ' . ticket_priority_label($ticket['priority']) . ' → ' . ticket_priority_label($priority);
    $oldAssignee = $ticket['assignee_id'] ? (string)$ticket['assignee_id'] : '';
    if ($oldAssignee !== (string)($assignee ?? '')) $changes[] = 'Thay đổi người xử lý';
    if ((string)$ticket['admin_note'] !== $note) $changes[] = 'Cập nhật ghi chú phản hồi';
    try {
        $pdo->beginTransaction();
        $update = $pdo->prepare('UPDATE tickets SET status = ?, priority = ?, assignee_id = ?, admin_note = ?, updated_at = NOW() WHERE id = ?');
        $update->execute([$status, $priority, $assignee, $note !== '' ? $note : null, $id]);
        if ($changes) add_ticket_history((int)$id, $admin['id'], 'Cập nhật ticket', implode('; ', $changes));
        $pdo->commit();
        admin_flash('success', 'Ticket đã được cập nhật.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Ticket update error: ' . $exception->getMessage());
        admin_flash('error', 'Không thể cập nhật ticket lúc này.');
    }
    redirect('/ticket/admin/ticket.php?id=' . $id);
}

$assignees = $pdo->query('SELECT id, username FROM admins WHERE is_active = 1 ORDER BY username')->fetchAll();
$historyStmt = $pdo->prepare('SELECT h.action, h.details, h.created_at, a.username FROM ticket_history h LEFT JOIN admins a ON a.id = h.admin_id WHERE h.ticket_id = ? ORDER BY h.id DESC');
$historyStmt->execute([$id]);
$history = $historyStmt->fetchAll();
page_header('Chi tiết ticket ' . $ticket['ticket_code'], true);
show_flash();
?>
<div class="detail-grid">
    <section class="card">
        <h2>Thông tin yêu cầu</h2>
        <dl class="details">
            <dt>Mã ticket</dt><dd><?= e($ticket['ticket_code']) ?></dd>
            <dt>Người gửi</dt><dd><?= e($ticket['full_name']) ?></dd>
            <dt>Phòng ban</dt><dd><?= e($ticket['department']) ?></dd>
            <dt>Email / SĐT</dt><dd><?= e($ticket['contact']) ?></dd>
            <dt>Email xác nhận</dt><dd><?= e($ticket['email'] ?: '—') ?></dd>
            <dt>Loại</dt><dd><?= e(ticket_type_label($ticket['type'])) ?></dd>
            <dt>Ngày tạo</dt><dd><?= e($ticket['created_at']) ?></dd>
            <dt>Tiêu đề</dt><dd><?= e($ticket['title']) ?></dd>
            <dt>Mô tả</dt><dd class="preserve-lines"><?= e($ticket['description']) ?></dd>
            <dt>Đính kèm</dt><dd><?php if ($ticket['attachment_name']): ?><a href="/ticket/admin/ticket.php?id=<?= (int)$id ?>&amp;download=1"><?= e($ticket['attachment_original_name']) ?></a><?php else: ?>Không có<?php endif; ?></dd>
        </dl>
    </section>
    <section class="card">
        <h2>Xử lý ticket</h2>
        <form method="post" class="form-grid">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>">
            <label class="full">Trạng thái<select name="status"><?php foreach ($statuses as $item): ?><option value="<?= e($item) ?>" <?= $ticket['status'] === $item ? 'selected' : '' ?>><?= e(ticket_status_label($item)) ?></option><?php endforeach; ?></select></label>
            <label class="full">Mức ưu tiên<select name="priority"><?php foreach ($priorities as $item): ?><option value="<?= e($item) ?>" <?= $ticket['priority'] === $item ? 'selected' : '' ?>><?= e(ticket_priority_label($item)) ?></option><?php endforeach; ?></select></label>
            <label class="full">Gán cho<select name="assignee_id"><option value="">Chưa gán</option><?php foreach ($assignees as $person): ?><option value="<?= (int)$person['id'] ?>" <?= (int)$ticket['assignee_id'] === (int)$person['id'] ? 'selected' : '' ?>><?= e($person['username']) ?></option><?php endforeach; ?></select></label>
            <label class="full">Ghi chú phản hồi (người gửi có thể xem)<textarea name="admin_note" rows="6" maxlength="10000"><?= e($ticket['admin_note'] ?? '') ?></textarea></label>
            <div class="full"><button type="submit">Lưu thay đổi</button></div>
        </form>
    </section>
</div>
<section class="card"><h2>Lịch sử thay đổi</h2><?php if (!$history): ?><p>Chưa có thay đổi.</p><?php else: ?><ul class="history"><?php foreach ($history as $item): ?><li><strong><?= e($item['action']) ?></strong> — <?= e($item['username'] ?? 'Tài khoản đã xóa') ?> <time><?= e($item['created_at']) ?></time><div><?= e($item['details']) ?></div></li><?php endforeach; ?></ul><?php endif; ?></section>
<?php page_footer(); ?>
