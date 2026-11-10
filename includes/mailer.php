<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/* =====================================================================
 *  GỬI EMAIL — hỗ trợ 2 driver (chọn bằng MAIL_DRIVER trong config.php):
 *   - smtp : Office 365 SMTP (smtp.office365.com:587, STARTTLS) qua PHPMailer — ĐANG DÙNG
 *   - graph: Office 365 qua Microsoft Graph (OAuth) — dự phòng khi Microsoft tắt SMTP Basic Auth
 *  Mọi lỗi chỉ ghi vào PHP error log, KHÔNG làm hỏng việc tạo/cập nhật ticket.
 * ===================================================================== */

function mail_driver(): string
{
    $driver = defined('MAIL_DRIVER') ? MAIL_DRIVER : 'off';
    if ($driver === 'off' && SMTP_ENABLED) {
        $driver = 'smtp';
    }
    return $driver;
}

/**
 * Gửi một email HTML.
 * @param string|string[] $to
 * @param array{importance?: string} $options importance: low|normal|high (chỉ áp dụng với Graph)
 */
function send_ticket_email(string|array $to, string $subject, string $html, array $options = []): bool
{
    $recipients = normalize_recipients(is_array($to) ? $to : [$to]);
    if (!$recipients) {
        return false;
    }

    return match (mail_driver()) {
        'graph' => graph_send_mail($recipients, $subject, $html, $options),
        'smtp' => smtp_send_mail($recipients, $subject, $html),
        default => false,
    };
}

/** Lọc trùng, bỏ địa chỉ rỗng/không hợp lệ. */
function normalize_recipients(array $emails): array
{
    $clean = [];
    foreach ($emails as $email) {
        $email = strtolower(trim((string)$email));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $clean[$email] = true;
        }
    }
    return array_keys($clean);
}

/* ---------------------------- Microsoft Graph ---------------------------- */

function graph_http(string $method, string $url, array $headers, ?string $body): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP extension curl chưa bật (php.ini: extension=curl).');
    }
    $ch = curl_init($url);
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }
    if (MAIL_CA_BUNDLE !== '') {
        $opts[CURLOPT_CAINFO] = MAIL_CA_BUNDLE;
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($response === false) {
        throw new RuntimeException('Kết nối thất bại: ' . $error);
    }
    return [$status, (string)$response];
}

/** Lấy access token (cache ra file tạm ~55 phút để không xin token mỗi lần gửi). */
function graph_access_token(bool $forceRefresh = false): string
{
    if (M365_TENANT_ID === '' || M365_CLIENT_ID === '' || M365_CLIENT_SECRET === '') {
        throw new RuntimeException('Thiếu M365_TENANT_ID / M365_CLIENT_ID / M365_CLIENT_SECRET trong config.php.');
    }
    $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ticket_graph_' . hash('sha256', M365_TENANT_ID . M365_CLIENT_ID . APP_KEY) . '.json';

    if (!$forceRefresh && is_file($cacheFile)) {
        $cached = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($cached) && ($cached['expires_at'] ?? 0) > time() + 60 && !empty($cached['token'])) {
            return (string)$cached['token'];
        }
    }

    $url = 'https://login.microsoftonline.com/' . rawurlencode(M365_TENANT_ID) . '/oauth2/v2.0/token';
    [$status, $body] = graph_http('POST', $url, ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'client_id' => M365_CLIENT_ID,
        'client_secret' => M365_CLIENT_SECRET,
        'scope' => 'https://graph.microsoft.com/.default',
        'grant_type' => 'client_credentials',
    ]));
    $data = json_decode($body, true);
    if ($status !== 200 || empty($data['access_token'])) {
        $reason = is_array($data) ? (($data['error'] ?? '') . ' ' . ($data['error_description'] ?? '')) : $body;
        throw new RuntimeException('Không lấy được token (HTTP ' . $status . '): ' . mb_substr(trim($reason), 0, 300));
    }
    $token = (string)$data['access_token'];
    $expiresAt = time() + max(300, (int)($data['expires_in'] ?? 3600)) - 300;
    @file_put_contents($cacheFile, json_encode(['token' => $token, 'expires_at' => $expiresAt]), LOCK_EX);
    return $token;
}

