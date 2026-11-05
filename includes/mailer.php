<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function send_ticket_email(string $to, string $subject, string $html): bool
{
    if (!SMTP_ENABLED) {
        return false;
    }

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

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(SMTP_FROM_EMAIL !== '' ? SMTP_FROM_EMAIL : SMTP_USER, SMTP_FROM_NAME);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)));
        $mail->send();
        return true;
    } catch (Throwable $exception) {
        error_log('Ticket mail error: ' . $exception->getMessage());
        return false;
    }
}

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
