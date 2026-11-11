<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/functions.php';
$user = require_permission('tickets.view');

$pdo = db();
try {
    $pdo->exec("UPDATE tickets SET type = 'pc' WHERE type = 'accounting'");
} catch (Throwable) {
}

$counts = ['all' => 0, 'new' => 0, 'in_progress' => 0, 'completed' => 0, 'rejected' => 0];
foreach ($pdo->query('SELECT status, COUNT(*) AS total FROM tickets GROUP BY status') as $row) {
    $counts['all'] += (int)$row['total'];
    if (isset($counts[$row['status']])) {
        $counts[$row['status']] = (int)$row['total'];
    }
}

$byTypeCounts = array_fill_keys(array_keys(ticket_types()), 0);
foreach ($pdo->query('SELECT type, COUNT(*) AS total FROM tickets GROUP BY type') as $row) {
    if (array_key_exists($row['type'], $byTypeCounts)) {
        $byTypeCounts[$row['type']] = (int)$row['total'];
    }
}
$totalByType = array_sum($byTypeCounts);

$priorityCounts = ['high' => 0, 'medium' => 0, 'low' => 0];
foreach ($pdo->query('SELECT priority, COUNT(*) AS total FROM tickets GROUP BY priority') as $row) {
    if (isset($priorityCounts[$row['priority']])) {
        $priorityCounts[$row['priority']] = (int)$row['total'];
    }
}

$byDepartmentCounts = array_fill_keys(ticket_departments(), 0);
foreach ($pdo->query('SELECT department, COUNT(*) AS total FROM tickets GROUP BY department') as $row) {
    if (array_key_exists($row['department'], $byDepartmentCounts)) {
        $byDepartmentCounts[$row['department']] = (int)$row['total'];
    }
}
$totalByDepartment = array_sum($byDepartmentCounts);

