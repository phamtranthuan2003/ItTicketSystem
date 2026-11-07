<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/mailer.php';
$admin = require_permission('tickets.view');
$canUpdateTickets = has_permission($admin, 'tickets.update');
$canManageTickets = has_permission($admin, 'tickets.manage');
$pdo = db();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$id) { http_response_code(400); exit('Mã ticket không hợp lệ.'); }
$returnState = $_POST['return'] ?? $_GET['return'] ?? '';
$returnParams = [];
if (is_string($returnState) && strlen($returnState) <= 2048) {
    parse_str($returnState, $returnParams);
}
$listParams = [];
foreach (['status', 'type', 'department', 'priority', 'from', 'to', 'q', 'page'] as $key) {
    if (!isset($returnParams[$key]) || !is_string($returnParams[$key])) {
        continue;
    }
    if ($key === 'page' && !ctype_digit($returnParams[$key])) {
        continue;
    }
    $listParams[$key] = $returnParams[$key];
}
$listQuery = http_build_query($listParams);
$backUrl = app_path('/admin/tickets.php') . ($listQuery !== '' ? '?' . $listQuery : '') . '#ticket-' . (int)$id;
$ticketPageUrl = app_path('/admin/ticket.php') . '?' . http_build_query(['id' => (int)$id, 'return' => $listQuery]);

if (isset($_GET['preview_attachment'])) {
    $attachmentId = filter_input(INPUT_GET, 'preview_attachment', FILTER_VALIDATE_INT);
    if (!$attachmentId) { http_response_code(400); exit('Mã tệp không hợp lệ.'); }
    $attachmentStmt = $pdo->prepare('SELECT stored_name, mime_type FROM ticket_attachments WHERE ticket_id = ? AND id = ?');
    $attachmentStmt->execute([$id, $attachmentId]);
    $fileRow = $attachmentStmt->fetch();
    $previewTypes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!$fileRow || !in_array($fileRow['mime_type'], $previewTypes, true)) { http_response_code(404); exit('Không tìm thấy ảnh.'); }
    $path = dirname(__DIR__) . '/uploads/' . basename($fileRow['stored_name']);
    if (!is_file($path) || !is_readable($path)) { http_response_code(404); exit('Ảnh không còn tồn tại.'); }
    header('Content-Type: ' . $fileRow['mime_type']);
    header('Content-Disposition: inline');
    header('Content-Security-Policy: default-src \'none\'; sandbox');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

if (isset($_GET['preview_legacy'])) {
    $ticketStmt = $pdo->prepare('SELECT attachment_name FROM tickets WHERE id = ?');
    $ticketStmt->execute([$id]);
    $fileRow = $ticketStmt->fetch();
    if (!$fileRow || !$fileRow['attachment_name']) { http_response_code(404); exit('Không tìm thấy ảnh.'); }
    $path = dirname(__DIR__) . '/uploads/' . basename($fileRow['attachment_name']);
    if (!is_file($path) || !is_readable($path)) { http_response_code(404); exit('Ảnh không còn tồn tại.'); }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) { http_response_code(415); exit('Tệp này không phải ảnh được hỗ trợ.'); }
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline');
    header('Content-Security-Policy: default-src \'none\'; sandbox');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

