<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/functions.php';
require_permission('tickets.export');

if (!class_exists(ZipArchive::class)) {
    http_response_code(500);
    exit('Máy chủ chưa hỗ trợ tạo tệp Excel.');
}

/* ------------------------------------------------------------------ */
/* 1. Đọc và kiểm tra tháng báo cáo                                    */
/* ------------------------------------------------------------------ */
$monthValue = (string)($_GET['month'] ?? '');
if ($monthValue === '' && isset($_GET['year'], $_GET['month_number'])) {
    $yearValue = (string)$_GET['year'];
    $monthNumberValue = (string)$_GET['month_number'];
    if (!preg_match('/^\d{4}$/', $yearValue) || !preg_match('/^(0?[1-9]|1[0-2])$/', $monthNumberValue)) {
        http_response_code(400);
        exit('Tháng báo cáo không hợp lệ.');
    }
    $monthValue = sprintf('%04d-%02d', (int)$yearValue, (int)$monthNumberValue);
}
if ($monthValue === '') {
    $monthValue = date('Y-m');
}
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthValue)) {
    http_response_code(400);
    exit('Tháng báo cáo không hợp lệ.');
}

$monthStart = DateTimeImmutable::createFromFormat('!Y-m', $monthValue);
if (!$monthStart || $monthStart->format('Y-m') !== $monthValue) {
    http_response_code(400);
    exit('Tháng báo cáo không hợp lệ.');
}
$nextMonth = $monthStart->modify('+1 month');
$monthEnd = $nextMonth->modify('-1 day');
$dashboardMonthStart = new DateTimeImmutable('first day of this month 00:00:00');
$dashboardNextMonth = $dashboardMonthStart->modify('+1 month');
$chartStart = $dashboardMonthStart->modify('-11 months');
$pdo = db();

/* ------------------------------------------------------------------ */
/* 2. Truy vấn dữ liệu                                                 */
/* ------------------------------------------------------------------ */
$statusKeys = ['new', 'in_progress', 'completed', 'rejected'];
$dashboardStatusCounts = array_fill_keys($statusKeys, 0);
$statusStmt = $pdo->query('SELECT status, COUNT(*) AS total FROM tickets GROUP BY status');
foreach ($statusStmt->fetchAll() as $row) {
    if (isset($dashboardStatusCounts[$row['status']])) {
        $dashboardStatusCounts[$row['status']] = (int)$row['total'];
    }
}
$totalStmt = $pdo->query('SELECT COUNT(*) FROM tickets');
$dashboardTotal = (int)$totalStmt->fetchColumn();
$dashboardMonthStmt = $pdo->prepare(
    "SELECT COUNT(*) AS total, SUM(status = 'completed') AS completed
     FROM tickets WHERE created_at >= ? AND created_at < ?"
);
$dashboardMonthStmt->execute([
    $dashboardMonthStart->format('Y-m-d H:i:s'),
    $dashboardNextMonth->format('Y-m-d H:i:s'),
]);
$dashboardMonth = $dashboardMonthStmt->fetch();
$dashboardMonthTotal = (int)$dashboardMonth['total'];
$dashboardMonthCompleted = (int)$dashboardMonth['completed'];
$dashboardMonthNotCompleted = $dashboardMonthTotal - $dashboardMonthCompleted;

$monthlyStmt = $pdo->prepare(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month_key,
            COUNT(*) AS total,
            SUM(status = 'completed') AS completed
     FROM tickets
     WHERE created_at >= ? AND created_at < ?
     GROUP BY month_key
     ORDER BY month_key"
);
$monthlyStmt->execute([
    $chartStart->format('Y-m-d H:i:s'),
    $dashboardNextMonth->format('Y-m-d H:i:s'),
]);
$monthlyRows = [];
foreach ($monthlyStmt->fetchAll() as $row) {
    $monthlyRows[$row['month_key']] = [
        'total' => (int)$row['total'],
        'completed' => (int)$row['completed'],
    ];
}
$monthly = [];
for ($offset = 0; $offset < 12; $offset++) {
    $chartMonth = $chartStart->modify('+' . $offset . ' months');
    $key = $chartMonth->format('Y-m');
    $monthly[] = [
        'label' => $chartMonth->format('m/Y'),
        'total' => $monthlyRows[$key]['total'] ?? 0,
        'completed' => $monthlyRows[$key]['completed'] ?? 0,
    ];
}

$departments = array_fill_keys(ticket_departments(), 0);
$departmentStmt = $pdo->query('SELECT department, COUNT(*) AS total FROM tickets GROUP BY department');
foreach ($departmentStmt->fetchAll() as $row) {
    if (array_key_exists($row['department'], $departments)) {
        $departments[$row['department']] = (int)$row['total'];
    }
}
$departmentTotal = array_sum($departments);