function graph_send_mail(array $recipients, string $subject, string $html, array $options = []): bool
{
    try {
        if (M365_SENDER === '' || !filter_var(M365_SENDER, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('M365_SENDER chưa cấu hình hoặc không hợp lệ.');
        }
        $importance = in_array($options['importance'] ?? 'normal', ['low', 'normal', 'high'], true) ? ($options['importance'] ?? 'normal') : 'normal';
        $payload = json_encode([
            'message' => [
                'subject' => $subject,
                'importance' => $importance,
                'body' => ['contentType' => 'HTML', 'content' => $html],
                'toRecipients' => array_map(static fn(string $a): array => ['emailAddress' => ['address' => $a]], $recipients),
            ],
            'saveToSentItems' => M365_SAVE_TO_SENT,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode(M365_SENDER) . '/sendMail';

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $token = graph_access_token($attempt > 1);
            [$status, $body] = graph_http('POST', $url, [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ], $payload);
            if ($status === 202) {
                return true;
            }
            if ($status !== 401) {
                break; // chỉ thử lại khi token hết hạn
            }
        }
        $err = json_decode($body ?? '', true);
        $msg = is_array($err) ? (($err['error']['code'] ?? '') . ': ' . ($err['error']['message'] ?? '')) : (string)$body;
        throw new RuntimeException('Graph sendMail lỗi HTTP ' . $status . ' — ' . mb_substr($msg, 0, 300));
    } catch (Throwable $exception) {
        error_log('Ticket mail error (graph): ' . $exception->getMessage());
        return false;
    }
}

/* -------------------------------- SMTP --------------------------------- */

function smtp_send_mail(array $recipients, string $subject, string $html): bool
{
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    } else {
        $manualDir = __DIR__ . '/PHPMailer/src/';
        foreach (['Exception.php', 'PHPMailer.php', 'SMTP.php'] as $file) {
            if (is_file($manualDir . $file)) {
                require_once $manualDir . $file;
            }
        }
    }
    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        error_log('Ticket mail error: PHPMailer is not installed.');
        return false;
    }
    if (SMTP_USER === '' || SMTP_PASS === '') {
        error_log('Ticket mail error (smtp): SMTP_USER / SMTP_PASS chưa điền trong config.php.');
        return false;
    }
    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Timeout = defined('SMTP_TIMEOUT') ? SMTP_TIMEOUT : 15;
        $mail->Encoding = \PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64; // tiếng Việt hiển thị đúng trên mọi client
        if (PHP_SAPI === 'cli' && getenv('MAIL_DEBUG')) {
            $mail->SMTPDebug = \PHPMailer\PHPMailer\SMTP::DEBUG_SERVER; // chỉ khi chạy tools/test_mail.php --debug
        }
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(SMTP_FROM_EMAIL !== '' ? SMTP_FROM_EMAIL : SMTP_USER, SMTP_FROM_NAME);
        foreach ($recipients as $address) {
            $mail->addAddress($address);
        }
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)));
        $mail->send();
        return true;
    } catch (Throwable $exception) {
        $detail = isset($mail) && $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $exception->getMessage();
        error_log('Ticket mail error (smtp): ' . $detail);
        return false;
    }
}

/* ----------------------- Thông báo cho người quản lý ----------------------- */

/** Danh sách quản lý nhận thông báo cho một loại ticket. */
function manager_recipients(string $type): array
{
    $map = defined('MANAGER_RECIPIENTS') ? MANAGER_RECIPIENTS : [];
    $list = array_merge($map['all'] ?? [], $map[$type] ?? []);
    if (!$list && ADMIN_EMAIL !== '' && ADMIN_EMAIL !== 'admin@example.com') {
        $list = [ADMIN_EMAIL]; // tương thích cấu hình cũ
    }
    return normalize_recipients($list);
}

function ticket_admin_url(int $ticketId): string
{
    return app_url('admin/ticket.php?id=' . $ticketId);
}

/** Khung HTML email chung (inline CSS để hiển thị đúng trong Outlook). */
function ticket_email_layout(string $heading, array $rows, string $buttonUrl = '', string $buttonText = '', string $extraHtml = ''): string
{
    $html = '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;color:#1d2b3a;max-width:640px">';
    $html .= '<div style="background:#102a43;color:#fff;padding:12px 16px;font-weight:600">' . e(APP_NAME) . '</div>';
    $html .= '<div style="padding:16px;border:1px solid #dce4ec;border-top:0">';
    $html .= '<h2 style="font-size:17px;margin:0 0 12px">' . e($heading) . '</h2>';
    $html .= '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%">';
    foreach ($rows as $label => $value) {
        $html .= '<tr><td style="color:#64748b;width:140px;vertical-align:top;border-bottom:1px solid #eef2f6">' . e($label) . '</td>'
            . '<td style="vertical-align:top;border-bottom:1px solid #eef2f6">' . $value . '</td></tr>';
    }
    $html .= '</table>' . $extraHtml;
    if ($buttonUrl !== '') {
        $html .= '<p style="margin:18px 0 4px"><a href="' . e($buttonUrl) . '" style="background:#145da0;color:#fff;padding:9px 16px;border-radius:5px;text-decoration:none;font-weight:600">' . e($buttonText) . '</a></p>';
    }
    $html .= '<p style="color:#94a3b8;font-size:12px;margin-top:18px">Email tự động từ hệ thống ticket nội bộ — vui lòng không trả lời email này.</p>';
    return $html . '</div></div>';
}