if (isset($_GET['attachment_id'])) {
    $attachmentId = filter_input(INPUT_GET, 'attachment_id', FILTER_VALIDATE_INT);
    if (!$attachmentId) { http_response_code(400); exit('Mã tệp không hợp lệ.'); }
    $attachmentStmt = $pdo->prepare('SELECT stored_name, original_name FROM ticket_attachments WHERE ticket_id = ? AND id = ?');
    $attachmentStmt->execute([$id, $attachmentId]);
    $fileRow = $attachmentStmt->fetch();
    if (!$fileRow) { http_response_code(404); exit('Không tìm thấy tệp.'); }
    $path = dirname(__DIR__) . '/uploads/' . basename($fileRow['stored_name']);
    if (!is_file($path) || !is_readable($path)) { http_response_code(404); exit('Tệp không còn tồn tại.'); }
    header('Content-Type: application/octet-stream');
    $downloadName = preg_replace('/[\r\n"\\\\]/', '_', basename($fileRow['original_name'])) ?? 'attachment';
    header('Content-Disposition: attachment; filename="' . $downloadName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

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
if ($ticket['type'] === 'accounting') {
    try {
        $pdo->prepare("UPDATE tickets SET type = 'pc' WHERE id = ?")->execute([$id]);
    } catch (Throwable) {}
    $ticket['type'] = 'pc';
}
$statuses = ['new', 'in_progress', 'completed', 'rejected'];
$priorities = ['low', 'medium', 'high'];
$departments = ticket_departments();
$types = ticket_types();
$editableTypes = $types;
if (!isset($editableTypes[$ticket['type']]) && $ticket['type'] !== 'accounting') {
    $editableTypes = [$ticket['type'] => ticket_type_label($ticket['type'])] + $editableTypes;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'delete') {
        if (!has_permission($admin, 'tickets.delete')) {
            http_response_code(403);
            exit('Bạn không có quyền xóa ticket.');
        }
        try {
            $attachmentFilesStmt = $pdo->prepare('SELECT stored_name FROM ticket_attachments WHERE ticket_id = ?');
            $attachmentFilesStmt->execute([$id]);
            $attachmentNames = array_column($attachmentFilesStmt->fetchAll(), 'stored_name');
            if (!empty($ticket['attachment_name'])) {
                $attachmentNames[] = $ticket['attachment_name'];
            }
            $pdo->beginTransaction();
            $delete = $pdo->prepare('DELETE FROM tickets WHERE id = ?');
            $delete->execute([$id]);
            if ($delete->rowCount() !== 1) {
                throw new RuntimeException('Ticket was not deleted.');
            }
            $pdo->commit();

            $cleanupFailed = false;
            foreach ($attachmentNames as $attachmentName) {
                $attachmentPath = dirname(__DIR__) . '/uploads/' . basename((string)$attachmentName);
                if (is_file($attachmentPath) && !unlink($attachmentPath)) {
                    error_log('Ticket attachment cleanup failed after deleting ticket ' . $id . '.');
                    $cleanupFailed = true;
                }
            }
            admin_flash($cleanupFailed ? 'error' : 'success', $cleanupFailed ? 'Đã xóa ticket nhưng không thể xóa một số tệp đính kèm.' : 'Ticket đã được xóa.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Ticket deletion error: ' . $exception->getMessage());
            admin_flash('error', 'Không thể xóa ticket lúc này.');
        }
        redirect($backUrl);
    }

    if (!$canManageTickets) {
        if (!$canUpdateTickets) {
            http_response_code(403);
            exit('Bạn không có quyền cập nhật ticket.');
        }
        $status = (string)($_POST['status'] ?? '');
        $priority = (string)($_POST['priority'] ?? '');
        $note = trim((string)($_POST['admin_note'] ?? ''));
        if (!in_array($status, $statuses, true) || !in_array($priority, $priorities, true) || mb_strlen($note) > 10000) {
            admin_flash('error', 'Trạng thái, mức ưu tiên hoặc ghi chú không hợp lệ.');
            redirect($ticketPageUrl);
        }

        $changes = [];
        $historyChanges = [];
        if ($ticket['status'] !== $status) {
            $change = 'Trạng thái: ' . ticket_status_label($ticket['status']) . ' → ' . ticket_status_label($status);
            $changes[] = $change;
            $historyChanges[] = $change;
        }
        if ($ticket['priority'] !== $priority) {
            $change = 'Ưu tiên: ' . ticket_priority_label($ticket['priority']) . ' → ' . ticket_priority_label($priority);
            $changes[] = $change;
            $historyChanges[] = $change;
        }
        if ((string)$ticket['admin_note'] !== $note) {
            $changes[] = 'Cập nhật ghi chú phản hồi';
            $historyChanges[] = 'Ghi chú phản hồi: "' . ((string)$ticket['admin_note'] !== '' ? (string)$ticket['admin_note'] : '(trống)')
                . '" → "' . ($note !== '' ? $note : '(trống)') . '"';
        }
        try {
            $pdo->beginTransaction();
            $priorityChanged = $ticket['priority'] !== $priority;
            if ($priorityChanged) {
                $deadlines = ticket_sla_deadlines(new DateTimeImmutable('now'), $priority);
                $responseDeadline = $deadlines['response']->format('Y-m-d H:i:s');
                $resolutionDeadline = $deadlines['resolution']->format('Y-m-d H:i:s');
            } else {
                $responseDeadline = $ticket['response_due_at'];
                $resolutionDeadline = $ticket['resolution_due_at'];
            }
            $update = $pdo->prepare("UPDATE tickets SET last_reminded_at = IF(status <> 'new' AND ? = 'new', NULL, last_reminded_at), status = ?, priority = ?, admin_note = ?, response_due_at = ?, resolution_due_at = ?, updated_at = NOW() WHERE id = ?");
            $update->execute([
                $status, $status, $priority, $note !== '' ? $note : null,
                $responseDeadline, $resolutionDeadline, $id,
            ]);
            if ($changes) {
                add_ticket_history((int)$id, $admin['id'], 'Cập nhật ticket', implode("\n", $historyChanges));
            }
            $pdo->commit();
            admin_flash('success', 'Trạng thái và ghi chú đã được cập nhật.');

            if ($ticket['status'] !== $status || $ticket['priority'] !== $priority) {
                notify_managers_ticket_updated(array_merge($ticket, ['status' => $status, 'priority' => $priority]), $changes, $admin['username']);
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Staff ticket update error: ' . $exception->getMessage());
            admin_flash('error', 'Không thể cập nhật ticket lúc này.');
        }
        redirect($ticketPageUrl);
    }

    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $department = (string)($_POST['department'] ?? '');
    $contact = trim((string)($_POST['contact'] ?? ''));
    $type = (string)($_POST['type'] ?? '');
    $status = (string)($_POST['status'] ?? '');
    $priority = (string)($_POST['priority'] ?? '');
    $assigneeId = (string)($_POST['assignee_id'] ?? '');
    $title = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $note = trim((string)($_POST['admin_note'] ?? ''));
    if (
        $fullName === '' || mb_strlen($fullName) > 150 ||
        ($department !== $ticket['department'] && !in_array($department, $departments, true)) ||
        ($contact === '' && (string)$ticket['contact'] !== '') || mb_strlen($contact) > 190 ||
        ($type !== $ticket['type'] && !array_key_exists($type, $types)) ||
        !in_array($status, $statuses, true) ||
        !in_array($priority, $priorities, true) ||
        $title === '' || mb_strlen($title) > 200 ||
        $description === '' || mb_strlen($description) > 20000 ||
        mb_strlen($note) > 10000
    ) {
        admin_flash('error', 'Thông tin cập nhật không hợp lệ.');
        redirect($ticketPageUrl);
    }
    $assignee = $assigneeId === '' ? null : (int)$assigneeId;
    $assigneeName = null;
    if ($assignee !== null) {
        $assigneeCheck = $pdo->prepare('SELECT username FROM admins WHERE id = ? AND is_active = 1');
        $assigneeCheck->execute([$assignee]);
        $assigneeName = $assigneeCheck->fetchColumn() ?: null;
        if ($assigneeName === null) {
            admin_flash('error', 'Người xử lý được chọn không tồn tại.');
            redirect($ticketPageUrl);
        }
    }

    $changes = [];
    $historyChanges = [];
    $recordChange = static function (string $label, string $oldValue, string $newValue) use (&$changes, &$historyChanges): void {
        $changes[] = $label . ' đã thay đổi';
        $historyChanges[] = $label . ': "' . ($oldValue !== '' ? $oldValue : '(trống)')
            . '" → "' . ($newValue !== '' ? $newValue : '(trống)') . '"';
    };
    if ($ticket['full_name'] !== $fullName) $recordChange('Người gửi', (string)$ticket['full_name'], $fullName);
    if ($ticket['department'] !== $department) $recordChange('Phòng ban', (string)$ticket['department'], $department);
    if ($ticket['contact'] !== $contact) $recordChange('Zalo liên hệ', (string)$ticket['contact'], $contact);
    if ($ticket['type'] !== $type) $recordChange('Loại yêu cầu', ticket_type_label($ticket['type']), ticket_type_label($type));
    if ($ticket['title'] !== $title) $recordChange('Tiêu đề', (string)$ticket['title'], $title);
    if ($ticket['description'] !== $description) $recordChange('Mô tả', (string)$ticket['description'], $description);
    if ($ticket['status'] !== $status) {
        $recordChange('Trạng thái', ticket_status_label($ticket['status']), ticket_status_label($status));
    }
    if ($ticket['priority'] !== $priority) {
        $recordChange('Ưu tiên', ticket_priority_label($ticket['priority']), ticket_priority_label($priority));
    }
    $oldAssignee = $ticket['assignee_id'] ? (string)$ticket['assignee_id'] : '';
    if ($oldAssignee !== (string)($assignee ?? '')) {
        $recordChange('Người xử lý', (string)($ticket['assignee_name'] ?? 'Chưa gán'), (string)($assigneeName ?? 'Chưa gán'));
    }
    if ((string)$ticket['admin_note'] !== $note) {
        $recordChange('Ghi chú phản hồi', (string)$ticket['admin_note'], $note);
    }
    $priorityResult = calculate_ticket_priority(
        (string)$ticket['impact_scope'],
        (string)$ticket['work_ability'],
        $type,
        $department,
        $title,
        $description
    );
    $previousAutomaticPriority = $ticket['priority_score'] === null
        ? null
        : ((int)$ticket['priority_score'] >= 10 ? 'high' : ((int)$ticket['priority_score'] >= 5 ? 'medium' : 'low'));
    if ($previousAutomaticPriority !== $priorityResult['priority']) {
        $historyChanges[] = 'Mức độ ưu tiên tự động: '
            . ($previousAutomaticPriority !== null ? ticket_priority_label($previousAutomaticPriority) : 'chưa xác định')
            . ' → ' . ticket_priority_label($priorityResult['priority']);
    }
    try {
        $pdo->beginTransaction();
        $priorityChanged = $ticket['priority'] !== $priority;
        if ($priorityChanged) {
            $deadlines = ticket_sla_deadlines(new DateTimeImmutable('now'), $priority);
            $responseDeadline = $deadlines['response']->format('Y-m-d H:i:s');
            $resolutionDeadline = $deadlines['resolution']->format('Y-m-d H:i:s');
        } else {
            $responseDeadline = $ticket['response_due_at'];
            $resolutionDeadline = $ticket['resolution_due_at'];
        }
        $update = $pdo->prepare("UPDATE tickets SET full_name = ?, department = ?, contact = ?, type = ?, title = ?, description = ?, priority_score = ?, last_reminded_at = IF(status <> 'new' AND ? = 'new', NULL, last_reminded_at), status = ?, priority = ?, response_due_at = ?, resolution_due_at = ?, assignee_id = ?, admin_note = ?, updated_at = NOW() WHERE id = ?");
        $update->execute([
            $fullName, $department, $contact, $type, $title, $description, $priorityResult['score'], $status, $status, $priority,
            $responseDeadline, $resolutionDeadline,
            $assignee, $note !== '' ? $note : null, $id,
        ]);
        if ($changes || $historyChanges) add_ticket_history((int)$id, $admin['id'], 'Cập nhật ticket', implode("\n", $historyChanges));
        $pdo->commit();
        admin_flash('success', 'Ticket đã được cập nhật.');
        // Chỉ báo quản lý khi có thay đổi quan trọng (không gửi khi chỉ sửa ghi chú)
        $mailChanges = array_values(array_filter($changes, static fn(string $c): bool => !str_starts_with($c, 'Ghi chú phản hồi')));
        if ($mailChanges) {
            notify_managers_ticket_updated(array_merge($ticket, [
                'full_name' => $fullName,
                'department' => $department,
                'contact' => $contact,
                'type' => $type,
                'title' => $title,
                'description' => $description,
                'status' => $status,
                'priority' => $priority,
                'assignee_name' => $assigneeName,
            ]), $mailChanges, $admin['username']);
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Ticket update error: ' . $exception->getMessage());
        admin_flash('error', 'Không thể cập nhật ticket lúc này.');
    }
    redirect($ticketPageUrl);
}

$assignees = $pdo->query('SELECT id, username FROM admins WHERE is_active = 1 ORDER BY username')->fetchAll();
$historyStmt = $pdo->prepare('SELECT h.admin_id, h.action, h.details, h.created_at, a.username FROM ticket_history h LEFT JOIN admins a ON a.id = h.admin_id WHERE h.ticket_id = ? ORDER BY h.id DESC');
$historyStmt->execute([$id]);
$history = $historyStmt->fetchAll();
$priorityCalculation = calculate_ticket_priority(
    (string)$ticket['impact_scope'],
    (string)$ticket['work_ability'],
    (string)$ticket['type'],
    (string)$ticket['department'],
    (string)$ticket['title'],
    (string)$ticket['description']
);
$attachmentStmt = $pdo->prepare('SELECT id, stored_name, original_name, mime_type FROM ticket_attachments WHERE ticket_id = ? ORDER BY id');
$attachmentStmt->execute([$id]);
$attachments = $attachmentStmt->fetchAll();
$attachmentMimes = [];
foreach ($attachments as $attachmentMime) {
    $attachmentMimes[(int)$attachmentMime['id']] = (string)$attachmentMime['mime_type'];
}
$legacyAttachmentIsImage = false;
if (!empty($ticket['attachment_name'])) {
    $legacyAttachmentPath = dirname(__DIR__) . '/uploads/' . basename($ticket['attachment_name']);
    if (is_file($legacyAttachmentPath) && is_readable($legacyAttachmentPath)) {
        $legacyAttachmentIsImage = in_array((new finfo(FILEINFO_MIME_TYPE))->file($legacyAttachmentPath), ['image/jpeg', 'image/png', 'image/webp'], true);
    }
}
page_header('Chi tiết ticket ' . $ticket['ticket_code'], true);
show_flash();
?>
<p><a class="button" href="<?= e($backUrl) ?>">← Quay lại danh sách</a></p>
<div class="detail-grid">
    <section class="card">
        <h2>Thông tin yêu cầu</h2>
        <dl class="details">
            <dt>Mã ticket</dt><dd><?= e($ticket['ticket_code']) ?></dd>
            <dt>Người gửi</dt><dd><?= e($ticket['full_name']) ?></dd>
            <dt>Phòng ban</dt><dd><?= e($ticket['department']) ?></dd>
            <?php if ($ticket['contact'] !== ''): ?><dt>Zalo liên hệ</dt><dd><?= e($ticket['contact']) ?></dd><?php endif; ?>
            <?php if ($ticket['email']): ?><dt>Email xác nhận</dt><dd><?= e($ticket['email']) ?></dd><?php endif; ?>
            <dt>Loại</dt><dd><?= e(ticket_type_label($ticket['type'])) ?></dd>
            <dt>Phạm vi ảnh hưởng</dt><dd><?= e(ticket_impact_scopes()[$ticket['impact_scope']] ?? $ticket['impact_scope']) ?></dd>
            <dt>Khả năng làm việc</dt><dd><?= e(ticket_work_abilities()[$ticket['work_ability']] ?? $ticket['work_ability']) ?></dd>
            <dt>Mức độ ưu tiên</dt><dd><?= e(ticket_priority_label($ticket['priority'])) ?></dd>
            <dt>Hạn phản hồi</dt><dd><?= e($ticket['response_due_at'] ?? 'Chưa thiết lập') ?></dd>
            <dt>Hạn xử lý</dt><dd><?= e($ticket['resolution_due_at'] ?? 'Chưa thiết lập') ?></dd>
            <dt>Ngày tạo</dt><dd><?= e($ticket['created_at']) ?></dd>
            <dt>Tiêu đề</dt><dd><?= e($ticket['title']) ?></dd>
            <dt>Mô tả</dt><dd class="preserve-lines"><?= e($ticket['description']) ?></dd>
            <dt>Đính kèm</dt><dd>
                <?php if ($attachments || $ticket['attachment_name']): ?>
                    <ul class="ticket-attachments">
                        <?php foreach ($attachments as $attachment): ?>
                            <?php $attachmentUrl = $ticketPageUrl; ?>
                            <li class="ticket-attachment">
                                <?php if (in_array($attachmentMimes[(int)$attachment['id']] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)): ?>
                                    <a class="ticket-attachment-image-link" href="<?= e($attachmentUrl . '&preview_attachment=' . (int)$attachment['id']) ?>" target="_blank" rel="noopener">
                                        <img class="ticket-attachment-image" src="<?= e($attachmentUrl . '&preview_attachment=' . (int)$attachment['id']) ?>" alt="<?= e($attachment['original_name']) ?>" loading="lazy">
                                    </a>
                                <?php endif; ?>
                                <span><?= e($attachment['original_name']) ?></span>
                                <a href="<?= e($attachmentUrl . '&attachment_id=' . (int)$attachment['id']) ?>">Tải xuống</a>
                            </li>
                        <?php endforeach; ?>
                        <?php if ($ticket['attachment_name']): ?>
                            <?php $legacyAttachmentUrl = $ticketPageUrl; ?>
                            <li class="ticket-attachment">
                                <?php if ($legacyAttachmentIsImage): ?>
                                    <a class="ticket-attachment-image-link" href="<?= e($legacyAttachmentUrl . '&preview_legacy=1') ?>" target="_blank" rel="noopener">
                                        <img class="ticket-attachment-image" src="<?= e($legacyAttachmentUrl . '&preview_legacy=1') ?>" alt="<?= e($ticket['attachment_original_name']) ?>" loading="lazy">
                                    </a>
                                <?php endif; ?>
                                <span><?= e($ticket['attachment_original_name']) ?></span>
                                <a href="<?= e($legacyAttachmentUrl . '&download=1') ?>">Tải xuống</a>
                            </li>
                        <?php endif; ?>
                    </ul>
                <?php else: ?>Không có<?php endif; ?>
            </dd>
        </dl>
        <?php if ($canManageTickets): ?>
            <form method="post" action="<?= e(app_path('/admin/ticket.php')) ?>" class="delete-detail-form" onsubmit="return confirm('Bạn có chắc muốn xóa ticket <?= e($ticket['ticket_code']) ?>? Hành động này không thể hoàn tác.')">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="return" value="<?= e($listQuery) ?>">
                <button type="submit" class="delete-button"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2m3 0-1 14H6L5 6m4 4v6m6-6v6"/></svg>Xóa ticket</button>
            </form>
        <?php endif; ?>
    </section>
    <?php if ($canUpdateTickets || $canManageTickets): ?>
    <section class="card">
        <h2><?= $canManageTickets ? 'Chỉnh sửa ticket và trạng thái' : 'Cập nhật trạng thái, ưu tiên và ghi chú' ?></h2>
        <form method="post" class="form-grid">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="return" value="<?= e($listQuery) ?>">
            <?php if ($canManageTickets): ?>
                <label class="full">Họ tên người gửi<input name="full_name" required maxlength="150" value="<?= e($ticket['full_name']) ?>"></label>
                <label class="full">Phòng ban<select name="department" required><?php if (!in_array($ticket['department'], $departments, true)): ?><option value="<?= e($ticket['department']) ?>" selected><?= e($ticket['department']) ?></option><?php endif; ?><?php foreach ($departments as $department): ?><option value="<?= e($department) ?>" <?= $ticket['department'] === $department ? 'selected' : '' ?>><?= e($department) ?></option><?php endforeach; ?></select></label>
                <label class="full">Zalo liên hệ<input name="contact" <?= $ticket['contact'] !== '' ? 'required' : '' ?> maxlength="190" value="<?= e($ticket['contact']) ?>"></label>
                <label class="full">Loại yêu cầu<select name="type" required><?php foreach ($editableTypes as $key => $label): ?><option value="<?= e($key) ?>" <?= $ticket['type'] === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                <label class="full">Tiêu đề<input name="title" required maxlength="200" value="<?= e($ticket['title']) ?>"></label>
                <label class="full">Mô tả chi tiết<textarea name="description" rows="7" required maxlength="20000"><?= e($ticket['description']) ?></textarea></label>
            <?php endif; ?>
            <label class="full">Trạng thái<select name="status"><?php foreach ($statuses as $item): ?><option value="<?= e($item) ?>" <?= $ticket['status'] === $item ? 'selected' : '' ?>><?= e(ticket_status_label($item)) ?></option><?php endforeach; ?></select></label>
            <label class="full">Mức ưu tiên<select name="priority"><?php foreach ($priorities as $item): ?><option value="<?= e($item) ?>" <?= $ticket['priority'] === $item ? 'selected' : '' ?>><?= e(ticket_priority_label($item)) ?></option><?php endforeach; ?></select></label>
            <?php if ($canManageTickets): ?>
                <label class="full">Gán cho<select name="assignee_id"><option value="">Chưa gán</option><?php foreach ($assignees as $person): ?><option value="<?= (int)$person['id'] ?>" <?= (int)$ticket['assignee_id'] === (int)$person['id'] ? 'selected' : '' ?>><?= e($person['username']) ?></option><?php endforeach; ?></select></label>
            <?php endif; ?>
            <label class="full">Mô tả / ghi chú phản hồi<textarea name="admin_note" rows="6" maxlength="10000"><?= e($ticket['admin_note'] ?? '') ?></textarea></label>
            <div class="full"><button type="submit">Lưu thay đổi</button></div>
        </form>
    </section>
    <?php endif; ?>
</div>
<section class="card priority-explanation">
    <h2>Chi tiết cách tính điểm ưu tiên</h2>
    <p>Dựa trên thông tin hiện tại của ticket, tổng điểm là <strong><?= (int)$priorityCalculation['score'] ?></strong>, tương ứng mức <?= e(ticket_priority_label($priorityCalculation['priority'])) ?>.</p>
    <?php if ($ticket['priority_score'] !== null && (int)$ticket['priority_score'] !== $priorityCalculation['score']): ?>
        <p class="muted">Điểm đang lưu: <?= (int)$ticket['priority_score'] ?>. Nội dung dùng để tính điểm đã thay đổi; điểm sẽ được đồng bộ khi lưu ticket.</p>
    <?php elseif ($ticket['priority_score'] === null): ?>
        <p class="muted">Ticket cũ chưa lưu điểm; con số trên được tính lại từ dữ liệu hiện tại.</p>
    <?php endif; ?>
    <?php if ($ticket['priority'] !== $priorityCalculation['priority']): ?>
        <p class="muted">Mức ưu tiên đang áp dụng là <?= e(ticket_priority_label($ticket['priority'])) ?>; mức tính từ điểm là <?= e(ticket_priority_label($priorityCalculation['priority'])) ?>. Mức ưu tiên có thể được điều chỉnh thủ công hoặc nâng theo SLA.</p>
    <?php endif; ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Yếu tố</th><th>Điểm cộng</th></tr></thead>
            <tbody>
                <?php foreach ($priorityCalculation['breakdown'] as $factor): ?>
                    <tr><td><?= e($factor['label']) ?></td><td><?= (int)$factor['points'] ?></td></tr>
                <?php endforeach; ?>
                <tr><th>Tổng điểm</th><th><?= (int)$priorityCalculation['score'] ?></th></tr>
            </tbody>
        </table>
    </div>
    <p class="muted">Phạm vi ảnh hưởng: cá nhân +0, cả phòng +3, toàn công ty +6. Khả năng làm việc: vẫn làm được +0, một phần +3, không làm được +6. Loại mạng +3; PC/Laptop/Máy in +2; loại khác +0. Phòng IT +2. Mỗi nhóm từ khóa khẩn cấp, gián đoạn/kết nối và bảo mật/mất dữ liệu được +2, tối đa +4. Mức ưu tiên theo điểm: dưới 5 là Thấp, 5–9 là Trung bình, từ 10 là Cao.</p>
</section>
<section class="card">
    <h2>Lịch sử chỉnh sửa</h2>
    <?php if (!$history): ?><p>Chưa có thay đổi.</p><?php else: ?>
        <ul class="history">
            <?php foreach ($history as $item): ?>
                <?php $actor = $item['username'] ?? (str_starts_with((string)$item['action'], 'Tự động') ? 'Hệ thống' : 'Tài khoản đã xóa'); ?>
                <li>
                    <strong><?= e($item['action']) ?></strong>
                    <div><strong>Người thực hiện:</strong> <?= e($actor) ?> <time><?= e($item['created_at']) ?></time></div>
                    <div class="history-details preserve-lines"><?= e($item['details']) ?></div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<?php page_footer(); ?>
