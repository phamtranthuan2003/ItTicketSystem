<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/mailer.php';

$lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'internal_tickets_sla_escalations.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false) {
    throw new RuntimeException('Unable to open ticket SLA escalation lock file.');
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Ticket SLA escalation task is already running.\n");
    exit(0);
}

$pdo = db();
$candidateRows = $pdo->query(
    "SELECT id
     FROM tickets
     WHERE status IN ('new', 'in_progress')
       AND (
           (status = 'new' AND response_due_at <= NOW() AND response_sla_escalated_at IS NULL)
           OR (resolution_due_at <= NOW() AND resolution_sla_escalated_at IS NULL)
       )
     ORDER BY created_at"
)->fetchAll(PDO::FETCH_COLUMN);
$dryRun = in_array('--dry-run', $argv, true);
if ($dryRun) {
    foreach ($candidateRows as $candidateId) {
        $stmt = $pdo->prepare(
            "SELECT ticket_code, priority,
                    (status = 'new' AND response_due_at <= NOW() AND response_sla_escalated_at IS NULL) AS response_overdue,
                    (resolution_due_at <= NOW() AND resolution_sla_escalated_at IS NULL) AS resolution_overdue
             FROM tickets WHERE id = ?"
        );
        $stmt->execute([(int)$candidateId]);
        $ticket = $stmt->fetch();
        if ($ticket) {
            printf(
                "%s\t%s\tresponse=%s\tresolution=%s\n",
                $ticket['ticket_code'],
                $ticket['priority'],
                $ticket['response_overdue'] ? 'overdue' : 'ok',
                $ticket['resolution_overdue'] ? 'overdue' : 'ok'
            );
        }
    }
    printf("Due escalations: %d (dry run; no changes made)\n", count($candidateRows));
    exit(0);
}

$priorityRank = ['low' => 1, 'medium' => 2, 'high' => 3];
$priorityForRank = [1 => 'low', 2 => 'medium', 3 => 'high'];
$escalatedCount = 0;
$failedCount = 0;

foreach ($candidateRows as $candidateId) {
    $changes = [];
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "SELECT t.*, (t.status = 'new' AND t.response_due_at <= NOW()) AS response_overdue,
                    (t.resolution_due_at <= NOW()) AS resolution_overdue,
                    a.username AS assignee_name
             FROM tickets t
             LEFT JOIN admins a ON a.id = t.assignee_id
             WHERE t.id = ? AND t.status IN ('new', 'in_progress')
             FOR UPDATE"
        );
        $stmt->execute([(int)$candidateId]);
        $ticket = $stmt->fetch();
        if (!$ticket) {
            $pdo->commit();
            continue;
        }

        $responseOverdue = (bool)$ticket['response_overdue'] && $ticket['response_sla_escalated_at'] === null;
        $resolutionOverdue = (bool)$ticket['resolution_overdue'] && $ticket['resolution_sla_escalated_at'] === null;
        if (!$responseOverdue && !$resolutionOverdue) {
            $pdo->commit();
            continue;
        }

        $rank = $priorityRank[$ticket['priority']];
        $events = [];
        if ($responseOverdue) {
            $rank = min(3, $rank + 1);
            $events[] = 'Quá hạn SLA phản hồi';
        }
        if ($resolutionOverdue) {
            $rank = min(3, $rank + 1);
            $events[] = 'Quá hạn SLA xử lý';
        }
        $newPriority = $priorityForRank[$rank];
        $update = $pdo->prepare(
            'UPDATE tickets
             SET priority = ?,
                 response_sla_escalated_at = IF(? = 1, NOW(), response_sla_escalated_at),
                 resolution_sla_escalated_at = IF(? = 1, NOW(), resolution_sla_escalated_at),
                 updated_at = NOW()
             WHERE id = ?'
        );
        $update->execute([
            $newPriority,
            $responseOverdue ? 1 : 0,
            $resolutionOverdue ? 1 : 0,
            (int)$ticket['id'],
        ]);
        $details = implode('; ', $events) . ': ' . ticket_priority_label($ticket['priority']) . ' → ' . ticket_priority_label($newPriority);
        add_ticket_history((int)$ticket['id'], null, 'Tự động nâng mức ưu tiên', $details);
        $pdo->commit();

        $changes[] = $details;
        notify_managers_ticket_updated(array_merge($ticket, ['priority' => $newPriority]), $changes, 'Hệ thống SLA');
        printf("Escalated ticket %s: %s\n", $ticket['ticket_code'], $details);
        $escalatedCount++;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Ticket SLA escalation error for ID ' . (int)$candidateId . ': ' . $exception->getMessage());
        $failedCount++;
    }
}

printf("Escalated: %d; failed: %d; checked: %d\n", $escalatedCount, $failedCount, count($candidateRows));
if ($failedCount > 0) {
    exit(1);
}
