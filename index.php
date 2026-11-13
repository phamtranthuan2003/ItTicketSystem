<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';

$errors = [];
$successCode = '';
$types = ticket_types();
$departments = ticket_departments();
$impactScopes = ticket_impact_scopes();
$workAbilities = ticket_work_abilities();

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
        'type' => (string)($_POST['type'] ?? 'pc'),
        'impact_scope' => (string)($_POST['impact_scope'] ?? ''),
        'work_ability' => (string)($_POST['work_ability'] ?? ''),
        'title' => trim((string)($_POST['title'] ?? '')),
        'description' => trim((string)($_POST['description'] ?? '')),
    ];
    if ($data['full_name'] === '' || mb_strlen($data['full_name']) > 150) {
        $errors[] = 'Vui lòng nhập họ tên (tối đa 150 ký tự).';
    }
    if (!in_array($data['department'], $departments, true)) {
        $errors[] = 'Vui lòng chọn phòng ban hợp lệ.';
    }
    if ($data['contact'] === '' || strlen($data['contact']) > 10 || !preg_match('/^\d+$/', $data['contact'])) {
        $errors[] = 'Vui lòng nhập thông tin Zalo liên hệ (tối đa 10 số).';
    }
    if (
        $data['email'] === ''
        || strlen($data['email']) > 190
        || filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false
    ) {
        $errors[] = 'Vui lòng nhập địa chỉ email hợp lệ (tối đa 190 ký tự).';
    }
    if (!array_key_exists($data['type'], $types)) {
        $errors[] = 'Loại yêu cầu không hợp lệ.';
    }
    if (!array_key_exists($data['impact_scope'], $impactScopes)) {
        $errors[] = 'Vui lòng chọn phạm vi ảnh hưởng hợp lệ.';
    }
    if (!array_key_exists($data['work_ability'], $workAbilities)) {
        $errors[] = 'Vui lòng chọn khả năng tiếp tục làm việc hợp lệ.';
    }
    if ($data['title'] === '' || mb_strlen($data['title']) > 200) {
        $errors[] = 'Vui lòng nhập tiêu đề (tối đa 200 ký tự).';
    }
    if ($data['description'] === '' || mb_strlen($data['description']) > 20000) {
        $errors[] = 'Vui lòng nhập mô tả (tối đa 20.000 ký tự).';
    }
    $priorityResult = calculate_ticket_priority(
        $data['impact_scope'],
        $data['work_ability'],
        $data['type'],
        $data['department'],
        $data['title'],
        $data['description']
    );

    $uploads = [];
    $fileData = $_FILES['attachment'] ?? null;
    if (is_array($fileData) && isset($fileData['name'])) {
        $fileCount = is_array($fileData['name']) ? count($fileData['name']) : 1;
        if ($fileCount > 5) {
            $errors[] = 'Bạn chỉ có thể đính kèm tối đa 5 tệp.';
        } else {
            $allowed = [
                'jpg' => ['image/jpeg'],
                'jpeg' => ['image/jpeg'],
                'png' => ['image/png'],
                'webp' => ['image/webp'],
                'pdf' => ['application/pdf'],
                'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
                'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
            ];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            for ($index = 0; $index < $fileCount; $index++) {
                $file = [
                    'name' => is_array($fileData['name']) ? $fileData['name'][$index] : $fileData['name'],
                    'type' => is_array($fileData['type']) ? $fileData['type'][$index] : $fileData['type'],
                    'tmp_name' => is_array($fileData['tmp_name']) ? $fileData['tmp_name'][$index] : $fileData['tmp_name'],
                    'error' => is_array($fileData['error']) ? $fileData['error'][$index] : $fileData['error'],
                    'size' => is_array($fileData['size']) ? $fileData['size'][$index] : $fileData['size'],
                ];
                if ($file['error'] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    $errors[] = 'Tải tệp lên thất bại. Vui lòng thử lại.';
                    continue;
                }
                if ($file['size'] > MAX_UPLOAD_BYTES) {
                    $errors[] = 'Tệp đính kèm vượt quá 5MB.';
                    continue;
                }
                $originalName = (string)$file['name'];
                $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                $mime = $finfo->file($file['tmp_name']);
                if (!isset($allowed[$extension]) || !in_array($mime, $allowed[$extension], true)) {
                    $errors[] = 'Định dạng tệp không được phép hoặc MIME không hợp lệ.';
                    continue;
                }
                $uploads[] = [
                    'tmp_name' => $file['tmp_name'],
                    'original_name' => mb_substr(preg_replace('/[\r\n"\\\\]/', '_', basename($originalName)) ?? 'attachment', 0, 255),
                    'extension' => $extension,
                    'mime' => $mime,
                    'size' => (int)$file['size'],
                ];
            }
        }
    }

    if (!$errors) {
        $savedFilePaths = [];
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
                if ($uploads) {
                    $uploadDir = __DIR__ . '/uploads';
                    if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
                        throw new RuntimeException('Upload directory is unavailable.');
                    }
                    foreach ($uploads as &$upload) {
                        $upload['stored_name'] = bin2hex(random_bytes(20)) . '.' . $upload['extension'];
                        $upload['path'] = $uploadDir . DIRECTORY_SEPARATOR . $upload['stored_name'];
                        if (!move_uploaded_file($upload['tmp_name'], $upload['path'])) {
                            throw new RuntimeException('Unable to move uploaded file.');
                        }
                        $savedFilePaths[] = $upload['path'];
                    }
                    unset($upload);
                }
                $createdAt = new DateTimeImmutable('now');
                $deadlines = ticket_sla_deadlines($createdAt, $priorityResult['priority']);
                $stmt = $pdo->prepare(
                    'INSERT INTO tickets (ticket_code, full_name, department, contact, email, type, impact_scope, work_ability, priority, priority_score, response_due_at, resolution_due_at, title, description, attachment_name, attachment_original_name, ip_hash, created_at)
                     VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $data['full_name'], $data['department'], $data['contact'], $data['email'] !== '' ? $data['email'] : null,
                    $data['type'], $data['impact_scope'], $data['work_ability'],
                    $priorityResult['priority'], $priorityResult['score'],
                    $deadlines['response']->format('Y-m-d H:i:s'), $deadlines['resolution']->format('Y-m-d H:i:s'),
                    $data['title'], $data['description'], null, null, $ipHash, $createdAt->format('Y-m-d H:i:s'),
                ]);
                $ticketId = (int)$pdo->lastInsertId();
                $successCode = (string)$ticketId;
                $pdo->prepare('UPDATE tickets SET ticket_code = ? WHERE id = ?')->execute([$successCode, $ticketId]);
                if ($uploads) {
                    $attachmentStmt = $pdo->prepare(
                        'INSERT INTO ticket_attachments (ticket_id, stored_name, original_name, mime_type, file_size) VALUES (?, ?, ?, ?, ?)'
                    );
                    foreach ($uploads as $upload) {
                        $attachmentStmt->execute([$ticketId, $upload['stored_name'], $upload['original_name'], $upload['mime'], $upload['size']]);
                    }
                }
                $pdo->commit();
                $savedFilePaths = [];

                notify_managers_new_ticket([
                    'id' => $ticketId,
                    'ticket_code' => $successCode,
                    'full_name' => $data['full_name'],
                    'department' => $data['department'],
                    'contact' => $data['contact'],
                    'type' => $data['type'],
                    'impact_scope' => $data['impact_scope'],
                    'work_ability' => $data['work_ability'],
                    'priority' => $priorityResult['priority'],
                    'priority_score' => $priorityResult['score'],
                    'title' => $data['title'],
                    'description' => $data['description'],
                    'attachment_original_name' => implode(', ', array_column($uploads, 'original_name')),
                ]);
                notify_requester_created($data['email'], [
                    'ticket_code' => $successCode,
                    'full_name' => $data['full_name'],
                    'department' => $data['department'],
                    'contact' => $data['contact'],
                    'type' => $data['type'],
                    'impact_scope' => $data['impact_scope'],
                    'work_ability' => $data['work_ability'],
                    'priority' => $priorityResult['priority'],
                    'title' => $data['title'],
                    'description' => $data['description'],
                    'response_due_at' => $deadlines['response']->format('Y-m-d H:i:s'),
                    'resolution_due_at' => $deadlines['resolution']->format('Y-m-d H:i:s'),
                    'attachment_original_name' => implode(', ', array_column($uploads, 'original_name')),
                ]);
                send_telegram_notification("Ticket mới {$successCode}: " . $data['title']);
            }
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            foreach ($savedFilePaths as $savedFilePath) {
                if (is_file($savedFilePath) && !unlink($savedFilePath)) {
                    error_log('Ticket attachment cleanup failed after ticket creation error.');
                }
            }
            error_log('Ticket creation error: ' . $exception->getMessage());
            $errors[] = 'Không thể tạo ticket lúc này. Vui lòng thử lại sau.';
        }
    }
}

