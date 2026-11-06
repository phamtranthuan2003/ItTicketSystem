<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/mailer.php';

$lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'internal_tickets_reminders.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false) {
    throw new RuntimeException('Unable to open ticket reminder lock file.');
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Ticket reminder task is already running.\n");
    exit(0);
}

$dryRun = in_array('--dry-run', $argv, true);
$pdo = db();
$dueSql = "status = 'new'
           AND priority IN ('high', 'medium')
           AND TIMESTAMPADD(
               HOUR,
               CASE priority WHEN 'high' THEN 2 WHEN 'medium' THEN 4 END,
               COALESCE(last_reminded_at, created_at)
           ) <= NOW()";
$dueStmt = $pdo->query("SELECT id, ticket_code, priority FROM tickets WHERE $dueSql ORDER BY created_at");
$dueTickets = $dueStmt->fetchAll();

if ($dryRun) {
    foreach ($dueTickets as $ticket) {
        printf("%s\t%s\t%s\n", $ticket['id'], $ticket['ticket_code'], $ticket['priority']);
    }
    printf("Due reminders: %d\n", count($dueTickets));
    exit(0);
}

$sent = 0;
$failed = 0;
foreach ($dueTickets as $candidate) {
    try {
        $pdo->beginTransaction();
        $ticketStmt = $pdo->prepare(
            "SELECT id, ticket_code, full_name, department, contact, type, priority, title, description
             FROM tickets
             WHERE id = ? AND $dueSql
             FOR UPDATE"
        );
        $ticketStmt->execute([(int)$candidate['id']]);
        $ticket = $ticketStmt->fetch();
        if (!$ticket) {
            $pdo->commit();
            continue;
        }

        $intervalHours = $ticket['priority'] === 'high' ? 2 : 4;
        if (!notify_managers_ticket_reminder($ticket, $intervalHours)) {
            $pdo->rollBack();
            $failed++;
            continue;
        }

        $update = $pdo->prepare('UPDATE tickets SET last_reminded_at = NOW() WHERE id = ?');
        $update->execute([(int)$ticket['id']]);
        $pdo->commit();
        $sent++;
        printf("Sent reminder for ticket %s.\n", $ticket['ticket_code']);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Ticket reminder task error for ticket ' . $candidate['ticket_code'] . ': ' . $exception->getMessage());
        $failed++;
    }
}

printf("Sent: %d; failed: %d; checked: %d\n", $sent, $failed, count($dueTickets));
if ($failed > 0) {
    exit(1);
}
