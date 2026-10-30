<?php
require_once __DIR__ . '/../includes/admin_only.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/pdf_report_helper.php';

$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$reg_no = $_GET['reg_no'] ?? '';
$where = 'WHERE 1';
if ($from_date !== '') $where .= " AND p.payment_date >= '" . $conn->real_escape_string($from_date) . "'";
if ($to_date !== '') $where .= " AND p.payment_date <= '" . $conn->real_escape_string($to_date) . "'";
if ($reg_no !== '') $where .= " AND ca.reg_no LIKE '%" . $conn->real_escape_string($reg_no) . "%'";

$result = $conn->query("SELECT p.id, p.payment_date, p.amount, r.id AS rental_id, c.full_name, ca.reg_no, m.name AS make_name, mo.name AS model_name, y.year AS car_year
    FROM payment p
    JOIN rentals r ON p.rental_id = r.id
    JOIN customers c ON r.customer_id = c.id
    JOIN cars ca ON r.cars_id = ca.id
    LEFT JOIN makes m ON ca.make_id = m.id
    LEFT JOIN models mo ON ca.model_id = mo.id
    LEFT JOIN car_years y ON ca.year_id = y.id
    $where
    ORDER BY p.payment_date DESC, p.id DESC");

$pdf = new CarRentalReportPdf('P');
$pdf->AddPage();
$pdf->reportTitle('Payment Report', ['From' => $from_date, 'To' => $to_date, 'Reg No' => $reg_no]);

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
        $pdf->Cell($w[1],6,cr_date($row['payment_date']),1);
        $pdf->Cell($w[2],6,$pdf->safeText($row['full_name'], 26),1);
        $pdf->Cell($w[3],6,$pdf->safeText($carName, 24),1);
        $pdf->Cell($w[4],6,$pdf->safeText($row['reg_no'], 16),1);
        $pdf->Cell($w[5],6,'#'.$row['rental_id'],1);
        $pdf->Cell($w[6],6,cr_number($row['amount']),1,1,'R');
    }
    if ($pdf->GetY() > 260) { $pdf->AddPage(); $drawHeader(); }
    $pdf->SetFont('Arial','B',8);
    $pdf->Cell(array_sum(array_slice($w,0,6)),7,'Total',1,0,'R');
    $pdf->Cell($w[6],7,$pdf->safeText(money_text($total), 30),1,1,'R');
} else {
    $pdf->Cell(array_sum($w),7,'No payment records found.',1,1,'C');
}
$pdf->Output('I','payment_report.pdf');
exit;
?>