$currentYear = (int)(new DateTimeImmutable('now'))->format('Y');
$reportStartYear = max(1, $currentYear - 2);
$reportMonths = [
    1 => 'Tháng 1',
    2 => 'Tháng 2',
    3 => 'Tháng 3',
    4 => 'Tháng 4',
    5 => 'Tháng 5',
    6 => 'Tháng 6',
    7 => 'Tháng 7',
    8 => 'Tháng 8',
    9 => 'Tháng 9',
    10 => 'Tháng 10',
    11 => 'Tháng 11',
    12 => 'Tháng 12',
];
$firstMonth = new DateTimeImmutable('first day of this month 00:00:00');
$nextMonth = $firstMonth->modify('+1 month');
$monthEnd = $nextMonth->modify('-1 day');
$monthStatsStmt = $pdo->prepare(
    "SELECT COUNT(*) AS total, SUM(status = 'completed') AS completed
     FROM tickets
     WHERE created_at >= ? AND created_at < ?"
);
$monthStatsStmt->execute([$firstMonth->format('Y-m-d H:i:s'), $nextMonth->format('Y-m-d H:i:s')]);
$monthStats = $monthStatsStmt->fetch();
$monthTotal = (int)$monthStats['total'];
$monthCompleted = (int)$monthStats['completed'];
$monthRemaining = max(0, $monthTotal - $monthCompleted);
$monthCompletionPercent = $monthTotal > 0 ? (int)round($monthCompleted * 100 / $monthTotal) : 0;

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
        'from' => $month->format('Y-m-01'),
        'to' => $month->format('Y-m-t'),
        'total' => $values['total'],
        'completed' => $values['completed'],
    ];
    $maxMonthly = max($maxMonthly, $values['total']);
}
$maxMonthly = max(1, $maxMonthly);
page_header('Tổng quan', true);
?>
<?php if (has_permission($user, 'tickets.export')): ?>
    <section class="card dashboard-export-card">
        <form method="get" action="<?= e(app_path('/admin/export_dashboard.php')) ?>" class="dashboard-export-form">
            <label for="dashboard-export-month">Tháng xuất báo cáo
                <select id="dashboard-export-month" name="month_number" required>
                    <?php foreach ($reportMonths as $monthNumber => $monthLabel): ?>
                        <option value="<?= $monthNumber ?>" <?= $monthNumber === (int)$firstMonth->format('n') ? 'selected' : '' ?>><?= e($monthLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label for="dashboard-export-year">Năm
                <select id="dashboard-export-year" name="year" required>
                    <?php for ($year = $currentYear; $year >= $reportStartYear; $year--): ?>
                        <option value="<?= $year ?>" <?= $year === $currentYear ? 'selected' : '' ?>><?= $year ?></option>
                    <?php endfor; ?>
                </select>
            </label>
            <button type="submit">Xuất báo cáo Excel</button>
        </form>
    </section>
<?php endif; ?>
<section class="stats">
    <a class="stat" href="<?= e(app_path('/admin/tickets.php')) ?>" title="Xem tất cả ticket">
        <span>Tổng ticket</span>
        <strong><?= $counts['all'] ?></strong>
    </a>
    <a class="stat" href="<?= e(app_path('/admin/tickets.php?status=new')) ?>" title="Xem ticket Mới chưa xử lý">
        <span>Mới chưa xử lý</span>
        <strong><?= $counts['new'] ?></strong>
    </a>
    <a class="stat" href="<?= e(app_path('/admin/tickets.php?status=in_progress')) ?>" title="Xem ticket Đang xử lý">
        <span>Đang xử lý</span>
        <strong><?= $counts['in_progress'] ?></strong>
    </a>
    <a class="stat" href="<?= e(app_path('/admin/tickets.php?status=completed')) ?>" title="Xem ticket Đã hoàn thành">
        <span>Hoàn thành</span>
        <strong><?= $counts['completed'] ?></strong>
    </a>
    <a class="stat" href="<?= e(app_path('/admin/tickets.php?status=rejected')) ?>" title="Xem ticket Từ chối">
        <span>Từ chối</span>
        <strong><?= $counts['rejected'] ?></strong>
    </a>
</section>

<section class="dashboard-charts">
    <div class="card dashboard-monthly-card">
        <div class="section-title">
            <h2>Thống kê ticket theo tháng</h2>
            <span class="chart-period">12 tháng gần nhất</span>
        </div>
        <div class="chart-legend">
            <a href="<?= e(app_path('/admin/tickets.php?from=' . $chartStart->format('Y-m-01') . '&to=' . $firstMonth->format('Y-m-t'))) ?>" title="Xem tất cả ticket trong 12 tháng qua">
                <i class="legend-total"></i>Tổng ticket
            </a>
            <a href="<?= e(app_path('/admin/tickets.php?status=completed&from=' . $chartStart->format('Y-m-01') . '&to=' . $firstMonth->format('Y-m-t'))) ?>" title="Xem ticket hoàn thành trong 12 tháng qua">
                <i class="legend-completed"></i>Hoàn thành
            </a>
        </div>
        <p class="chart-hint">Chọn cột để xem danh sách ticket của tháng.</p>
        <div class="chart-scroll">
            <div class="monthly-chart" role="img" aria-label="Biểu đồ số ticket được tạo và hoàn thành theo tháng trong 12 tháng gần nhất">
                <?php foreach ($monthly as $month): ?>
                    <div class="month-column" title="Tháng <?= e($month['label']) ?>: <?= $month['total'] ?> ticket, <?= $month['completed'] ?> hoàn thành">
                        <div class="month-bars">
                            <a class="month-bar total" href="<?= e(app_path('/admin/tickets.php?from=' . $month['from'] . '&to=' . $month['to'])) ?>" style="height:<?= (int)round($month['total'] * 100 / $maxMonthly) ?>%" title="Tháng <?= e($month['label']) ?>: <?= $month['total'] ?> ticket (bấm để xem)">
                                <span><?= $month['total'] ?></span>
                            </a>
                            <a class="month-bar completed" href="<?= e(app_path('/admin/tickets.php?status=completed&from=' . $month['from'] . '&to=' . $month['to'])) ?>" style="height:<?= (int)round($month['completed'] * 100 / $maxMonthly) ?>%" title="Tháng <?= e($month['label']) ?>: <?= $month['completed'] ?> hoàn thành (bấm để xem)">
                                <span><?= $month['completed'] ?></span>
                            </a>
                        </div>
                        <a class="month-label" href="<?= e(app_path('/admin/tickets.php?from=' . $month['from'] . '&to=' . $month['to'])) ?>" title="Lọc ticket tháng <?= e($month['label']) ?>"><?= e($month['label']) ?></a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="card dashboard-month-pie-card">
        <div class="section-title">
            <h2>Kết quả tháng này</h2>
            <span class="chart-period"><?= e($firstMonth->format('m/Y')) ?></span>
        </div>
        <p class="chart-hint">Tình trạng của các ticket được tạo trong tháng.</p>
        <div class="pie-card-content">
            <div class="pie-chart-wrap">
                <svg class="pie-chart-svg" viewBox="0 0 120 120" role="img" aria-label="Tháng này có <?= $monthTotal ?> ticket, <?= $monthCompleted ?> đã hoàn thành và <?= $monthRemaining ?> chưa hoàn thành">
                    <circle class="pie-circle-bg" cx="60" cy="60" r="48" pathLength="100"></circle>
                    <circle class="pie-circle-progress" cx="60" cy="60" r="48" pathLength="100" stroke-dasharray="<?= $monthCompletionPercent ?> 100"></circle>
                </svg>
                <div class="pie-center-text">
                    <strong><?= $monthTotal ?></strong>
                    <small>TỔNG TICKET</small>
                    <span><?= $monthCompletionPercent ?>% hoàn thành</span>
                </div>
            </div>
            <div class="pie-legend">
                <a class="pie-legend-item" href="<?= e(app_path('/admin/tickets.php?status=completed&from=' . $firstMonth->format('Y-m-01') . '&to=' . $monthEnd->format('Y-m-d'))) ?>">
                    <span class="pie-legend-left"><i class="legend-completed"></i>Đã hoàn thành</span>
                    <strong><?= $monthCompleted ?></strong>
                </a>
                <a class="pie-legend-item" href="<?= e(app_path('/admin/tickets.php?status=not_completed&from=' . $firstMonth->format('Y-m-01') . '&to=' . $monthEnd->format('Y-m-d'))) ?>" title="Xem các ticket chưa hoàn thành trong tháng này">
                    <span class="pie-legend-left"><i class="legend-remaining"></i>Chưa hoàn thành</span>
                    <strong><?= $monthRemaining ?></strong>
                </a>
            </div>
        </div>
    </div>
</section>

<section class="card">
    <h2>Thống kê theo mức độ ưu tiên</h2>
    <div class="priority-stats">
        <a class="priority-stat priority-high" href="<?= e(app_path('/admin/tickets.php?priority=high')) ?>" title="Xem các ticket mức Cao">
            <span>Cao</span>
            <strong><?= $priorityCounts['high'] ?></strong>
            <small>SLA xử lý: 2 tiếng</small>
        </a>
        <a class="priority-stat priority-medium" href="<?= e(app_path('/admin/tickets.php?priority=medium')) ?>" title="Xem các ticket mức Trung bình">
            <span>Trung bình</span>
            <strong><?= $priorityCounts['medium'] ?></strong>
            <small>SLA xử lý: 4 tiếng</small>
        </a>
        <a class="priority-stat priority-low" href="<?= e(app_path('/admin/tickets.php?priority=low')) ?>" title="Xem các ticket mức Thấp">
            <span>Thấp</span>
            <strong><?= $priorityCounts['low'] ?></strong>
            <small>SLA xử lý: 6 tiếng</small>
        </a>
    </div>
</section>

<section class="dashboard-breakdowns">
    <div class="card">
        <h2>Thống kê theo loại yêu cầu</h2>
        <div class="type-stats"><?php foreach ($byTypeCounts as $type => $total): ?>
                <a class="type-stat" href="<?= e(app_path('/admin/tickets.php?type=' . urlencode($type))) ?>" title="Xem danh sách ticket loại <?= e(ticket_type_label($type)) ?>">
                    <span><?= e(ticket_type_label($type)) ?></span>
                    <strong><?= $total ?></strong>
                    <div class="bar"><span style="width:<?= $totalByType ? (int)round($total * 100 / $totalByType) : 0 ?>%"></span></div>
                </a>
            <?php endforeach; ?></div>
    </div>
    <div class="card">
        <h2>Thống kê theo phòng ban</h2>
        <div class="dept-stats"><?php foreach ($byDepartmentCounts as $department => $total): ?>
            <a class="dept-stat" href="<?= e(app_path('/admin/tickets.php?department=' . urlencode($department))) ?>" title="Xem danh sách ticket của <?= e($department) ?>">
                <span class="dept-stat-header">
                    <span><?= e($department) ?></span>
                    <strong><?= $total ?></strong>
                </span>
                <span class="dept-bar"><span style="width:<?= $totalByDepartment ? (int)round($total * 100 / $totalByDepartment) : 0 ?>%"></span></span>
            </a>
        <?php endforeach; ?></div>
    </div>
</section>
<?php page_footer(); ?>
