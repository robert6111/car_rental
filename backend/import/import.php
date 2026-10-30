<?php
require_once __DIR__ . '/../includes/admin_only.php';
require __DIR__ . '/../includes/db.php';

$msg = $error = '';
function csv_header_map(array $headers): array { $map=[]; foreach($headers as $i=>$h){ $key=strtolower(trim($h)); $key=str_replace([' ','-'], '_', $key); $map[$key]=$i; } return $map; }
function get_or_create_id(mysqli $conn, string $table, string $name, int $makeId = 0): int {
    if ($table === 'models') { $stmt=$conn->prepare("SELECT id FROM models WHERE name=? AND make_id=? LIMIT 1"); $stmt->bind_param('si',$name,$makeId); }
    else { $stmt=$conn->prepare("SELECT id FROM $table WHERE name=? LIMIT 1"); $stmt->bind_param('s',$name); }
    $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close(); if($row) return (int)$row['id'];
    if ($table === 'models') { $stmt=$conn->prepare("INSERT INTO models (make_id,name) VALUES (?,?)"); $stmt->bind_param('is',$makeId,$name); }
    else { $stmt=$conn->prepare("INSERT INTO $table (name) VALUES (?)"); $stmt->bind_param('s',$name); }
    $stmt->execute(); $id=$stmt->insert_id; $stmt->close(); return (int)$id;
}
function get_or_create_year_id(mysqli $conn, string $year): int { $year=(int)$year; $stmt=$conn->prepare("SELECT id FROM car_years WHERE year=? LIMIT 1"); $stmt->bind_param('i',$year); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close(); if($row) return (int)$row['id']; $stmt=$conn->prepare("INSERT INTO car_years (year) VALUES (?)"); $stmt->bind_param('i',$year); $stmt->execute(); $id=$stmt->insert_id; $stmt->close(); return (int)$id; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['type'] ?? '';
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) $error = 'Please upload a valid CSV file.';
    elseif (($handle = fopen($_FILES['csv_file']['tmp_name'], 'r')) === false) $error = 'Could not read the uploaded file.';
    else {
        $headers = fgetcsv($handle); $map = csv_header_map($headers ?: []); $inserted=0; $skipped=0;
        while (($row = fgetcsv($handle)) !== false) {
            if ($type === 'customers') {
                foreach(['full_name','phone_number','email','license_no'] as $required){ if(!isset($map[$required]) || trim($row[$map[$required]] ?? '')===''){ $skipped++; continue 2; } }
                $full=trim($row[$map['full_name']]); $phone=trim($row[$map['phone_number']]); $email=trim($row[$map['email']]); $license=trim($row[$map['license_no']]);
                $check=$conn->prepare("SELECT id FROM customers WHERE email=? OR license_no=? LIMIT 1"); $check->bind_param('ss',$email,$license); $check->execute(); $exists=$check->get_result()->fetch_assoc(); $check->close(); if($exists){$skipped++; continue;}
                $stmt=$conn->prepare("INSERT INTO customers (full_name,phone_number,email,license_no) VALUES (?,?,?,?)"); $stmt->bind_param('ssss',$full,$phone,$email,$license); if($stmt->execute()) $inserted++; else $skipped++; $stmt->close();
            } elseif ($type === 'cars') {
                foreach(['reg_no','make','model','year','price_per_day'] as $required){ if(!isset($map[$required]) || trim($row[$map[$required]] ?? '')===''){ $skipped++; continue 2; } }
                $reg=normalize_plate($row[$map['reg_no']]); $make=trim($row[$map['make']]); $model=trim($row[$map['model']]); $year=trim($row[$map['year']]); $price=(float)($row[$map['price_per_day']] ?? 0); $status=trim($row[$map['status']] ?? 'Available'); if(!in_array($status,['Available','Rented','Maintenance'],true)) $status='Available';
                $check=$conn->prepare("SELECT id FROM cars WHERE reg_no=? LIMIT 1"); $check->bind_param('s',$reg); $check->execute(); $exists=$check->get_result()->fetch_assoc(); $check->close(); if($exists){$skipped++; continue;}
                $makeId=get_or_create_id($conn,'makes',$make); $modelId=get_or_create_id($conn,'models',$model,$makeId); $yearId=get_or_create_year_id($conn,$year);
                $stmt=$conn->prepare("INSERT INTO cars (reg_no,make_id,model_id,year_id,availability_status,price_per_day) VALUES (?,?,?,?,?,?)"); $stmt->bind_param('siiisd',$reg,$makeId,$modelId,$yearId,$status,$price); if($stmt->execute()) $inserted++; else $skipped++; $stmt->close();
            } else { $error='Please select a valid import type.'; break; }
        }
        fclose($handle); if(!$error) $msg = "Import finished. Inserted: $inserted. Skipped: $skipped.";
    }
}
?>
<!DOCTYPE html><html><head><title>Import Data</title><link rel="stylesheet" href="/../backend/includes/css/header.css"><link rel="stylesheet" href="/../backend/includes/css/dashboard.css"><link rel="stylesheet" href="/../backend/includes/css/common.css"></head><body><?php include __DIR__ . '/../includes/header.php'; include __DIR__ . '/../includes/leftsidebar.php'; ?>
<div class="content-page"><h2>Import Data</h2><?php if($msg): ?><p style="color:green;font-weight:bold;"><?= h($msg); ?></p><?php endif; ?><?php if($error): ?><p style="color:red;font-weight:bold;"><?= h($error); ?></p><?php endif; ?>
<form method="POST" enctype="multipart/form-data"><label>Import Type:</label><br><select name="type" required><option value="customers">Customers CSV</option><option value="cars">Cars CSV</option></select><br><br><label>CSV File:</label><br><input type="file" name="csv_file" accept=".csv,text/csv" required><br><br><button type="submit">Import</button></form>
<h3>Required CSV headers</h3><p><strong>Customers:</strong> full_name, phone_number, email, license_no</p><p><strong>Cars:</strong> reg_no, make, model, year, price_per_day, status</p></div></body></html>
