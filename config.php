<?php
declare(strict_types=1);

const APP_NAME = 'Hệ thống quản lý yêu cầu nội bộ';
const APP_KEY = 'THAY_BANG_CHUOI_NGAU_NHIEN_DAI_IT_NHAT_32_KY_TU';
const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_NAME = 'internal_tickets';
const DB_USER = 'root';
const DB_PASS = '';
const APP_BASE_URL = ''; // Ví dụ: http://192.168.1.10 ; để trống dùng đường dẫn tương đối.
const ADMIN_EMAIL = 'admin@example.com';
const SMTP_ENABLED = false;
const SMTP_HOST = 'smtp.gmail.com';
const SMTP_PORT = 587;
const SMTP_USER = '';
const SMTP_PASS = ''; // Gmail App Password 16 ký tự, không phải mật khẩu Gmail.
const SMTP_FROM_EMAIL = '';
const SMTP_FROM_NAME = APP_NAME;
const TELEGRAM_ENABLED = false;
const TELEGRAM_BOT_TOKEN = '';
const TELEGRAM_CHAT_ID = '';
const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;
const PRODUCTION_MODE = true;

date_default_timezone_set('Asia/Ho_Chi_Minh');

if (PRODUCTION_MODE) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_name('ticket_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/ticket',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
