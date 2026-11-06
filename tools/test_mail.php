<?php
declare(strict_types=1);
// Kiểm tra gửi email. CHỈ chạy bằng dòng lệnh:
//   C:\xampp\php\php.exe C:\xampp\htdocs\ticket\tools\test_mail.php nguoinhan@congty.vn
//   Thêm --debug để xem toàn bộ hội thoại với máy chủ SMTP khi bị lỗi.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/includes/mailer.php';

$args = array_slice($argv, 1);
if (in_array('--debug', $args, true)) {
    putenv('MAIL_DEBUG=1');
}
$to = (string)(array_values(array_diff($args, ['--debug']))[0] ?? '');
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Cách dùng: php tools/test_mail.php email@congty.vn [--debug]\n");
    exit(1);
}
echo 'Driver: ' . mail_driver() . PHP_EOL;
if (mail_driver() === 'graph') {
    try {
        graph_access_token(true);
        echo "[OK] Lấy access token thành công.\n";
    } catch (Throwable $e) {
        echo '[LỖI] Token: ' . $e->getMessage() . PHP_EOL;
        exit(1);
    }
}
ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr'); // in lỗi gửi mail ra màn hình
$html = ticket_email_layout('Email thử nghiệm', ['Thời gian' => e(date('Y-m-d H:i:s'))]);
$ok = send_ticket_email($to, '[Test] ' . APP_NAME, $html);
echo $ok ? "[OK] Đã gửi tới {$to}\n" : "[LỖI] Gửi thất bại — xem dòng lỗi ở trên.\n";
exit($ok ? 0 : 1);