/** Báo quản lý khi có ticket mới. $ticket: mảng có id, ticket_code, full_name, department, contact, type, priority, title, description, attachment_original_name. */
function notify_managers_new_ticket(array $ticket): bool
{
    if (!NOTIFY_ON_NEW_TICKET) {
        return false;
    }
    $to = manager_recipients((string)$ticket['type']);
    if (!$to) {
        return false;
    }
    $isHigh = $ticket['priority'] === 'high';
    $description = (string)$ticket['description'];
    if (mb_strlen($description) > 1500) {
        $description = mb_substr($description, 0, 1500) . '…';
    }
    $priorityHtml = $isHigh
        ? '<strong style="color:#b42318">' . e(ticket_priority_label('high')) . '</strong>'
        : e(ticket_priority_label((string)$ticket['priority']));
    $rows = [
        'Mã ticket' => '<strong>' . e($ticket['ticket_code']) . '</strong>',
        'Tiêu đề' => e($ticket['title']),
        'Người gửi' => e($ticket['full_name']) . ' — ' . e($ticket['department']),
        'Phạm vi ảnh hưởng' => e(ticket_impact_scopes()[(string)($ticket['impact_scope'] ?? '')] ?? 'Chưa ghi nhận'),
        'Khả năng làm việc' => e(ticket_work_abilities()[(string)($ticket['work_ability'] ?? '')] ?? 'Chưa ghi nhận'),
    ];
    if (!empty($ticket['contact'])) {
        $rows['Zalo liên hệ'] = e($ticket['contact']);
    }
    $rows['Loại'] = e(ticket_type_label((string)$ticket['type']));
    $rows['Mức độ ưu tiên'] = $priorityHtml;
    $rows['Đính kèm'] = e($ticket['attachment_original_name'] ?: 'Không có');
    $html = ticket_email_layout(
        'Ticket mới ' . $ticket['ticket_code'],
        $rows,
        ticket_admin_url((int)$ticket['id']),
        'Mở ticket',
        '<p style="margin:14px 0 4px;color:#64748b">Mô tả:</p><div style="background:#f7f9fb;padding:10px;border-radius:5px">' . nl2br(e($description)) . '</div>'
    );
    $subject = ($isHigh ? '[KHẨN] ' : '') . '[Ticket mới] ' . $ticket['ticket_code'] . ' — ' . mb_substr((string)$ticket['title'], 0, 120);
    return send_ticket_email($to, $subject, $html, ['importance' => $isHigh ? 'high' : 'normal']);
}

/** Báo quản lý khi ticket được cập nhật. $changes: danh sách mô tả thay đổi. */
function notify_managers_ticket_updated(array $ticket, array $changes, string $byUsername): bool
{
    if (!NOTIFY_ON_STATUS_CHANGE || !$changes) {
        return false;
    }
    $to = manager_recipients((string)$ticket['type']);
    if (!$to) {
        return false;
    }
    $changeList = '<ul style="margin:0;padding-left:18px">' . implode('', array_map(static fn(string $c): string => '<li>' . e($c) . '</li>', $changes)) . '</ul>';
    $html = ticket_email_layout(
        'Cập nhật ticket ' . $ticket['ticket_code'],
        [
            'Mã ticket' => '<strong>' . e($ticket['ticket_code']) . '</strong>',
            'Tiêu đề' => e($ticket['title']),
            'Người gửi' => e($ticket['full_name']) . ' — ' . e($ticket['department']),
            'Trạng thái' => e(ticket_status_label((string)$ticket['status'])),
            'Ưu tiên' => e(ticket_priority_label((string)$ticket['priority'])),
            'Người xử lý' => e($ticket['assignee_name'] ?? 'Chưa gán'),
            'Thay đổi' => $changeList,
            'Thực hiện bởi' => e($byUsername),
        ],
        ticket_admin_url((int)$ticket['id']),
        'Mở ticket'
    );
    $subject = '[Cập nhật] ' . $ticket['ticket_code'] . ' — ' . ticket_status_label((string)$ticket['status']);
    return send_ticket_email($to, $subject, $html, ['importance' => $ticket['priority'] === 'high' ? 'high' : 'normal']);
}