page_header('Gửi yêu cầu', false, false);
?>
<section class="brand-hero" aria-label="Everland Group">
    <div class="brand-hero-content">
        <p class="brand-hero-kicker">INTERNAL SERVICE DESK</p>
        <h2>Hệ thống yêu cầu hỗ trợ CNTT</h2>
        <p>Gửi yêu cầu hỗ trợ nhanh chóng, theo dõi tiến độ xử lý minh bạch.</p>
        <div class="brand-hero-points"><span>Hỗ trợ nội bộ</span><span>Theo dõi ticket</span><span>Ưu tiên theo SLA</span></div>
    </div>
</section>
<?php
if ($successCode !== '') {
    $finalSla = ticket_sla_minutes($priorityResult['priority']);
    echo '<div class="alert success">'
        . '<h2>Gửi yêu cầu thành công</h2>'
        . '<p>Mã ticket của bạn: <strong class="ticket-code">' . e($successCode) . '</strong></p>'
        . '<p>Mức độ ưu tiên: <strong>' . e(ticket_priority_label($priorityResult['priority'])) . '</strong></p>'
        . '<p>Cam kết xử lý (SLA): phản hồi trong vòng <strong>' . (int)$finalSla['response'] . ' phút</strong> và hoàn tất xử lý trong vòng <strong>' . (int)($finalSla['resolution'] / 60) . ' giờ</strong> làm việc.</p>'
        . '<p>Thời gian SLA chỉ được tính trong giờ hành chính: Thứ Hai – Thứ Sáu, 8:00 – 12:00 và 13:30 – 17:30; Thứ Bảy, 8:00 – 12:00.</p>'
        . '<p>Vui lòng lưu lại mã ticket để tra cứu tình trạng xử lý.</p>'
        . '<a class="button" href="' . e(app_path('/track.php')) . '">Tra cứu ticket</a>'
        . '</div>';
} else {
    foreach ($errors as $error) {
        echo '<div class="alert error">' . e($error) . '</div>';
    }
    ?>
    <form method="post" enctype="multipart/form-data" class="card form-grid">
        <?= csrf_field() ?>
        <div class="honeypot" aria-hidden="true"><label>Để trống<input name="website" tabindex="-1" autocomplete="off"></label></div>
        <label>Họ tên *<input name="full_name" required maxlength="150" value="<?= e($_POST['full_name'] ?? '') ?>"></label>
        <label>Phòng ban *<select name="department" required><option value="">-- Chọn phòng ban --</option><?php foreach ($departments as $department): ?><option value="<?= e($department) ?>" <?= ($_POST['department'] ?? '') === $department ? 'selected' : '' ?>><?= e($department) ?></option><?php endforeach; ?></select></label>
        <label>Loại yêu cầu<select name="type" required><?php foreach ($types as $key => $label): ?><option value="<?= e($key) ?>" <?= ($_POST['type'] ?? 'pc') === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <label>Zalo liên hệ *<input name="contact" type="text" inputmode="numeric" pattern="[0-9]{1,10}" maxlength="10" title="Nhập tối đa 10 chữ số" required placeholder="Nhập số điện thoại" value="<?= e($_POST['contact'] ?? '') ?>"></label>
        <label>Email nhận thông tin ticket *<input name="email" type="email" required maxlength="190" autocomplete="email" placeholder="Nhập địa chỉ email" value="<?= e($_POST['email'] ?? '') ?>"></label>
        <label>Phạm vi ảnh hưởng *<select name="impact_scope" required><option value="">-- Chọn phạm vi --</option><?php foreach ($impactScopes as $key => $label): ?><option value="<?= e($key) ?>" <?= ($_POST['impact_scope'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <label>Có thể tiếp tục làm việc không? *<select name="work_ability" required><option value="">-- Chọn khả năng làm việc --</option><?php foreach ($workAbilities as $key => $label): ?><option value="<?= e($key) ?>" <?= ($_POST['work_ability'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <p class="full form-hint">Mức ưu tiên được tự động tính từ phạm vi ảnh hưởng, khả năng làm việc, loại yêu cầu, phòng ban và từ khóa.</p>
        <label class="full">Tiêu đề *<input name="title" required maxlength="200" value="<?= e($_POST['title'] ?? '') ?>"></label>
        <label class="full">Mô tả chi tiết *<textarea name="description" rows="7" required maxlength="20000"><?= e($_POST['description'] ?? '') ?></textarea></label>
        <div class="full attachment-field">
            <label for="ticket-attachment">Đính kèm (JPG, PNG, PDF, DOCX, XLSX. Tối đa 5MB)</label>
            <div class="attachment-dropzone" id="attachment-paste-area" tabindex="0" role="button" aria-label="Vùng thêm ảnh hoặc tệp đính kèm">
                <span class="attachment-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5M5 14.5v4A1.5 1.5 0 0 0 6.5 20h11a1.5 1.5 0 0 0 1.5-1.5v-4"/></svg>
                </span>
                <strong>Thêm ảnh hoặc tệp đính kèm</strong>
                <span>Dán một hoặc nhiều ảnh bằng Ctrl + V, hoặc chọn tối đa 5 tệp</span>
                <input id="ticket-attachment" type="file" name="attachment[]" accept=".jpg,.jpeg,.png,.webp,.pdf,.docx,.xlsx" multiple>
            </div>
            <div class="attachment-preview" id="attachment-preview" hidden>
                <div class="attachment-preview-list" id="attachment-preview-list"></div>
                <button type="button" class="attachment-remove" id="attachment-remove">Bỏ tất cả</button>
            </div>
            <p class="attachment-feedback" id="attachment-feedback" aria-live="polite">Có thể dán một hoặc nhiều ảnh bằng Ctrl + V, hoặc chọn tệp từ thiết bị.</p>
        </div>
        <div class="full"><button type="submit">Gửi yêu cầu</button></div>
    </form>
    <script defer src="<?= e(app_path('/assets/js/attachment-paste.js?v=3')) ?>"></script>
    <?php
}
page_footer();
