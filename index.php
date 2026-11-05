<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';

$errors = [];
$successCode = '';
$types = ['it' => 'IT', 'hr' => 'Nhân sự', 'admin' => 'Hành chính', 'accounting' => 'Kế toán', 'other' => 'Khác'];
$priorities = ['low' => 'Thấp', 'medium' => 'Trung bình', 'high' => 'Cao'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!empty($_POST['website'] ?? '')) {
        http_response_code(200);
        exit('Đã tiếp nhận yêu cầu.');
    }

    $data = [
        'full_name' => trim((string)($_POST['full_name'] ?? '')),
        'department' => trim((string)($_POST['department'] ?? '')),
        'contact' => trim((string)($_POST['contact'] ?? '')),
        'email' => trim((string)($_POST['email'] ?? '')),
        'type' => (string)($_POST['type'] ?? 'other'),
        'priority' => (string)($_POST['priority'] ?? 'medium'),
        'title' => trim((string)($_POST['title'] ?? '')),
        'description' => trim((string)($_POST['description'] ?? '')),
    ];
    if ($data['full_name'] === '' || mb_strlen($data['full_name']) > 150) $errors[] = 'Vui lòng nhập họ tên (tối đa 150 ký tự).';
    if ($data['department'] === '' || mb_strlen($data['department']) > 150) $errors[] = 'Vui lòng nhập phòng ban (tối đa 150 ký tự).';
    if ($data['contact'] === '' || mb_strlen($data['contact']) > 190) $errors[] = 'Vui lòng nhập email hoặc số điện thoại.';
    if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Địa chỉ email không hợp lệ.';
    if (!array_key_exists($data['type'], $types)) $errors[] = 'Loại yêu cầu không hợp lệ.';
    if (!array_key_exists($data['priority'], $priorities)) $errors[] = 'Mức ưu tiên không hợp lệ.';
    if ($data['title'] === '' || mb_strlen($data['title']) > 200) $errors[] = 'Vui lòng nhập tiêu đề (tối đa 200 ký tự).';
    if ($data['description'] === '' || mb_strlen($data['description']) > 20000) $errors[] = 'Vui lòng nhập mô tả (tối đa 20.000 ký tự).';

    $upload = null;
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['attachment'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Tải tệp lên thất bại. Vui lòng thử lại.';
        } elseif ($file['size'] > MAX_UPLOAD_BYTES) {
            $errors[] = 'Tệp đính kèm vượt quá 5MB.';
        } else {
            $allowed = [
                'jpg' => ['image/jpeg'],
                'png' => ['image/png'],
                'pdf' => ['application/pdf'],
                'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
                'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
            ];
            $originalName = (string)$file['name'];
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            if (!isset($allowed[$extension]) || !in_array($mime, $allowed[$extension], true)) {
                $errors[] = 'Định dạng tệp không được phép hoặc MIME không hợp lệ.';
            } else {
                $upload = [
                    'tmp_name' => $file['tmp_name'],
                    'original_name' => mb_substr(preg_replace('/[\r\n"\\\\]/', '_', basename($originalName)) ?? 'attachment', 0, 255),
                    'extension' => $extension,
                    'mime' => $mime,
                ];
            }
        }
    }

    if (!$errors) {
        $savedFilePath = null;
        try {
            $pdo = db();
            $pdo->beginTransaction();
            $ipHash = client_ip_hash();
            $rate = $pdo->prepare(
                'INSERT INTO rate_limits (ip_hash, window_started_at, request_count) VALUES (?, NOW(), 1)
                 ON DUPLICATE KEY UPDATE
                   request_count = IF(window_started_at < DATE_SUB(NOW(), INTERVAL 1 HOUR), 1, request_count + 1),
                   window_started_at = IF(window_started_at < DATE_SUB(NOW(), INTERVAL 1 HOUR), NOW(), window_started_at)'
            );
            $rate->execute([$ipHash]);
            $countStmt = $pdo->prepare('SELECT request_count FROM rate_limits WHERE ip_hash = ?');
            $countStmt->execute([$ipHash]);
            if ((int)$countStmt->fetchColumn() > 5) {
                $pdo->rollBack();
                http_response_code(429);
                $errors[] = 'Bạn đã gửi quá 5 yêu cầu trong một giờ. Vui lòng thử lại sau.';
            } else {
                $attachmentName = null;
                if ($upload !== null) {
                    $uploadDir = __DIR__ . '/uploads';
                    if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
                        throw new RuntimeException('Upload directory is unavailable.');
                    }
                    $attachmentName = bin2hex(random_bytes(20)) . '.' . $upload['extension'];
                    if (!move_uploaded_file($upload['tmp_name'], $uploadDir . DIRECTORY_SEPARATOR . $attachmentName)) {
                        throw new RuntimeException('Unable to move uploaded file.');
                    }
                    $savedFilePath = $uploadDir . DIRECTORY_SEPARATOR . $attachmentName;
                }
                $stmt = $pdo->prepare(
                    'INSERT INTO tickets (ticket_code, full_name, department, contact, email, type, priority, title, description, attachment_name, attachment_original_name, ip_hash)
                     VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $data['full_name'], $data['department'], $data['contact'], $data['email'] ?: null,
                    $data['type'], $data['priority'], $data['title'], $data['description'],
                    $attachmentName, $upload['original_name'] ?? null, $ipHash,
                ]);
                $ticketId = (int)$pdo->lastInsertId();
                $successCode = 'TK-' . str_pad((string)$ticketId, 6, '0', STR_PAD_LEFT);
                $pdo->prepare('UPDATE tickets SET ticket_code = ? WHERE id = ?')->execute([$successCode, $ticketId]);
                $pdo->commit();
                $savedFilePath = null;

                $safeCode = e($successCode);
                $safeTitle = e($data['title']);
                send_ticket_email(ADMIN_EMAIL, "Ticket mới {$successCode}", "<p>Có ticket mới: <strong>{$safeCode}</strong></p><p>{$safeTitle}</p>");
                if ($data['email'] !== '') {
                    send_ticket_email($data['email'], "Xác nhận ticket {$successCode}", "<p>Chúng tôi đã nhận yêu cầu của bạn.</p><p>Mã ticket: <strong>{$safeCode}</strong></p><p>Tra cứu tại: " . e(app_url('track.php')) . '</p>');
                }
                send_telegram_notification("Ticket mới {$successCode}: " . $data['title']);
            }
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            if ($savedFilePath !== null && is_file($savedFilePath)) unlink($savedFilePath);
            error_log('Ticket creation error: ' . $exception->getMessage());
            $errors[] = 'Không thể tạo ticket lúc này. Vui lòng thử lại sau.';
        }
    }
}