$ticketStmt = $pdo->prepare(
    'SELECT t.ticket_code, t.full_name, t.department, t.contact, t.email, t.type, t.priority,
            t.impact_scope, t.work_ability, t.response_due_at, t.resolution_due_at, t.title,
            t.description, t.status, t.admin_note, t.created_at, t.updated_at, a.username AS assignee
     FROM tickets t
     LEFT JOIN admins a ON a.id = t.assignee_id
     WHERE t.created_at >= ? AND t.created_at < ?
     ORDER BY t.created_at ASC, CAST(t.ticket_code AS UNSIGNED) ASC'
);
$ticketStmt->execute([$monthStart->format('Y-m-d H:i:s'), $nextMonth->format('Y-m-d H:i:s')]);
$tickets = $ticketStmt->fetchAll();

/* ------------------------------------------------------------------ */
/* 3. Hàm hỗ trợ                                                       */
/* ------------------------------------------------------------------ */
// Chỉ số style (xem styles.xml bên dưới)
const ST_TEXT = 1;      // chữ, có viền
const ST_INT = 2;       // số nguyên, căn giữa, có viền
const ST_TITLE = 3;     // tiêu đề báo cáo
const ST_SECTION = 4;   // thanh tiêu đề mục (nền xanh đậm, chữ trắng)
const ST_HEAD = 5;      // tiêu đề cột (nền xanh nhạt, đậm)
const ST_LABEL = 6;     // nhãn nhiều dòng, căn giữa
const ST_PCT = 7;       // phần trăm
const ST_TOT_TEXT = 8;  // dòng tổng: chữ
const ST_TOT_INT = 9;   // dòng tổng: số
const ST_SUB = 10;      // dòng phụ đề
const ST_BODY = 11;     // nội dung bảng ticket (xuống dòng, canh trên)
const ST_TOT_PCT = 12;  // dòng tổng: phần trăm

