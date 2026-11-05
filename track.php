<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

$ticket = null;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $code = strtoupper(trim((string)($_POST['ticket_code'] ?? '')));
    $contact = trim((string)($_POST['contact'] ?? ''));
    if (!preg_match('/^TK-\d{6,}$/', $code) || $contact === '') {
        $error = 'Vui lòng nhập mã ticket và email/số điện thoại đã khai báo.';
    } else {
        $stmt = db()->prepare('SELECT ticket_code, status, title, type, created_at, updated_at, admin_note FROM tickets WHERE ticket_code = ? AND (email = ? OR contact = ?) LIMIT 1');
        $stmt->execute([$code, $contact, $contact]);
        $ticket = $stmt->fetch() ?: null;
        if (!$ticket) $error = 'Không tìm thấy ticket với thông tin đã nhập.';
    }
}

page_header('Tra cứu ticket');
if ($error !== '') echo '<div class="alert error">' . e($error) . '</div>';
?>
<form method="post" class="card form-grid compact">
    <?= csrf_field() ?>
    <label>Mã ticket<input name="ticket_code" required placeholder="TK-000123" value="<?= e($_POST['ticket_code'] ?? '') ?>"></label>
    <label>Email hoặc SĐT đã khai báo<input name="contact" required value="<?= e($_POST['contact'] ?? '') ?>"></label>
    <div class="full"><button type="submit">Tra cứu</button></div>
</form>
<?php if ($ticket): ?>
    <section class="card result">
        <h2><?= e($ticket['ticket_code']) ?> — <?= e($ticket['title']) ?></h2>
        <p><strong>Trạng thái:</strong> <?= e(ticket_status_label($ticket['status'])) ?></p>
        <p><strong>Loại yêu cầu:</strong> <?= e(ticket_type_label($ticket['type'])) ?></p>
        <p><strong>Ngày tạo:</strong> <?= e($ticket['created_at']) ?></p>
        <?php if (!empty($ticket['admin_note'])): ?><div><strong>Ghi chú phản hồi:</strong><p class="preserve-lines"><?= e($ticket['admin_note']) ?></p></div><?php endif; ?>
    </section>
<?php endif; ?>
<?php page_footer(); ?>