function notify_managers_ticket_reminder(array $ticket, int $intervalHours): bool
{
    if (!in_array($intervalHours, [2, 4], true)) {
        throw new InvalidArgumentException('Invalid ticket reminder interval.');
    }
    $to = manager_recipients((string)$ticket['type']);
    if (!$to) {
        error_log('Ticket reminder skipped: no manager recipients for ticket ' . $ticket['ticket_code'] . '.');
        return false;
    }
    $priority = ticket_priority_label((string)$ticket['priority']);
    $html = ticket_email_layout(
        'Nhắc xử lý ticket ' . $ticket['ticket_code'],
        [
            'Mã ticket' => '<strong>' . e($ticket['ticket_code']) . '</strong>',
            'Tiêu đề' => e($ticket['title']),
            'Người gửi' => e($ticket['full_name']) . ' — ' . e($ticket['department']),
            'Zalo liên hệ' => e($ticket['contact']),
            'Ưu tiên' => '<strong>' . e($priority) . '</strong>',
            'Thời gian chờ' => 'Ticket vẫn chưa xử lý sau ' . $intervalHours . ' giờ.',
        ],
        ticket_admin_url((int)$ticket['id']),
        'Mở và xử lý ticket',
        '<p style="margin:14px 0 4px;color:#64748b">Mô tả:</p><div style="background:#f7f9fb;padding:10px;border-radius:5px">' . nl2br(e((string)$ticket['description'])) . '</div>'
    );
    $subject = '[Nhắc xử lý] Ticket ' . $ticket['ticket_code'] . ' — ' . mb_substr((string)$ticket['title'], 0, 120);
    return send_ticket_email($to, $subject, $html, ['importance' => $ticket['priority'] === 'high' ? 'high' : 'normal']);
}

/** Gửi email xác nhận kèm thông tin ticket cho người gửi và hộp thư IT. */
function notify_requester_created(string $email, array $ticket): bool
{
    if (!NOTIFY_REQUESTER || $email === '') {
        return false;
    }
    $description = (string)($ticket['description'] ?? '');
    if (mb_strlen($description) > 2000) {
        $description = mb_substr($description, 0, 2000) . '…';
    }
    $ticketCode = (string)$ticket['ticket_code'];
    $html = ticket_email_layout(
        'Chúng tôi đã nhận yêu cầu của bạn',
        [
            'Mã ticket' => '<strong>' . e($ticketCode) . '</strong>',
            'Người gửi' => e((string)$ticket['full_name']),
            'Phòng ban' => e((string)$ticket['department']),
            'Zalo liên hệ' => e((string)$ticket['contact']),
            'Loại yêu cầu' => e(ticket_type_label((string)$ticket['type'])),
            'Phạm vi ảnh hưởng' => e(ticket_impact_scopes()[(string)$ticket['impact_scope']] ?? ''),
            'Khả năng làm việc' => e(ticket_work_abilities()[(string)$ticket['work_ability']] ?? ''),
            'Mức độ ưu tiên' => e(ticket_priority_label((string)$ticket['priority'])),
            'Tiêu đề' => e((string)$ticket['title']),
            'Hạn phản hồi' => e((string)$ticket['response_due_at']),
            'Hạn xử lý' => e((string)$ticket['resolution_due_at']),
            'Tệp đính kèm' => e((string)($ticket['attachment_original_name'] ?: 'Không có')),
        ],
        app_url('track.php?' . http_build_query(['ticket_code' => $ticketCode])),
        'Tra cứu ticket',
        '<p style="margin:14px 0 4px;color:#64748b">Mô tả:</p><div style="background:#f7f9fb;padding:10px;border-radius:5px">'
            . nl2br(e($description)) . '</div>'
    );
    return send_ticket_email([$email, SMTP_USER], 'Xác nhận ticket ' . $ticketCode, $html);
}

/* ------------------------------- Telegram -------------------------------- */

function send_telegram_notification(string $message): bool
{
    if (!TELEGRAM_ENABLED) {
        return false;
    }
    if (TELEGRAM_BOT_TOKEN === '' || TELEGRAM_CHAT_ID === '') {
        error_log('Ticket Telegram error: bot token or chat ID is missing.');
        return false;
    }

    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage';
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query(['chat_id' => TELEGRAM_CHAT_ID, 'text' => $message]),
            'timeout' => 5,
            'ignore_errors' => true,
        ],
    ]);
    $result = @file_get_contents($url, false, $context);
    if ($result === false) {
        error_log('Ticket Telegram error: notification request failed.');
        return false;
    }
    return true;
}
