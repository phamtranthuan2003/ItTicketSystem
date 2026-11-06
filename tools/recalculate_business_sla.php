<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/functions.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$pdo = db();
$tickets = $pdo->query(
    "SELECT id, created_at, priority FROM tickets WHERE status IN ('new', 'in_progress')"
)->fetchAll();

try {
    $pdo->beginTransaction();
    $update = $pdo->prepare(
        'UPDATE tickets SET response_due_at = ?, resolution_due_at = ? WHERE id = ?'
    );
    foreach ($tickets as $ticket) {
        $deadlines = ticket_sla_deadlines(
            new DateTimeImmutable((string)$ticket['created_at']),
            (string)$ticket['priority']
        );
        $update->execute([
            $deadlines['response']->format('Y-m-d H:i:s'),
            $deadlines['resolution']->format('Y-m-d H:i:s'),
            (int)$ticket['id'],
        ]);
    }
    $pdo->commit();
    echo 'Updated business-hour SLA deadlines for ' . count($tickets) . " open ticket(s).\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Business-hour SLA recalculation failed: ' . $exception->getMessage());
    fwrite(STDERR, "Unable to update SLA deadlines.\n");
    exit(1);
}
