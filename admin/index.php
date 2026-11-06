<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/functions.php';
require_admin();

$pdo = db();
try {
    $pdo->exec("UPDATE tickets SET type = 'pc' WHERE type = 'accounting'");
} catch (Throwable) {}

$counts = ['all' => 0, 'new' => 0, 'in_progress' => 0, 'completed' => 0, 'rejected' => 0];
foreach ($pdo->query('SELECT status, COUNT(*) AS total FROM tickets GROUP BY status') as $row) {
    $counts['all'] += (int)$row['total'];
    if (isset($counts[$row['status']])) $counts[$row['status']] = (int)$row['total'];
}

$byType = $pdo->query("SELECT type, COUNT(*) AS total FROM tickets WHERE type != 'accounting' GROUP BY type ORDER BY total DESC")->fetchAll();
$firstMonth = new DateTimeImmutable('first day of this month 00:00:00');
$chartStart = $firstMonth->modify('-11 months');
$monthlyStmt = $pdo->prepare(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month_key,
            COUNT(*) AS total,
            SUM(status = 'completed') AS completed
     FROM tickets
     WHERE created_at >= ?
     GROUP BY month_key
     ORDER BY month_key"
);
$monthlyStmt->execute([$chartStart->format('Y-m-d H:i:s')]);
$monthlyRows = [];
foreach ($monthlyStmt->fetchAll() as $row) {
    $monthlyRows[$row['month_key']] = [
        'total' => (int)$row['total'],
        'completed' => (int)$row['completed'],
    ];
}
$monthly = [];
$maxMonthly = 0;
for ($offset = 0; $offset < 12; $offset++) {
    $month = $chartStart->modify('+' . $offset . ' months');
    $key = $month->format('Y-m');
    $values = $monthlyRows[$key] ?? ['total' => 0, 'completed' => 0];
    $monthly[] = [
        'label' => $month->format('m/Y'),
        'total' => $values['total'],
        'completed' => $values['completed'],
    ];
    $maxMonthly = max($maxMonthly, $values['total']);
}
$maxMonthly = max(1, $maxMonthly);
page_header('Tổng quan', true);
?>
<section class="stats">
    <div class="stat"><span>Tổng ticket</span><strong><?= $counts['all'] ?></strong></div>
    <div class="stat"><span>Mới chưa xử lý</span><strong><?= $counts['new'] ?></strong></div>
    <div class="stat"><span>Đang xử lý</span><strong><?= $counts['in_progress'] ?></strong></div>
    <div class="stat"><span>Hoàn thành</span><strong><?= $counts['completed'] ?></strong></div>
    <div class="stat"><span>Từ chối</span><strong><?= $counts['rejected'] ?></strong></div>
</section>
<section class="card">
    <div class="section-title">
        <h2>Thống kê ticket theo tháng</h2>
        <span class="chart-period">12 tháng gần nhất</span>
    </div>
    <div class="chart-legend"><span><i class="legend-total"></i>Tổng ticket</span><span><i class="legend-completed"></i>Hoàn thành</span></div>
    <div class="chart-scroll">
        <div class="monthly-chart" role="img" aria-label="Biểu đồ số ticket được tạo và hoàn thành theo tháng trong 12 tháng gần nhất">
            <?php foreach ($monthly as $month): ?>
                <div class="month-column" title="<?= e($month['label']) ?>: <?= $month['total'] ?> ticket, <?= $month['completed'] ?> hoàn thành">
                    <div class="month-bars">
                        <div class="month-bar total" style="height:<?= (int)round($month['total'] * 100 / $maxMonthly) ?>%"><span><?= $month['total'] ?></span></div>
                        <div class="month-bar completed" style="height:<?= (int)round($month['completed'] * 100 / $maxMonthly) ?>%"><span><?= $month['completed'] ?></span></div>
                    </div>
                    <span class="month-label"><?= e($month['label']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
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
<?php page_footer(); ?>
