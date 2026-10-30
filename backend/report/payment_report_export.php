<?php
require_once __DIR__ . '/../includes/admin_only.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rental_status.php';
require_once __DIR__ . '/../includes/pdf_report_helper.php';

/**
 * Payment report filters. KEEP IDENTICAL to payment_report_filters() in payment_report.php.
 * Returns [whereSql, bindTypes, bindParams, cleanFilters].
 */
function payment_report_filters(array $in): array {
    $from = (string)($in['from_date'] ?? '');
    $to = (string)($in['to_date'] ?? '');
    $search = trim((string)($in['reg_no'] ?? ''));
    if (!cr_valid_date($from)) $from = '';
    if (!cr_valid_date($to)) $to = '';

    $where = ["r.status <> 'Deleted'"];
    $types = '';
    $params = [];
    if ($from !== '') { $where[] = 'p.payment_date >= ?'; $types .= 's'; $params[] = $from; }
    if ($to !== '') { $where[] = 'p.payment_date <= ?'; $types .= 's'; $params[] = $to; }
    if ($search !== '') {
        $where[] = '(ca.reg_no LIKE ? OR c.full_name LIKE ?)';
        $like = '%' . $search . '%';
        $types .= 'ss'; $params[] = $like; $params[] = $like;
    }
    return ['WHERE ' . implode(' AND ', $where), $types, $params, ['from_date' => $from, 'to_date' => $to, 'reg_no' => $search]];
}

[$where, $types, $params, $filters] = payment_report_filters($_GET);
$from_date = $filters['from_date'];
$to_date = $filters['to_date'];
$reg_no = $filters['reg_no'];

$stmt = $conn->prepare("SELECT p.id, p.payment_date, p.amount, r.id AS rental_id, c.full_name, ca.reg_no, m.name AS make_name, mo.name AS model_name, y.year AS car_year
    FROM payment p
    JOIN rentals r ON p.rental_id = r.id
    JOIN customers c ON r.customer_id = c.id
    JOIN cars ca ON r.cars_id = ca.id
    LEFT JOIN makes m ON ca.make_id = m.id
    LEFT JOIN models mo ON ca.model_id = mo.id
    LEFT JOIN car_years y ON ca.year_id = y.id
    $where
    ORDER BY p.payment_date DESC, p.id DESC");
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

$pdf = new CarRentalReportPdf('P');
$pdf->AddPage();
$pdf->reportTitle('Payment Report', ['From' => $from_date, 'To' => $to_date, 'Reg No / Customer' => $reg_no]);

$w = [8, 24, 40, 36, 27, 18, 37];
$headers = ['#','Date','Customer','Car','Reg No','Rental','Amount (' . $pdf->safeText(cr_setting('currency_code'), 5) . ')'];
$drawHeader = function() use ($pdf, $w, $headers) {
    $pdf->SetFont('Arial','B',8);
    $pdf->SetFillColor(44, 62, 80);
    $pdf->SetTextColor(255,255,255);
    foreach($headers as $i=>$v) $pdf->Cell($w[$i],7,$v,1,0,'C',true);
    $pdf->Ln();
    $pdf->SetFont('Arial','',8);
    $pdf->SetTextColor(0,0,0);
};
$drawHeader();

$i=1; $total=0;
if($result && $result->num_rows){
    while($row=$result->fetch_assoc()){
        if ($pdf->GetY() > 260) { $pdf->AddPage(); $drawHeader(); }
        $total += (float)$row['amount'];
        $carName = trim(($row['make_name'] ?? '') . ' ' . ($row['model_name'] ?? '') . ' ' . ($row['car_year'] ?? ''));
        if ($carName === '') $carName = $row['reg_no'];
        $pdf->Cell($w[0],6,$i++,1);
        $pdf->Cell($w[1],6,$pdf->safeText(cr_date($row['payment_date']), 14),1);
        $pdf->Cell($w[2],6,$pdf->safeText($row['full_name'], 26),1);
        $pdf->Cell($w[3],6,$pdf->safeText($carName, 24),1);
        $pdf->Cell($w[4],6,$pdf->safeText($row['reg_no'], 16),1);
        $pdf->Cell($w[5],6,'#'.(int)$row['rental_id'],1);
        $pdf->Cell($w[6],6,$pdf->safeText(cr_number($row['amount']), 24),1,1,'R');
    }
    if ($pdf->GetY() > 260) { $pdf->AddPage(); $drawHeader(); }
    $pdf->SetFont('Arial','B',8);
    $pdf->Cell(array_sum(array_slice($w,0,6)),7,$pdf->safeText('Total (' . $result->num_rows . ' payments)', 60),1,0,'R');
    $pdf->Cell($w[6],7,$pdf->safeText(money_text($total), 30),1,1,'R');
} else {
    $pdf->Cell(array_sum($w),7,'No payment records found.',1,1,'C');
}
$pdf->Output('I','payment_report.pdf');
exit;
