<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/functions.php';
require_permission('tickets.export');

if (!class_exists(ZipArchive::class)) {
    http_response_code(500);
    exit('Máy chủ chưa hỗ trợ tạo tệp Excel.');
}

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
$chartStart = $monthStart->modify('-11 months');
$pdo = db();

$statusKeys = ['new', 'in_progress', 'completed', 'rejected'];
$statusCounts = array_fill_keys($statusKeys, 0);
$statusStmt = $pdo->prepare(
    'SELECT status, COUNT(*) AS total FROM tickets WHERE created_at >= ? AND created_at < ? GROUP BY status'
);
$statusStmt->execute([$monthStart->format('Y-m-d H:i:s'), $nextMonth->format('Y-m-d H:i:s')]);
foreach ($statusStmt->fetchAll() as $row) {
    if (isset($statusCounts[$row['status']])) {
        $statusCounts[$row['status']] = (int)$row['total'];
    }
}
$totalStmt = $pdo->prepare('SELECT COUNT(*) FROM tickets WHERE created_at >= ? AND created_at < ?');
$totalStmt->execute([$monthStart->format('Y-m-d H:i:s'), $nextMonth->format('Y-m-d H:i:s')]);
$monthTotal = (int)$totalStmt->fetchColumn();

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
    $nextMonth->format('Y-m-d H:i:s'),
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
$departmentStmt = $pdo->prepare(
    'SELECT department, COUNT(*) AS total FROM tickets WHERE created_at >= ? AND created_at < ? GROUP BY department'
);
$departmentStmt->execute([$monthStart->format('Y-m-d H:i:s'), $nextMonth->format('Y-m-d H:i:s')]);
foreach ($departmentStmt->fetchAll() as $row) {
    if (array_key_exists($row['department'], $departments)) {
        $departments[$row['department']] = (int)$row['total'];
    }
}

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
$makeCell = static function (string $value, int|string|float|null $content = null): array {
    return [$value, $content];
};
$sheetXml = static function (
    array $rows,
    bool $hasDrawing = false,
    array $columnWidths = [],
    bool $autoFilter = false,
    bool $freezeFirstRow = false
) use ($xmlText, $columnName): string {
    $lastRow = max(1, count($rows));
    $lastColumn = 1;
    foreach ($rows as $row) {
        $lastColumn = max($lastColumn, count($row));
    }
    $sheetView = '<sheetView workbookViewId="0">';
    if ($freezeFirstRow) {
        $sheetView .= '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            . '<selection pane="bottomLeft" activeCell="A2" sqref="A2"/>';
    }
    $sheetView .= '</sheetView>';
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<dimension ref="A1:' . $columnName($lastColumn) . $lastRow . '"/>'
        . '<sheetViews>' . $sheetView . '</sheetViews><sheetFormatPr defaultRowHeight="15"/>';
    if ($columnWidths) {
        $xml .= '<cols>';
        foreach ($columnWidths as $index => $width) {
            $column = $index + 1;
            $xml .= '<col min="' . $column . '" max="' . $column . '" width="' . (float)$width . '" customWidth="1"/>';
        }
        $xml .= '</cols>';
    }
    $xml .= '<sheetData>';
    foreach ($rows as $rowIndex => $row) {
        $excelRow = $rowIndex + 1;
        $xml .= '<row r="' . $excelRow . '">';
        foreach ($row as $columnIndex => $cell) {
            if ($cell === null) {
                continue;
            }
            [$type, $value] = $cell;
            $reference = $columnName($columnIndex + 1) . $excelRow;
            if ($type === 'n') {
                $xml .= '<c r="' . $reference . '" t="n"><v>' . (float)$value . '</v></c>';
            } else {
                $xml .= '<c r="' . $reference . '" t="inlineStr"><is><t xml:space="preserve">'
                    . $xmlText($value) . '</t></is></c>';
            }
        }
        $xml .= '</row>';
    }
    $xml .= '</sheetData>';
    if ($autoFilter && $lastRow > 0) {
        $xml .= '<autoFilter ref="A1:' . $columnName($lastColumn) . $lastRow . '"/>';
    }
    $xml .= '<pageMargins left="0.25" right="0.25" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>';
    if ($hasDrawing) {
        $xml .= '<drawing r:id="rId1"/>';
    }
    return $xml . '</worksheet>';
};
$chartTitle = static function (string $title) use ($xmlText): string {
    return '<c:title><c:tx><c:rich><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr/></a:pPr>'
        . '<a:r><a:rPr lang="vi-VN" sz="1400" b="1"/><a:t>' . $xmlText($title)
        . '</a:t></a:r><a:endParaRPr lang="vi-VN"/></a:p></c:rich></c:tx><c:overlay val="0"/></c:title>';
};
$chartSeries = static function (array $series, bool $numericValues): string {
    $xml = '';
    foreach ($series as $index => $item) {
        $literalType = $numericValues ? 'numLit' : 'strLit';
        $xml .= '<c:ser><c:idx val="' . $index . '"/><c:order val="' . $index . '"/>'
            . '<c:tx><c:v>'
            . htmlspecialchars($item['name'], ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</c:v></c:tx>'
            . '<c:cat><c:strLit><c:ptCount val="' . count($item['categories']) . '"/>';
        foreach ($item['categories'] as $pointIndex => $category) {
            $xml .= '<c:pt idx="' . $pointIndex . '"><c:v>'
                . htmlspecialchars((string)$category, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</c:v></c:pt>';
        }
        $xml .= '</c:strLit></c:cat><c:val><c:' . $literalType . '><c:formatCode>General</c:formatCode><c:ptCount val="'
            . count($item['values']) . '"/>';
        foreach ($item['values'] as $pointIndex => $point) {
            $xml .= '<c:pt idx="' . $pointIndex . '"><c:v>' . (float)$point . '</c:v></c:pt>';
        }
        $xml .= '</c:' . $literalType . '></c:val></c:ser>';
    }
    return $xml;
};
$months = array_column($monthly, 'label');
$chart1Series = [
    [
        'name' => 'Tổng ticket',
        'categories' => $months,
        'values' => array_column($monthly, 'total'),
    ],
    [
        'name' => 'Hoàn thành',
        'categories' => $months,
        'values' => array_column($monthly, 'completed'),
    ],
];
$chart1 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<c:chartSpace xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart"'
    . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
    . '<c:chart>' . $chartTitle('Ticket theo tháng (12 tháng đến kỳ báo cáo)')
    . '<c:plotArea><c:layout/><c:barChart><c:barDir val="col"/><c:grouping val="clustered"/>'
    . '<c:varyColors val="0"/>' . $chartSeries($chart1Series, true)
    . '<c:gapWidth val="80"/><c:overlap val="0"/>'
    . '<c:axId val="10001"/><c:axId val="10002"/></c:barChart>'
    . '<c:catAx><c:axId val="10001"/><c:scaling><c:orientation val="minMax"/></c:scaling>'
    . '<c:axPos val="b"/><c:tickLblPos val="nextTo"/><c:crossAx val="10002"/>'
    . '<c:crosses val="autoZero"/><c:auto val="1"/><c:lblAlgn val="ctr"/><c:lblOffset val="100"/></c:catAx>'
    . '<c:valAx><c:axId val="10002"/><c:scaling><c:orientation val="minMax"/></c:scaling>'
    . '<c:axPos val="l"/><c:majorGridlines/><c:numFmt formatCode="0" sourceLinked="0"/>'
    . '<c:tickLblPos val="nextTo"/><c:crossAx val="10001"/><c:crosses val="autoZero"/>'
    . '<c:crossBetween val="between"/></c:valAx></c:plotArea>'
    . '<c:legend><c:legendPos val="b"/><c:overlay val="0"/></c:legend>'
    . '<c:plotVisOnly val="1"/><c:dispBlanksAs val="gap"/></c:chart></c:chartSpace>';
$completed = $statusCounts['completed'];
$notCompleted = $monthTotal - $completed;
$chart2Series = [[
    'name' => 'Tình trạng',
    'categories' => ['Hoàn thành', 'Chưa hoàn thành'],
    'values' => [$completed, $notCompleted],
]];
$chart2 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<c:chartSpace xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart"'
    . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
    . '<c:chart>' . $chartTitle('Kết quả ticket tháng ' . $monthStart->format('m/Y'))
    . '<c:plotArea><c:layout/><c:pieChart><c:varyColors val="1"/>'
    . $chartSeries($chart2Series, true)
    . '<c:dLbls><c:showLegendKey val="0"/><c:showVal val="1"/><c:showPercent val="1"/>'
    . '<c:showCatName val="1"/><c:showSerName val="0"/></c:dLbls>'
    . '<c:firstSliceAng val="270"/></c:pieChart></c:plotArea>'
    . '<c:legend><c:legendPos val="b"/><c:overlay val="0"/></c:legend>'
    . '<c:plotVisOnly val="1"/><c:dispBlanksAs val="gap"/></c:chart></c:chartSpace>';

$overviewRows = [
    [$makeCell('s', 'BÁO CÁO TICKET THÁNG ' . $monthStart->format('m/Y'))],
    [],
    [$makeCell('s', 'Tổng quan tháng'), $makeCell('s', 'Số lượng')],
    [$makeCell('s', 'Tổng ticket'), $makeCell('n', $monthTotal)],
];
foreach ($statusKeys as $statusKey) {
    $overviewRows[] = [
        $makeCell('s', ticket_status_label($statusKey)),
        $makeCell('n', $statusCounts[$statusKey]),
    ];
}
$overviewRows[] = [];
$overviewRows[] = [$makeCell('s', 'Dữ liệu biểu đồ cột: 12 tháng tính đến tháng báo cáo')];
$overviewRows[] = [$makeCell('s', 'Tháng'), $makeCell('s', 'Tổng ticket'), $makeCell('s', 'Hoàn thành')];
foreach ($monthly as $item) {
    $overviewRows[] = [
        $makeCell('s', $item['label']),
        $makeCell('n', $item['total']),
        $makeCell('n', $item['completed']),
    ];
}
$overviewRows[] = [];
$overviewRows[] = [$makeCell('s', 'Thống kê theo phòng ban trong tháng báo cáo')];
$overviewRows[] = [$makeCell('s', 'Phòng ban'), $makeCell('s', 'Số ticket')];
foreach ($departments as $department => $total) {
    $overviewRows[] = [$makeCell('s', $department), $makeCell('n', $total)];
}
$overviewRows[] = [];
$overviewRows[] = [$makeCell('s', 'Dữ liệu biểu đồ tròn')];
$overviewRows[] = [$makeCell('s', 'Tình trạng'), $makeCell('s', 'Số ticket')];
$overviewRows[] = [$makeCell('s', 'Hoàn thành'), $makeCell('n', $completed)];
$overviewRows[] = [$makeCell('s', 'Chưa hoàn thành'), $makeCell('n', $notCompleted)];

$ticketRows = [[
    $makeCell('s', 'Mã ticket'), $makeCell('s', 'Họ tên'), $makeCell('s', 'Phòng ban'),
    $makeCell('s', 'Zalo liên hệ'), $makeCell('s', 'Email'), $makeCell('s', 'Loại'),
    $makeCell('s', 'Mức độ ưu tiên'), $makeCell('s', 'Phạm vi ảnh hưởng'),
    $makeCell('s', 'Khả năng làm việc'), $makeCell('s', 'Hạn phản hồi'),
    $makeCell('s', 'Hạn xử lý'), $makeCell('s', 'Tiêu đề'), $makeCell('s', 'Mô tả'),
    $makeCell('s', 'Trạng thái'), $makeCell('s', 'Ghi chú phản hồi'),
    $makeCell('s', 'Người xử lý'), $makeCell('s', 'Ngày tạo'), $makeCell('s', 'Ngày cập nhật'),
]];
foreach ($tickets as $row) {
    $ticketRows[] = [
        $makeCell('s', $row['ticket_code']), $makeCell('s', $row['full_name']),
        $makeCell('s', $row['department']), $makeCell('s', $row['contact']),
        $makeCell('s', $row['email']), $makeCell('s', ticket_type_label((string)$row['type'])),
        $makeCell('s', ticket_priority_label((string)$row['priority'])),
        $makeCell('s', ticket_impact_scopes()[$row['impact_scope']] ?? $row['impact_scope']),
        $makeCell('s', ticket_work_abilities()[$row['work_ability']] ?? $row['work_ability']),
        $makeCell('s', $row['response_due_at']), $makeCell('s', $row['resolution_due_at']),
        $makeCell('s', $row['title']), $makeCell('s', $row['description']),
        $makeCell('s', ticket_status_label((string)$row['status'])),
        $makeCell('s', $row['admin_note']), $makeCell('s', $row['assignee']),
        $makeCell('s', $row['created_at']), $makeCell('s', $row['updated_at']),
    ];
}

$drawingXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing"'
    . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
    . '<xdr:twoCellAnchor><xdr:from><xdr:col>4</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>1</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from>'
    . '<xdr:to><xdr:col>12</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>17</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:to>'
    . '<xdr:graphicFrame macro=""><xdr:nvGraphicFramePr><xdr:cNvPr id="2" name="Biểu đồ cột"/>'
    . '<xdr:cNvGraphicFramePr/></xdr:nvGraphicFramePr><xdr:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></xdr:xfrm>'
    . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart">'
    . '<c:chart xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:id="rId1"/>'
    . '</a:graphicData></a:graphic></xdr:graphicFrame><xdr:clientData/></xdr:twoCellAnchor>'
    . '<xdr:twoCellAnchor><xdr:from><xdr:col>4</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>19</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from>'
    . '<xdr:to><xdr:col>12</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>34</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:to>'
    . '<xdr:graphicFrame macro=""><xdr:nvGraphicFramePr><xdr:cNvPr id="3" name="Biểu đồ tròn"/>'
    . '<xdr:cNvGraphicFramePr/></xdr:nvGraphicFramePr><xdr:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></xdr:xfrm>'
    . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart">'
    . '<c:chart xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:id="rId2"/>'
    . '</a:graphicData></a:graphic></xdr:graphicFrame><xdr:clientData/></xdr:twoCellAnchor></xdr:wsDr>';
$workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
    . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheets><sheet name="Tổng quan" sheetId="1" r:id="rId1"/>'
    . '<sheet name="Ticket trong tháng" sheetId="2" r:id="rId2"/></sheets></workbook>';
$contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>'
    . '<Override PartName="/xl/charts/chart1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/>'
    . '<Override PartName="/xl/charts/chart2.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/>'
    . '</Types>';
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
$zipFiles = [
    '[Content_Types].xml' => $contentTypesXml,
    '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>',
    'xl/workbook.xml' => $workbookXml,
    'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
        . '</Relationships>',
    'xl/worksheets/sheet1.xml' => $sheetXml($overviewRows, true, [42, 18, 18]),
    'xl/worksheets/sheet2.xml' => $sheetXml(
        $ticketRows,
        false,
        [14, 24, 28, 16, 28, 18, 18, 24, 24, 22, 22, 40, 60, 18, 50, 20, 22, 22],
        true,
        true
    ),
    'xl/worksheets/_rels/sheet1.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/>'
        . '</Relationships>',
    'xl/drawings/drawing1.xml' => $drawingXml,
    'xl/drawings/_rels/drawing1.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart" Target="../charts/chart1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart" Target="../charts/chart2.xml"/>'
        . '</Relationships>',
    'xl/charts/chart1.xml' => $chart1,
    'xl/charts/chart2.xml' => $chart2,
];
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
