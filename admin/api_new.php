<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/functions.php';
require_admin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

try {
    $pdo = db();
    if (!isset($_SESSION['ticket_cursor'])) {
        $_SESSION['ticket_cursor'] = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM tickets')->fetchColumn();
    }
    $cursor = (int)$_SESSION['ticket_cursor'];
    $count = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE status = 'new'")->fetchColumn();
    $stmt = $pdo->prepare("SELECT id, ticket_code, title FROM tickets WHERE id > ? AND status = 'new' ORDER BY id ASC LIMIT 50");
    $stmt->execute([$cursor]);
    $fresh = $stmt->fetchAll();
    if ($fresh) {
        $_SESSION['ticket_cursor'] = (int)end($fresh)['id'];
    }
    echo json_encode([
        'ok' => true,
        'count' => $count,
        'newTickets' => array_map(static fn(array $ticket): array => [
            'code' => $ticket['ticket_code'],
            'title' => $ticket['title'],
            'url' => app_path('/admin/ticket.php?id=' . (int)$ticket['id']),
        ], $fresh),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    error_log('Admin notification endpoint error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Không thể tải thông báo.'], JSON_UNESCAPED_UNICODE);
}