$xmlText = static function (mixed $value): string {
    $text = (string)($value ?? '');
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? '';
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$columnName = static function (int $number): string {
    $name = '';
    while ($number > 0) {
        $number--;
        $name = chr(65 + ($number % 26)) . $name;
        $number = intdiv($number, 26);
    }
    return $name;
};
$makeCell = static function (string $type, mixed $value, int $style = 0): array {
    return [$type, $value, $style];
};
$ratio = static function (int $part, int $whole): float {
    return $whole > 0 ? $part / $whole : 0.0;
};

/**
 * Sinh XML cho một worksheet.
 * $opts: drawing(bool), widths(array), autoFilter(bool), freeze(bool),
 *        merges(array), heights(array rowNumber => pt), gridlines(bool), tabColor(string)
 */
$sheetXml = static function (array $rows, array $opts = []) use ($xmlText, $columnName): string {
    $lastRow = max(1, count($rows));
    $lastColumn = 1;
    foreach ($rows as $row) {
        $lastColumn = max($lastColumn, count($row));
    }
    $showGrid = $opts['gridlines'] ?? true;
    $heights = $opts['heights'] ?? [];

    $sheetView = '<sheetView' . ($showGrid ? '' : ' showGridLines="0"') . ' workbookViewId="0">';
    if (!empty($opts['freeze'])) {
        $sheetView .= '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            . '<selection pane="bottomLeft" activeCell="A2" sqref="A2"/>';
    }
    $sheetView .= '</sheetView>';

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetPr>'
        . (isset($opts['tabColor']) ? '<tabColor rgb="' . $opts['tabColor'] . '"/>' : '')
        . '<pageSetUpPr fitToPage="1"/></sheetPr>'
        . '<dimension ref="A1:' . $columnName($lastColumn) . $lastRow . '"/>'
        . '<sheetViews>' . $sheetView . '</sheetViews><sheetFormatPr defaultRowHeight="15"/>';

    if (!empty($opts['widths'])) {
        $xml .= '<cols>';
        foreach ($opts['widths'] as $index => $width) {
            $column = $index + 1;
            $xml .= '<col min="' . $column . '" max="' . $column . '" width="' . (float)$width . '" customWidth="1"/>';
        }
        $xml .= '</cols>';
    }

    $xml .= '<sheetData>';
    foreach ($rows as $rowIndex => $row) {
        $excelRow = $rowIndex + 1;
        $rowAttr = isset($heights[$excelRow])
            ? ' ht="' . (float)$heights[$excelRow] . '" customHeight="1"'
            : '';
        $xml .= '<row r="' . $excelRow . '"' . $rowAttr . '>';
        foreach ($row as $columnIndex => $cell) {
            if ($cell === null) {
                continue;
            }
            [$type, $value, $styleId] = $cell;
            $reference = $columnName($columnIndex + 1) . $excelRow;
            $style = $styleId > 0 ? ' s="' . $styleId . '"' : '';
            if ($type === 'n') {
                $xml .= '<c r="' . $reference . '"' . $style . '><v>' . (float)$value . '</v></c>';
            } elseif ((string)($value ?? '') === '') {
                $xml .= '<c r="' . $reference . '"' . $style . '/>';
            } else {
                $xml .= '<c r="' . $reference . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">'
                    . $xmlText($value) . '</t></is></c>';
            }
        }
        $xml .= '</row>';
    }
    $xml .= '</sheetData>';

    if (!empty($opts['autoFilter'])) {
        $xml .= '<autoFilter ref="A1:' . $columnName($lastColumn) . $lastRow . '"/>';
    }
    if (!empty($opts['merges'])) {
        $xml .= '<mergeCells count="' . count($opts['merges']) . '">';
        foreach ($opts['merges'] as $range) {
            $xml .= '<mergeCell ref="' . $range . '"/>';
        }
        $xml .= '</mergeCells>';
    }
    $xml .= '<pageMargins left="0.4" right="0.4" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
        . '<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/>';
    if (!empty($opts['drawing'])) {
        $xml .= '<drawing r:id="rId1"/>';
    }
    return $xml . '</worksheet>';
};

/* ------------------------------------------------------------------ */
/* 4. Sheet "Tổng quan": dựng bảng số liệu                             */
/* ------------------------------------------------------------------ */
$overviewSheetName = 'Tổng quan';
$ticketSheetName = 'Ticket trong tháng';

$overviewRows = [];
$overviewHeights = [];
$overviewMerges = [];
$addRow = static function (array $cells, ?float $height = null) use (&$overviewRows, &$overviewHeights): int {
    $overviewRows[] = $cells;
    $rowNumber = count($overviewRows);
    if ($height !== null) {
        $overviewHeights[$rowNumber] = $height;
    }
    return $rowNumber;
};
$sectionRow = static function (string $title, int $cols) use ($addRow, &$overviewMerges, $makeCell, $columnName): int {
    $cells = [$makeCell('s', $title, ST_SECTION)];
    for ($i = 1; $i < $cols; $i++) {
        $cells[] = $makeCell('s', '', ST_SECTION);
    }
    $rowNumber = $addRow($cells, 22);
    $overviewMerges[] = 'A' . $rowNumber . ':' . $columnName($cols) . $rowNumber;
    return $rowNumber;
};

// Tiêu đề
$titleRow = $addRow([
    $makeCell('s', 'BÁO CÁO DASHBOARD ADMIN - CHI TIẾT THÁNG ' . $monthStart->format('m/Y'), ST_TITLE),
], 30);
$overviewMerges[] = 'A' . $titleRow . ':D' . $titleRow;
$subRow = $addRow([$makeCell(
    's',
    'Tổng quan và biểu đồ theo giao diện admin hiện tại'
        . '   |   Danh sách ticket kỳ ' . $monthStart->format('d/m/Y') . ' - ' . $monthEnd->format('d/m/Y')
        . '   |   Ngày xuất: ' . date('d/m/Y H:i'),
    ST_SUB
)], 18);
$overviewMerges[] = 'A' . $subRow . ':D' . $subRow;
$addRow([]);

// Mục 1: Tổng quan hệ thống, cùng phạm vi với các ô thống kê trên dashboard.
$sectionRow('1. TỔNG QUAN TOÀN HỆ THỐNG', 3);
$addRow([
    $makeCell('s', 'Chỉ tiêu', ST_HEAD),
    $makeCell('s', 'Số lượng', ST_HEAD),
    $makeCell('s', 'Tỷ lệ', ST_HEAD),
], 20);
$addRow([
    $makeCell('s', 'Tổng ticket', ST_TOT_TEXT),
    $makeCell('n', $dashboardTotal, ST_TOT_INT),
    $makeCell('n', $ratio($dashboardTotal, $dashboardTotal), ST_TOT_PCT),
]);
foreach ($statusKeys as $statusKey) {
    $addRow([
        $makeCell('s', ticket_status_label($statusKey), ST_TEXT),
        $makeCell('n', $dashboardStatusCounts[$statusKey], ST_INT),
        $makeCell('n', $ratio($dashboardStatusCounts[$statusKey], $dashboardTotal), ST_PCT),
    ]);
}
$addRow([]);

// Mục 2: Ticket theo tháng (dữ liệu biểu đồ cột)
$sectionRow('2. TICKET THEO THÁNG (12 THÁNG GẦN NHẤT - NHƯ DASHBOARD)', 4);
$monthHeaderRow = $addRow([
    $makeCell('s', 'Tháng', ST_HEAD),
    $makeCell('s', 'Tổng ticket', ST_HEAD),
    $makeCell('s', 'Hoàn thành', ST_HEAD),
    $makeCell('s', 'Tỷ lệ hoàn thành', ST_HEAD),
], 20);
$monthFirstRow = $monthHeaderRow + 1;
$sumTotal = 0;
$sumCompleted = 0;
foreach ($monthly as $item) {
    $sumTotal += $item['total'];
    $sumCompleted += $item['completed'];
    $addRow([
        $makeCell('s', $item['label'], ST_LABEL),
        $makeCell('n', $item['total'], ST_INT),
        $makeCell('n', $item['completed'], ST_INT),
        $makeCell('n', $ratio($item['completed'], $item['total']), ST_PCT),
    ], 32);
}
$monthLastRow = $monthHeaderRow + count($monthly);
$addRow([
    $makeCell('s', 'Tổng cộng', ST_TOT_TEXT),
    $makeCell('n', $sumTotal, ST_TOT_INT),
    $makeCell('n', $sumCompleted, ST_TOT_INT),
    $makeCell('n', $ratio($sumCompleted, $sumTotal), ST_TOT_PCT),
]);
$addRow([]);

// Mục 3: Phòng ban trên dashboard tính toàn bộ ticket.
$sectionRow('3. THỐNG KÊ THEO PHÒNG BAN (TOÀN HỆ THỐNG)', 3);
$addRow([
    $makeCell('s', 'Phòng ban', ST_HEAD),
    $makeCell('s', 'Số ticket', ST_HEAD),
    $makeCell('s', 'Tỷ lệ', ST_HEAD),
], 20);
foreach ($departments as $department => $total) {
    $addRow([
        $makeCell('s', (string)$department, ST_TEXT),
        $makeCell('n', $total, ST_INT),
        $makeCell('n', $ratio($total, $departmentTotal), ST_PCT),
    ]);
}
$addRow([
    $makeCell('s', 'Tổng cộng', ST_TOT_TEXT),
    $makeCell('n', $departmentTotal, ST_TOT_INT),
    $makeCell('n', $ratio($departmentTotal, $departmentTotal), ST_TOT_PCT),
]);
$addRow([]);

// Mục 4: Kết quả trong tháng hiện tại, cùng phạm vi với biểu đồ dashboard.
$sectionRow('4. KẾT QUẢ THÁNG NÀY (' . $dashboardMonthStart->format('m/Y') . ')', 3);
$pieHeaderRow = $addRow([
    $makeCell('s', 'Tình trạng', ST_HEAD),
    $makeCell('s', 'Số ticket', ST_HEAD),
    $makeCell('s', 'Tỷ lệ', ST_HEAD),
], 20);
$addRow([
    $makeCell('s', 'Tổng ticket', ST_TOT_TEXT),
    $makeCell('n', $dashboardMonthTotal, ST_TOT_INT),
    $makeCell('n', $ratio($dashboardMonthTotal, $dashboardMonthTotal), ST_TOT_PCT),
]);
$completed = $dashboardMonthCompleted;
$notCompleted = $dashboardMonthNotCompleted;
$addRow([
    $makeCell('s', 'Hoàn thành', ST_TEXT),
    $makeCell('n', $completed, ST_INT),
    $makeCell('n', $ratio($completed, $dashboardMonthTotal), ST_PCT),
]);
$addRow([
    $makeCell('s', 'Chưa hoàn thành', ST_TEXT),
    $makeCell('n', $notCompleted, ST_INT),
    $makeCell('n', $ratio($notCompleted, $dashboardMonthTotal), ST_PCT),
]);
$pieFirstRow = $pieHeaderRow + 2;
$pieLastRow = $pieHeaderRow + 3;

/* ------------------------------------------------------------------ */
/* 5. Biểu đồ                                                          */
/* ------------------------------------------------------------------ */
$sheetRef = static function (string $column, int $from, ?int $to = null) use ($overviewSheetName): string {
    $ref = "'" . $overviewSheetName . "'!$" . $column . '$' . $from;
    if ($to !== null) {
        $ref .= ':$' . $column . '$' . $to;
    }
    return $ref;
};
$chartTitle = static function (string $title) use ($xmlText): string {
    return '<c:title><c:tx><c:rich><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr/></a:pPr>'
        . '<a:r><a:rPr lang="vi-VN" sz="1400" b="1"/><a:t>' . $xmlText($title)
        . '</a:t></a:r></a:p></c:rich></c:tx><c:overlay val="0"/></c:title><c:autoTitleDeleted val="0"/>';
};
/**
 * Mỗi series liên kết tới ô trong sheet (strRef/numRef) kèm cache,
 * nên biểu đồ vẫn sửa được dữ liệu trực tiếp trong Excel.
 */
$chartSeries = static function (
    array $series,
    bool $isBar,
    array $seriesColors = [],
    array $pointColors = []
) use ($xmlText): string {
    $xml = '';
    foreach ($series as $index => $item) {
        $xml .= '<c:ser><c:idx val="' . $index . '"/><c:order val="' . $index . '"/>'
            . '<c:tx><c:strRef><c:f>' . $xmlText($item['nameRef']) . '</c:f><c:strCache><c:ptCount val="1"/>'
            . '<c:pt idx="0"><c:v>' . $xmlText($item['name']) . '</c:v></c:pt></c:strCache></c:strRef></c:tx>';
        if (isset($seriesColors[$index])) {
            $xml .= '<c:spPr><a:solidFill><a:srgbClr val="' . $seriesColors[$index] . '"/></a:solidFill>'
                . '<a:ln><a:noFill/></a:ln></c:spPr>';
        }
        if ($isBar) {
            $xml .= '<c:invertIfNegative val="0"/>';
        }
        foreach ($pointColors as $pointIndex => $color) {
            $xml .= '<c:dPt><c:idx val="' . $pointIndex . '"/><c:bubble3D val="0"/><c:spPr><a:solidFill>'
                . '<a:srgbClr val="' . $color . '"/></a:solidFill>'
                . '<a:ln w="12700"><a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill></a:ln></c:spPr></c:dPt>';
        }
        $xml .= '<c:cat><c:strRef><c:f>' . $xmlText($item['catRef']) . '</c:f><c:strCache><c:ptCount val="'
            . count($item['categories']) . '"/>';
        foreach ($item['categories'] as $pointIndex => $category) {
            $xml .= '<c:pt idx="' . $pointIndex . '"><c:v>' . $xmlText($category) . '</c:v></c:pt>';
        }
        $xml .= '</c:strCache></c:strRef></c:cat><c:val><c:numRef><c:f>' . $xmlText($item['valRef'])
            . '</c:f><c:numCache><c:formatCode>General</c:formatCode><c:ptCount val="'
            . count($item['values']) . '"/>';
        foreach ($item['values'] as $pointIndex => $point) {
            $xml .= '<c:pt idx="' . $pointIndex . '"><c:v>' . (float)$point . '</c:v></c:pt>';
        }
        $xml .= '</c:numCache></c:numRef></c:val></c:ser>';
    }
    return $xml;
};
// Khung ngoài của biểu đồ. Lưu ý: KHÔNG dùng <a:prstGeom> trong spPr của chartSpace
// (sai thứ tự phần tử là nguyên nhân Excel xóa drawing1.xml).
$chartFrame = static function (string $chartInner): string {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<c:chartSpace xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart"'
        . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<c:roundedCorners val="0"/>'
        . '<c:chart>' . $chartInner . '</c:chart>'
        . '<c:spPr><a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill>'
        . '<a:ln w="9525"><a:solidFill><a:srgbClr val="BFBFBF"/></a:solidFill></a:ln></c:spPr>'
        . '</c:chartSpace>';
};

$months = array_column($monthly, 'label');
$chart1Series = [
    [
        'name' => 'Tổng ticket',
        'nameRef' => $sheetRef('B', $monthHeaderRow),
        'categories' => $months,
        'catRef' => $sheetRef('A', $monthFirstRow, $monthLastRow),
        'values' => array_column($monthly, 'total'),
        'valRef' => $sheetRef('B', $monthFirstRow, $monthLastRow),
    ],
    [
        'name' => 'Hoàn thành',
        'nameRef' => $sheetRef('C', $monthHeaderRow),
        'categories' => $months,
        'catRef' => $sheetRef('A', $monthFirstRow, $monthLastRow),
        'values' => array_column($monthly, 'completed'),
        'valRef' => $sheetRef('C', $monthFirstRow, $monthLastRow),
    ],
];
$chart1 = $chartFrame(
    $chartTitle('Thống kê ticket theo tháng (12 tháng gần nhất)')
    . '<c:plotArea><c:layout/><c:barChart><c:barDir val="col"/><c:grouping val="clustered"/>'
    . '<c:varyColors val="0"/>' . $chartSeries($chart1Series, true, ['347FB5', '40A77A'])
    . '<c:dLbls><c:numFmt formatCode="0;-0;;@" sourceLinked="0"/><c:spPr><a:noFill/><a:ln><a:noFill/></a:ln></c:spPr>'
    . '<c:dLblPos val="outEnd"/><c:showLegendKey val="0"/><c:showVal val="1"/><c:showCatName val="0"/>'
    . '<c:showSerName val="0"/><c:showPercent val="0"/><c:showBubbleSize val="0"/></c:dLbls>'
    . '<c:gapWidth val="70"/><c:overlap val="-5"/>'
    . '<c:axId val="10001"/><c:axId val="10002"/></c:barChart>'
    . '<c:catAx><c:axId val="10001"/><c:scaling><c:orientation val="minMax"/></c:scaling><c:delete val="0"/>'
    . '<c:axPos val="b"/><c:numFmt formatCode="General" sourceLinked="0"/>'
    . '<c:majorTickMark val="none"/><c:minorTickMark val="none"/><c:tickLblPos val="low"/>'
    . '<c:spPr><a:ln w="9525"><a:solidFill><a:srgbClr val="808080"/></a:solidFill></a:ln></c:spPr>'
    . '<c:txPr><a:bodyPr rot="0" vert="horz"/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="900"/></a:pPr>'
    . '<a:endParaRPr lang="vi-VN"/></a:p></c:txPr>'
    . '<c:crossAx val="10002"/><c:crosses val="autoZero"/><c:auto val="1"/><c:lblAlgn val="ctr"/>'
    . '<c:lblOffset val="100"/><c:noMultiLvlLbl val="0"/></c:catAx>'
    . '<c:valAx><c:axId val="10002"/><c:scaling><c:orientation val="minMax"/><c:min val="0"/></c:scaling>'
    . '<c:delete val="0"/><c:axPos val="l"/>'
    . '<c:majorGridlines><c:spPr><a:ln w="6350"><a:solidFill><a:srgbClr val="D9D9D9"/></a:solidFill></a:ln></c:spPr></c:majorGridlines>'
    . '<c:title><c:tx><c:rich><a:bodyPr rot="-5400000" vert="horz"/><a:lstStyle/><a:p><a:pPr><a:defRPr/></a:pPr>'
    . '<a:r><a:rPr lang="vi-VN" sz="900" b="0"/><a:t>Số ticket</a:t></a:r></a:p></c:rich></c:tx><c:overlay val="0"/></c:title>'
    . '<c:numFmt formatCode="0" sourceLinked="0"/><c:majorTickMark val="none"/><c:minorTickMark val="none"/>'
    . '<c:tickLblPos val="nextTo"/><c:spPr><a:ln><a:noFill/></a:ln></c:spPr>'
    . '<c:crossAx val="10001"/><c:crosses val="autoZero"/><c:crossBetween val="between"/></c:valAx>'
    . '</c:plotArea>'
    . '<c:legend><c:legendPos val="b"/><c:overlay val="0"/></c:legend>'
    . '<c:plotVisOnly val="1"/><c:dispBlanksAs val="gap"/>'
);

$chart2Series = [[
    'name' => 'Số ticket',
    'nameRef' => $sheetRef('B', $pieHeaderRow),
    'categories' => ['Hoàn thành', 'Chưa hoàn thành'],
    'catRef' => $sheetRef('A', $pieFirstRow, $pieLastRow),
    'values' => [$completed, $notCompleted],
    'valRef' => $sheetRef('B', $pieFirstRow, $pieLastRow),
]];
$chart2 = $chartFrame(
    $chartTitle('Kết quả tháng này (' . $dashboardMonthStart->format('m/Y') . ')')
    . '<c:plotArea><c:layout/><c:doughnutChart><c:varyColors val="1"/>'
    . $chartSeries($chart2Series, false, [], ['2EA368', '347FB5'])
    . '<c:dLbls><c:numFmt formatCode="0%" sourceLinked="0"/>'
    . '<c:spPr><a:noFill/><a:ln><a:noFill/></a:ln></c:spPr>'
    . '<c:txPr><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="1000" b="1">'
    . '<a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill></a:defRPr></a:pPr>'
    . '<a:endParaRPr lang="vi-VN"/></a:p></c:txPr>'
    . '<c:showLegendKey val="0"/><c:showVal val="0"/><c:showCatName val="0"/>'
    . '<c:showSerName val="0"/><c:showPercent val="1"/><c:showBubbleSize val="0"/>'
    . '<c:separator>&#10;</c:separator><c:showLeaderLines val="0"/></c:dLbls>'
    . '<c:firstSliceAng val="0"/><c:holeSize val="55"/></c:doughnutChart></c:plotArea>'
    . '<c:legend><c:legendPos val="b"/><c:overlay val="0"/></c:legend>'
    . '<c:plotVisOnly val="1"/><c:dispBlanksAs val="gap"/>'
);

/* Drawing: dùng oneCellAnchor để kích thước biểu đồ cố định, đặt bên phải bảng số liệu */
$anchor = static function (int $id, string $name, int $col, int $row, int $cx, int $cy, string $rid) use ($xmlText): string {
    return '<xdr:oneCellAnchor><xdr:from><xdr:col>' . $col . '</xdr:col><xdr:colOff>0</xdr:colOff>'
        . '<xdr:row>' . $row . '</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from>'
        . '<xdr:ext cx="' . $cx . '" cy="' . $cy . '"/>'
        . '<xdr:graphicFrame macro=""><xdr:nvGraphicFramePr><xdr:cNvPr id="' . $id . '" name="' . $xmlText($name) . '"/>'
        . '<xdr:cNvGraphicFramePr/></xdr:nvGraphicFramePr>'
        . '<xdr:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></xdr:xfrm>'
        . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart">'
        . '<c:chart xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:id="' . $rid . '"/>'
        . '</a:graphicData></a:graphic></xdr:graphicFrame><xdr:clientData/></xdr:oneCellAnchor>';
};
$px = 9525; // EMU mỗi pixel
$drawingXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing"'
    . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
    . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . $anchor(2, 'Biểu đồ cột', 5, 3, 760 * $px, 380 * $px, 'rId1')
    . $anchor(3, 'Biểu đồ tròn', 5, 22, 560 * $px, 340 * $px, 'rId2')
    . '</xdr:wsDr>';

/* ------------------------------------------------------------------ */
/* 6. Sheet "Ticket trong tháng"                                       */
/* ------------------------------------------------------------------ */
$head = static fn(string $text): array => ['s', $text, ST_HEAD];
$body = static fn(mixed $text): array => ['s', (string)($text ?? ''), ST_BODY];

$ticketRows = [[
    $head('Mã ticket'), $head('Họ tên'), $head('Phòng ban'), $head('Zalo liên hệ'), $head('Email'),
    $head('Loại'), $head('Mức độ ưu tiên'), $head('Phạm vi ảnh hưởng'), $head('Khả năng làm việc'),
    $head('Hạn phản hồi'), $head('Hạn xử lý'), $head('Tiêu đề'), $head('Mô tả'), $head('Trạng thái'),
    $head('Ghi chú phản hồi'), $head('Người xử lý'), $head('Ngày tạo'), $head('Ngày cập nhật'),
]];
foreach ($tickets as $row) {
    $ticketRows[] = [
        $body($row['ticket_code']), $body($row['full_name']), $body($row['department']),
        $body($row['contact']), $body($row['email']),
        $body(ticket_type_label((string)$row['type'])),
        $body(ticket_priority_label((string)$row['priority'])),
        $body(ticket_impact_scopes()[$row['impact_scope']] ?? $row['impact_scope']),
        $body(ticket_work_abilities()[$row['work_ability']] ?? $row['work_ability']),
        $body($row['response_due_at']), $body($row['resolution_due_at']),
        $body($row['title']), $body($row['description']),
        $body(ticket_status_label((string)$row['status'])),
        $body($row['admin_note']), $body($row['assignee']),
        $body($row['created_at']), $body($row['updated_at']),
    ];
}
$ticketLastRow = count($ticketRows);

/* ------------------------------------------------------------------ */
/* 7. Các phần còn lại của gói .xlsx                                   */
/* ------------------------------------------------------------------ */
$workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
    . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<bookViews><workbookView activeTab="0"/></bookViews>'
    . '<sheets><sheet name="' . $xmlText($overviewSheetName) . '" sheetId="1" r:id="rId1"/>'
    . '<sheet name="' . $xmlText($ticketSheetName) . '" sheetId="2" r:id="rId2"/></sheets>'
    . '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="1" hidden="1">'
    . $xmlText("'" . $ticketSheetName . "'!") . '$A$1:$R$' . $ticketLastRow
    . '</definedName></definedNames></workbook>';

$contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>'
    . '<Override PartName="/xl/charts/chart1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/>'
    . '<Override PartName="/xl/charts/chart2.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/>'
    . '</Types>';

$stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<numFmts count="1"><numFmt numFmtId="164" formatCode="0.0%"/></numFmts>'
    . '<fonts count="5">'
    . '<font><sz val="11"/><color rgb="FF000000"/><name val="Calibri"/><family val="2"/></font>'                 // 0 thường
    . '<font><b/><sz val="11"/><color rgb="FF000000"/><name val="Calibri"/><family val="2"/></font>'             // 1 đậm
    . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>'             // 2 đậm trắng
    . '<font><b/><sz val="16"/><color rgb="FF1F4E78"/><name val="Calibri"/><family val="2"/></font>'             // 3 tiêu đề
    . '<font><i/><sz val="10"/><color rgb="FF7F7F7F"/><name val="Calibri"/><family val="2"/></font>'             // 4 phụ đề
    . '</fonts>'
    . '<fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FFDDEBF7"/><bgColor indexed="64"/></patternFill></fill>'  // 2 xanh nhạt
    . '<fill><patternFill patternType="solid"><fgColor rgb="FF1F4E78"/><bgColor indexed="64"/></patternFill></fill>'  // 3 xanh đậm
    . '<fill><patternFill patternType="solid"><fgColor rgb="FFF2F2F2"/><bgColor indexed="64"/></patternFill></fill>'  // 4 xám nhạt
    . '</fills>'
    . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
    . '<border><left style="thin"><color rgb="FFB4C0CC"/></left><right style="thin"><color rgb="FFB4C0CC"/></right>'
    . '<top style="thin"><color rgb="FFB4C0CC"/></top><bottom style="thin"><color rgb="FFB4C0CC"/></bottom><diagonal/></border></borders>'
    . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
    . '<cellXfs count="13">'
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'                                                                     // 0
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>' // 1 ST_TEXT
    . '<xf numFmtId="3" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' // 2 ST_INT
    . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="center"/></xf>'    // 3 ST_TITLE
    . '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>' // 4 ST_SECTION
    . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' // 5 ST_HEAD
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' // 6 ST_LABEL
    . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' // 7 ST_PCT
    . '<xf numFmtId="0" fontId="1" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>' // 8 ST_TOT_TEXT
    . '<xf numFmtId="3" fontId="1" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' // 9 ST_TOT_INT
    . '<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="center"/></xf>' // 10 ST_SUB
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>' // 11 ST_BODY
    . '<xf numFmtId="164" fontId="1" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' // 12 ST_TOT_PCT
    . '</cellXfs>'
    . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

$relsHeader = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';

$zipFiles = [
    '[Content_Types].xml' => $contentTypesXml,
    '_rels/.rels' => $relsHeader
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>',
    'xl/workbook.xml' => $workbookXml,
    'xl/_rels/workbook.xml.rels' => $relsHeader
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>',
    'xl/styles.xml' => $stylesXml,
    'xl/worksheets/sheet1.xml' => $sheetXml($overviewRows, [
        'drawing' => true,
        'widths' => [38, 16, 16, 18, 3],
        'merges' => $overviewMerges,
        'heights' => $overviewHeights,
        'gridlines' => false,
        'tabColor' => 'FF1F4E78',
    ]),
    'xl/worksheets/sheet2.xml' => $sheetXml($ticketRows, [
        'widths' => [14, 24, 28, 16, 28, 18, 18, 24, 24, 22, 22, 40, 60, 18, 50, 20, 22, 22],
        'autoFilter' => true,
        'freeze' => true,
        'heights' => [1 => 30],
        'tabColor' => 'FF70AD47',
    ]),
    'xl/worksheets/_rels/sheet1.xml.rels' => $relsHeader
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/>'
        . '</Relationships>',
    'xl/drawings/drawing1.xml' => $drawingXml,
    'xl/drawings/_rels/drawing1.xml.rels' => $relsHeader
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart" Target="../charts/chart1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart" Target="../charts/chart2.xml"/>'
        . '</Relationships>',
    'xl/charts/chart1.xml' => $chart1,
    'xl/charts/chart2.xml' => $chart2,
];

/* ------------------------------------------------------------------ */
/* 8. Đóng gói và tải về                                               */
/* ------------------------------------------------------------------ */
$zipPath = tempnam(sys_get_temp_dir(), 'ticket-report-');
if ($zipPath === false) {
    http_response_code(500);
    exit('Không thể tạo tệp báo cáo lúc này.');
}
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
    unlink($zipPath);
    http_response_code(500);
    exit('Không thể tạo tệp báo cáo lúc này.');
}
foreach ($zipFiles as $path => $contents) {
    if (!$zip->addFromString($path, $contents)) {
        $zip->close();
        unlink($zipPath);
        http_response_code(500);
        exit('Không thể hoàn tất tệp báo cáo Excel.');
    }
}
if (!$zip->close()) {
    unlink($zipPath);
    http_response_code(500);
    exit('Không thể hoàn tất tệp báo cáo Excel.');
}

$filename = 'bao-cao-ticket-' . $monthStart->format('Y-m') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($zipPath));
header('X-Content-Type-Options: nosniff');
readfile($zipPath);
unlink($zipPath);