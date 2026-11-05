<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/functions.php';
require_admin();

$validStatuses = ['new', 'in_progress', 'completed', 'rejected'];
$validTypes = ['it', 'hr', 'admin', 'accounting', 'other'];
$validPriorities = ['low', 'medium', 'high'];
$where = [];
$params = [];
$status = (string)($_GET['status'] ?? '');
$type = (string)($_GET['type'] ?? '');
$priority = (string)($_GET['priority'] ?? '');
$from = (string)($_GET['from'] ?? '');
$to = (string)($_GET['to'] ?? '');
$q = trim((string)($_GET['q'] ?? ''));
if (in_array($status, $validStatuses, true)) { $where[] = 't.status = ?'; $params[] = $status; }
if (in_array($type, $validTypes, true)) { $where[] = 't.type = ?'; $params[] = $type; }
if (in_array($priority, $validPriorities, true)) { $where[] = 't.priority = ?'; $params[] = $priority; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 't.created_at >= ?'; $params[] = $from . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where[] = 't.created_at <= ?'; $params[] = $to . ' 23:59:59'; }
if ($q !== '') {
    $where[] = '(t.ticket_code LIKE ? OR t.full_name LIKE ? OR t.title LIKE ?)';
    $needle = '%' . $q . '%';
    array_push($params, $needle, $needle, $needle);
}
$sql = 'SELECT t.ticket_code, t.full_name, t.department, t.contact, t.email, t.type, t.priority, t.title, t.description, t.status, t.admin_note, t.created_at, t.updated_at, a.username AS assignee
        FROM tickets t LEFT JOIN admins a ON a.id = t.assignee_id' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY t.id DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="tickets-' . date('Ymd-His') . '.csv"');
$output = fopen('php://output', 'wb');
fwrite($output, "\xEF\xBB\xBF");
$columns = ['Mã ticket', 'Họ tên', 'Phòng ban', 'Liên hệ', 'Email', 'Loại', 'Ưu tiên', 'Tiêu đề', 'Mô tả', 'Trạng thái', 'Ghi chú', 'Người xử lý', 'Ngày tạo', 'Cập nhật'];
fputcsv($output, $columns, ';');
$safeCell = static function (mixed $value): string {
    $text = (string)($value ?? '');
    return preg_match('/^[\s]*[=+\-@]/u', $text) ? "'" . $text : $text;
};
while ($row = $stmt->fetch()) {
    fputcsv($output, [
        $safeCell($row['ticket_code']), $safeCell($row['full_name']), $safeCell($row['department']),
        $safeCell($row['contact']), $safeCell($row['email']), ticket_type_label($row['type']),
        ticket_priority_label($row['priority']), $safeCell($row['title']), $safeCell($row['description']),
        ticket_status_label($row['status']), $safeCell($row['admin_note']), $safeCell($row['assignee']),
        $safeCell($row['created_at']), $safeCell($row['updated_at']),
    ], ';');
}
fclose($output);
