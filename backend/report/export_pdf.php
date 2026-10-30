<?php
require_once __DIR__ . '/../includes/admin_only.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rental_status.php';
require_once __DIR__ . '/../includes/pdf_report_helper.php';

sync_rental_and_car_status($conn);

/**
 * Rental report filters. KEEP IDENTICAL to rental_report_filters() in report.php.
 * Returns [whereSql, bindTypes, bindParams, cleanFilters].
 */
function rental_report_filters(array $in): array {
    $status = (string)($in['status'] ?? '');
    $from = (string)($in['from_date'] ?? '');
    $to = (string)($in['to_date'] ?? '');
    $search = trim((string)($in['reg_no'] ?? ''));
    if (!in_array($status, ['Pending', 'Active', 'Completed', 'Canceled', 'Not returned'], true)) $status = '';
    if (!cr_valid_date($from)) $from = '';
    if (!cr_valid_date($to)) $to = '';

    $where = ["r.status <> 'Deleted'"];
    $types = '';
    $params = [];
    if ($status === 'Not returned') {
        $where[] = "r.status = 'Completed' AND r.returned_at IS NULL AND r.end_date < CURDATE()";
    } elseif ($status !== '') {
        $where[] = 'r.status = ?'; $types .= 's'; $params[] = $status;
    }
    // A rental is included when it overlaps the chosen period.
    if ($to !== '') { $where[] = 'r.start_date <= ?'; $types .= 's'; $params[] = $to; }
    if ($from !== '') { $where[] = 'r.end_date >= ?'; $types .= 's'; $params[] = $from; }
    if ($search !== '') {
        $where[] = '(ca.reg_no LIKE ? OR c.full_name LIKE ?)';
        $like = '%' . $search . '%';
        $types .= 'ss'; $params[] = $like; $params[] = $like;
    }
    return ['WHERE ' . implode(' AND ', $where), $types, $params, ['status' => $status, 'from_date' => $from, 'to_date' => $to, 'reg_no' => $search]];
}

[$where, $types, $params, $filters] = rental_report_filters($_GET);
$status_filter = $filters['status'];
$from_date = $filters['from_date'];
$to_date = $filters['to_date'];
$reg_no = $filters['reg_no'];

$stmt = $conn->prepare("SELECT r.id, r.start_date, r.end_date, r.total_cost, r.status AS rental_status, r.returned_at,
               c.full_name AS customer_name, ca.reg_no AS car_reg, m.name AS make_name, mo.name AS model_name, y.year AS car_year
        FROM rentals r
        JOIN customers c ON r.customer_id = c.id
        JOIN cars ca ON r.cars_id = ca.id
        LEFT JOIN makes m ON ca.make_id = m.id
        LEFT JOIN models mo ON ca.model_id = mo.id
        LEFT JOIN car_years y ON ca.year_id = y.id
        $where
        ORDER BY r.start_date DESC, r.id DESC");
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();
$today = cr_today();

$pdf = new CarRentalReportPdf('P');
$pdf->AddPage();
$pdf->reportTitle('Rental Report', ['Status' => $status_filter ?: 'All', 'From' => $from_date, 'To' => $to_date, 'Reg No / Customer' => $reg_no]);

$w = [8, 34, 36, 25, 20, 20, 24, 23];
$headers = ['#','Customer','Car','Reg No','Start','End','Cost (' . $pdf->safeText(cr_setting('currency_code'), 5) . ')','Status'];
$drawHeader = function() use ($pdf, $w, $headers) {
    $pdf->SetFont('Arial','B',8);
    $pdf->SetFillColor(44, 62, 80);
    $pdf->SetTextColor(255,255,255);
    foreach($headers as $i=>$h) $pdf->Cell($w[$i],7,$h,1,0,'C',true);
    $pdf->Ln();
    $pdf->SetFont('Arial','',8);
    $pdf->SetTextColor(0,0,0);
};
$drawHeader();

$i=1; $total=0.0; $counted=0;
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        if ($pdf->GetY() > 260) { $pdf->AddPage(); $drawHeader(); }
        if ($row['rental_status'] !== 'Canceled') { $total += (float)$row['total_cost']; $counted++; }
        $carName = trim(($row['make_name'] ?? '') . ' ' . ($row['model_name'] ?? '') . ' ' . ($row['car_year'] ?? ''));
        if ($carName === '') $carName = $row['car_reg'];
        $statusText = ($row['rental_status'] === 'Completed' && $row['returned_at'] === null && $row['end_date'] < $today) ? 'Not returned' : $row['rental_status'];
        $pdf->Cell($w[0],6,$i++,1);
        $pdf->Cell($w[1],6,$pdf->safeText($row['customer_name'], 24),1);
        $pdf->Cell($w[2],6,$pdf->safeText($carName, 24),1);
        $pdf->Cell($w[3],6,$pdf->safeText($row['car_reg'], 15),1);
        $pdf->Cell($w[4],6,$pdf->safeText(cr_date($row['start_date']), 12),1);
        $pdf->Cell($w[5],6,$pdf->safeText(cr_date($row['end_date']), 12),1);
        $pdf->Cell($w[6],6,$pdf->safeText(cr_number($row['total_cost']), 20),1,0,'R');
        $pdf->Cell($w[7],6,$pdf->safeText($statusText, 14),1,1);
    }
    if ($pdf->GetY() > 260) { $pdf->AddPage(); $drawHeader(); }
    $pdf->SetFont('Arial','B',8);
    $pdf->Cell(array_sum(array_slice($w,0,6)),7,$pdf->safeText('Total (' . $counted . ' rentals, canceled excluded)', 60),1,0,'R');
    $pdf->Cell($w[6],7,$pdf->safeText(money_text($total), 30),1,0,'R');
    $pdf->Cell($w[7],7,'',1,1);
} else {
    $pdf->Cell(array_sum($w),7,'No rentals found.',1,1,'C');
}

$pdf->Output('I','rental_report.pdf');
exit;