page_header('Gửi yêu cầu');
if ($successCode !== '') {
    echo '<div class="alert success"><h2>Gửi yêu cầu thành công</h2><p>Mã ticket của bạn: <strong class="ticket-code">' . e($successCode) . '</strong></p><p>Hãy lưu mã này để tra cứu trạng thái.</p><a class="button" href="/ticket/track.php">Tra cứu ticket</a></div>';
} else {
    foreach ($errors as $error) echo '<div class="alert error">' . e($error) . '</div>';
    ?>
    <form method="post" enctype="multipart/form-data" class="card form-grid">
        <?= csrf_field() ?>
        <div class="honeypot" aria-hidden="true"><label>Để trống<input name="website" tabindex="-1" autocomplete="off"></label></div>
        <label>Họ tên *<input name="full_name" required maxlength="150" value="<?= e($_POST['full_name'] ?? '') ?>"></label>
        <label>Phòng ban *<input name="department" required maxlength="150" value="<?= e($_POST['department'] ?? '') ?>"></label>
        <label>Điện thoại hoặc email *<input name="contact" required maxlength="190" value="<?= e($_POST['contact'] ?? '') ?>"></label>
        <label>Email (để nhận xác nhận)<input type="email" name="email" maxlength="190" value="<?= e($_POST['email'] ?? '') ?>"></label>
        <label>Loại yêu cầu<select name="type"><?php foreach ($types as $key => $label): ?><option value="<?= e($key) ?>" <?= ($_POST['type'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <label>Mức độ ưu tiên<select name="priority"><?php foreach ($priorities as $key => $label): ?><option value="<?= e($key) ?>" <?= ($_POST['priority'] ?? 'medium') === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <label class="full">Tiêu đề *<input name="title" required maxlength="200" value="<?= e($_POST['title'] ?? '') ?>"></label>
        <label class="full">Mô tả chi tiết *<textarea name="description" rows="7" required maxlength="20000"><?= e($_POST['description'] ?? '') ?></textarea></label>
        <label class="full">Đính kèm (JPG, PNG, PDF, DOCX, XLSX; tối đa 5MB)<input type="file" name="attachment" accept=".jpg,.png,.pdf,.docx,.xlsx"></label>
        <div class="full"><button type="submit">Gửi yêu cầu</button></div>
    </form>
    <?php
}
page_footer();
