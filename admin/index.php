<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/functions.php';
require_admin();

$pdo = db();
$counts = ['all' => 0, 'new' => 0, 'in_progress' => 0, 'completed' => 0];
foreach ($pdo->query('SELECT status, COUNT(*) AS total FROM tickets GROUP BY status') as $row) {
    $counts['all'] += (int)$row['total'];
    if (isset($counts[$row['status']])) $counts[$row['status']] = (int)$row['total'];
}
$byType = $pdo->query('SELECT type, COUNT(*) AS total FROM tickets GROUP BY type ORDER BY total DESC')->fetchAll();
$recent = $pdo->query('SELECT id, ticket_code, full_name, title, type, priority, status, created_at FROM tickets ORDER BY id DESC LIMIT 8')->fetchAll();
page_header('Tổng quan', true);
?>
<section class="stats">
    <div class="stat"><span>Tổng ticket</span><strong><?= $counts['all'] ?></strong></div>
    <div class="stat"><span>Mới</span><strong><?= $counts['new'] ?></strong></div>
    <div class="stat"><span>Đang xử lý</span><strong><?= $counts['in_progress'] ?></strong></div>
    <div class="stat"><span>Hoàn thành</span><strong><?= $counts['completed'] ?></strong></div>
</section>
<section class="card">
    <h2>Thống kê theo loại yêu cầu</h2>
    <?php if (!$byType): ?><p>Chưa có dữ liệu.</p><?php else: ?>
        <div class="type-stats"><?php foreach ($byType as $row): ?>
            <div class="type-stat"><span><?= e(ticket_type_label($row['type'])) ?></span><strong><?= (int)$row['total'] ?></strong>
                <div class="bar"><span style="width:<?= $counts['all'] ? (int)round((int)$row['total'] * 100 / $counts['all']) : 0 ?>%"></span></div>
            </div>
        <?php endforeach; ?></div>
    <?php endif; ?>
</section>
<section class="card">
    <div class="section-title"><h2>Ticket gần đây</h2><a href="/ticket/admin/tickets.php">Xem tất cả</a></div>
    <?php if (!$recent): ?><p>Chưa có ticket.</p><?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>Mã</th><th>Người gửi</th><th>Tiêu đề</th><th>Loại</th><th>Ưu tiên</th><th>Trạng thái</th><th>Ngày tạo</th></tr></thead><tbody>
        <?php foreach ($recent as $row): ?><tr class="<?= $row['status'] === 'new' ? 'is-new' : '' ?> <?= $row['priority'] === 'high' ? 'is-high' : '' ?>">
            <td><a href="/ticket/admin/ticket.php?id=<?= (int)$row['id'] ?>"><?= e($row['ticket_code']) ?></a></td><td><?= e($row['full_name']) ?></td><td><?= e($row['title']) ?></td><td><?= e(ticket_type_label($row['type'])) ?></td><td><?= e(ticket_priority_label($row['priority'])) ?></td><td><?= e(ticket_status_label($row['status'])) ?></td><td><?= e($row['created_at']) ?></td>
        </tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
</section>
<?php page_footer(); ?>
