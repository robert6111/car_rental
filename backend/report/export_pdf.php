<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);
require_once __DIR__ . '/../includes/admin_only.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rental_status.php';
require_once __DIR__ . '/../includes/pdf_report_helper.php';

sync_rental_and_car_status($conn);

$status_filter = $_GET['status'] ?? '';
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$reg_no = $_GET['reg_no'] ?? '';
$where = 'WHERE 1';
if ($status_filter !== '') $where .= " AND r.status='" . $conn->real_escape_string($status_filter) . "'";
if ($from_date !== '') $where .= " AND r.start_date >= '" . $conn->real_escape_string($from_date) . "'";
if ($to_date !== '') $where .= " AND r.end_date <= '" . $conn->real_escape_string($to_date) . "'";
if ($reg_no !== '') $where .= " AND ca.reg_no LIKE '%" . $conn->real_escape_string($reg_no) . "%'";

$result = $conn->query("SELECT r.id, r.start_date, r.end_date, r.total_cost, r.status AS rental_status,
               c.full_name AS customer_name, ca.reg_no AS car_reg, m.name AS make_name, mo.name AS model_name, y.year AS car_year
        FROM rentals r
        JOIN customers c ON r.customer_id = c.id
        JOIN cars ca ON r.cars_id = ca.id
        LEFT JOIN makes m ON ca.make_id = m.id
        LEFT JOIN models mo ON ca.model_id = mo.id
        LEFT JOIN car_years y ON ca.year_id = y.id
        $where
        ORDER BY r.start_date DESC, r.id DESC");

$pdf = new CarRentalReportPdf('P');
$pdf->AddPage();
$pdf->reportTitle('Rental Report', ['Status' => $status_filter ?: 'All', 'From' => $from_date, 'To' => $to_date, 'Reg No' => $reg_no]);

$w = [8, 34, 36, 25, 20, 20, 24, 23];
$headers = ['#','Customer','Car','Reg No','Start','End','Cost','Status'];
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

$i=1; $total=0;
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        if ($pdf->GetY() > 260) { $pdf->AddPage(); $drawHeader(); }
        $total += (float)$row['total_cost'];
        $carName = trim(($row['make_name'] ?? '') . ' ' . ($row['model_name'] ?? '') . ' ' . ($row['car_year'] ?? ''));
        if ($carName === '') $carName = $row['car_reg'];
        $pdf->Cell($w[0],6,$i++,1);
        $pdf->Cell($w[1],6,$pdf->safeText($row['customer_name'], 24),1);
        $pdf->Cell($w[2],6,$pdf->safeText($carName, 24),1);
        $pdf->Cell($w[3],6,$pdf->safeText($row['car_reg'], 15),1);
        $pdf->Cell($w[4],6,$row['start_date'],1);
        $pdf->Cell($w[5],6,$row['end_date'],1);
        $pdf->Cell($w[6],6,number_format((float)$row['total_cost'],2),1,0,'R');
        $pdf->Cell($w[7],6,$pdf->safeText($row['rental_status'], 10),1,1);
    }
    if ($pdf->GetY() > 260) { $pdf->AddPage(); $drawHeader(); }
    $pdf->SetFont('Arial','B',8);
    $pdf->Cell(array_sum(array_slice($w,0,6)),7,'Total',1,0,'R');
    $pdf->Cell($w[6],7,number_format($total,2),1,0,'R');
    $pdf->Cell($w[7],7,'',1,1);
} else {
    $pdf->Cell(array_sum($w),7,'No rentals found.',1,1,'C');
}

$pdf->Output('I','rental_report.pdf');
exit;
?>
