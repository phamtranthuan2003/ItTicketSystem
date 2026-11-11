<?php

declare(strict_types=1);

$envFile = __DIR__ . DIRECTORY_SEPARATOR . '.env';
if (is_file($envFile)) {
    if (!is_readable($envFile)) {
        throw new RuntimeException('The project .env file is not readable.');
    }
    $envLines = file($envFile, FILE_IGNORE_NEW_LINES);
    if ($envLines === false) {
        throw new RuntimeException('Unable to read the project .env file.');
    }
    foreach ($envLines as $lineNumber => $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $matches)) {
            throw new RuntimeException('Invalid .env entry on line ' . ($lineNumber + 1) . '.');
        }
        $name = $matches[1];
        if (getenv($name) !== false) {
            continue;
        }
        $value = trim($matches[2]);
        if (strlen($value) >= 2 && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
            $value = substr($value, 1, -1);
        }
        putenv($name . '=' . $value);
    }
}

const APP_NAME = 'Hệ thống quản lý yêu cầu nội bộ';
const APP_KEY = 'e3a587a33a97137d965a37479afa8b11cf882b905d2f223085bf2b2fea5fe5e5';
const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_NAME = 'internal_tickets';
const DB_USER = 'root';
const DB_PASS = ''; // điền pass SQL
const APP_BASE_URL = 'http://192.168.10.16/ItTicketSystem';
$documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
$projectRoot = realpath(__DIR__);
$basePath = '/ticket';
if ($documentRoot !== false && $projectRoot !== false) {
    $documentRoot = rtrim($documentRoot, DIRECTORY_SEPARATOR);
    if (strcasecmp($projectRoot, $documentRoot) === 0) {
        $basePath = '';
    } elseif (
        strncasecmp($projectRoot, $documentRoot . DIRECTORY_SEPARATOR, strlen($documentRoot) + 1) === 0
    ) {
        $basePath = '/' . str_replace(DIRECTORY_SEPARATOR, '/', substr($projectRoot, strlen($documentRoot) + 1));
    }
}
define('APP_BASE_PATH', $basePath);
const ADMIN_EMAIL = 'admin@example.com';

// ===================== EMAIL THÔNG BÁO (OFFICE 365) =====================
// MAIL_DRIVER: 'smtp'  = gửi qua SMTP Office 365 bằng tài khoản + mật khẩu (đang dùng)
//              'graph' = gửi qua Microsoft Graph/OAuth (dự phòng, xem README mục 2b)
//              'off'   = tắt gửi email
const MAIL_DRIVER = 'smtp';

// --- SMTP Office 365 ---
const SMTP_HOST = 'smtp.office365.com';
const SMTP_PORT = 587;
$smtpUser = getenv('SMTP_USER');
$smtpPass = getenv('SMTP_PASS');
define('SMTP_USER', $smtpUser === false ? '' : trim($smtpUser));
define('SMTP_PASS', $smtpPass === false ? '' : $smtpPass);
const SMTP_FROM_EMAIL = '';            // Để trống = dùng SMTP_USER (Office 365 bắt buộc trùng, trừ khi có quyền Send As)
const SMTP_FROM_NAME = 'Hệ thống Ticket nội bộ';
const SMTP_TIMEOUT = 15;               // giây; tránh treo trang nếu máy chủ mail chậm

// --- Người quản lý nhận thông báo ---
const MANAGER_RECIPIENTS = [
    'all'        => ['duc.vm@everland.vn', 'thuan.pt@everland.vn'],
    'it'         => [],
    'hr'         => [],
    'admin'      => [],
    'other'      => [],
];
const NOTIFY_ON_NEW_TICKET = true;
const NOTIFY_ON_STATUS_CHANGE = true;
const NOTIFY_REQUESTER = true;

// --- Microsoft Graph
const M365_TENANT_ID = '';
const M365_CLIENT_ID = '';
$m365ClientSecret = getenv('M365_CLIENT_SECRET');
define('M365_CLIENT_SECRET', $m365ClientSecret === false ? '' : $m365ClientSecret);
const M365_SENDER = '';
const M365_SAVE_TO_SENT = false;
const MAIL_CA_BUNDLE = '';

const SMTP_ENABLED = false;
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

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_name('ticket_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => APP_BASE_PATH !== '' ? APP_BASE_PATH : '/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
