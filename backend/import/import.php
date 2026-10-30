<?php
require_once __DIR__ . '/../includes/admin_only.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rental_status.php';

const CR_IMPORT_MAX_BYTES = 2 * 1024 * 1024; // 2 MB
const CR_IMPORT_MAX_ROWS = 5000;
const CR_IMPORT_SHOW_ERRORS = 10;

$msg = $error = '';
$rowErrors = [];

function csv_header_map(array $headers): array {
    $map = [];
    foreach ($headers as $i => $h) {
        $key = strtolower(trim((string)$h));
        $key = str_replace([' ', '-'], '_', $key);
        if ($key !== '' && !isset($map[$key])) $map[$key] = $i;
    }
    return $map;
}

/** Finds a make/model by name (case-insensitive) or creates it. Returns 0 on failure. */
function get_or_create_id(mysqli $conn, string $table, string $name, int $makeId = 0): int {
    if ($table === 'models') {
        $stmt = $conn->prepare("SELECT id FROM models WHERE LOWER(name)=LOWER(?) AND make_id=? LIMIT 1");
        $stmt->bind_param('si', $name, $makeId);
    } else {
        $stmt = $conn->prepare("SELECT id FROM makes WHERE LOWER(name)=LOWER(?) LIMIT 1");
        $stmt->bind_param('s', $name);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) return (int)$row['id'];

    if ($table === 'models') {
        $stmt = $conn->prepare("INSERT INTO models (make_id,name) VALUES (?,?)");
        $stmt->bind_param('is', $makeId, $name);
    } else {
        $stmt = $conn->prepare("INSERT INTO makes (name) VALUES (?)");
        $stmt->bind_param('s', $name);
    }
    $ok = $stmt->execute();
    if (!$ok) cr_db_error($stmt->error);
    $id = $ok ? (int)$stmt->insert_id : 0;
    $stmt->close();
    return $id;
}

function get_or_create_year_id(mysqli $conn, int $year): int {
    $stmt = $conn->prepare("SELECT id FROM car_years WHERE year=? LIMIT 1");
    $stmt->bind_param('i', $year);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) return (int)$row['id'];
    $stmt = $conn->prepare("INSERT INTO car_years (year) VALUES (?)");
    $stmt->bind_param('i', $year);
    $ok = $stmt->execute();
    if (!$ok) cr_db_error($stmt->error);
    $id = $ok ? (int)$stmt->insert_id : 0;
    $stmt->close();
    return $id;
}

/** Returns a trimmed cell value by header name ('' when the column or cell is missing). */
function csv_cell(array $row, array $map, string $key): string {
    return isset($map[$key]) ? trim((string)($row[$map[$key]] ?? '')) : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = (string)($_POST['type'] ?? '');
    $file = $_FILES['csv_file'] ?? null;
    $required = [
        'customers' => ['full_name', 'phone_number', 'license_no'],
        'cars'      => ['reg_no', 'make', 'model', 'year', 'price_per_day'],
    ];

    if (!isset($required[$type])) {
        $error = 'Please select a valid import type.';
    } elseif (!$file || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $error = (($file['error'] ?? null) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? null) === UPLOAD_ERR_FORM_SIZE)
            ? 'The file is too large (maximum 2 MB).'
            : 'Please upload a valid CSV file.';
    } elseif (strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        $error = 'Only .csv files can be imported.';
    } elseif ((int)$file['size'] > CR_IMPORT_MAX_BYTES || filesize($file['tmp_name']) > CR_IMPORT_MAX_BYTES) {
        $error = 'The file is too large (maximum 2 MB).';
    } elseif (($handle = fopen($file['tmp_name'], 'r')) === false) {
        $error = 'Could not read the uploaded file.';
    } else {
        // Detect the delimiter: ';' when the header line has ';' and no ','.
        $firstLine = (string)fgets($handle);
        $delimiter = (strpos($firstLine, ';') !== false && strpos($firstLine, ',') === false) ? ';' : ',';
        rewind($handle);

        $headers = fgetcsv($handle, 0, $delimiter, '"', '');
        if (is_array($headers) && isset($headers[0])) {
            // Strip a UTF-8 BOM (Excel adds it) from the first header cell.
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
        }
        $map = csv_header_map(is_array($headers) ? $headers : []);
        $missing = array_diff($required[$type], array_keys($map));

        $rows = [];
        if ($missing) {
            $error = 'The CSV file is missing these columns: ' . implode(', ', $missing) . '.';
        } else {
            $line = 1;
            while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                $line++;
                if ($row === [null] || (count($row) === 1 && trim((string)$row[0]) === '')) continue; // blank line
                if (count($rows) >= CR_IMPORT_MAX_ROWS) {
                    $error = 'The file has more than ' . CR_IMPORT_MAX_ROWS . ' rows. Please split it into smaller files.';
                    break;
                }
                $rows[] = [$line, $row];
            }
        }
        fclose($handle);

        if (!$error && !$rows) $error = 'The CSV file has no data rows.';

        if (!$error) {
            $inserted = 0;
            $skipped = 0;
            $dbFailed = false;
            $seen = [];
            $addRowError = function (int $line, string $text) use (&$rowErrors, &$skipped) {
                $skipped++;
                $rowErrors[] = "Row $line: $text";
            };

            // All-or-nothing: if a database error happens, nothing from this file is saved.
            $conn->begin_transaction();
            foreach ($rows as [$line, $row]) {
                if ($type === 'customers') {
                    $full = csv_cell($row, $map, 'full_name');
                    $phone = csv_cell($row, $map, 'phone_number');
                    $email = csv_cell($row, $map, 'email');
                    $license = csv_cell($row, $map, 'license_no');

                    if ($full === '') { $addRowError($line, 'full_name is required.'); continue; }
                    if ($phone === '') { $addRowError($line, 'phone_number is required.'); continue; }
                    if ($license === '') { $addRowError($line, 'license_no is required.'); continue; }
                    if (mb_strlen($full) > 255 || mb_strlen($phone) > 50 || mb_strlen($email) > 255 || mb_strlen($license) > 100) {
                        $addRowError($line, 'a value is too long.'); continue;
                    }
                    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $addRowError($line, 'invalid email address.'); continue; }

                    $licKey = 'l:' . mb_strtolower($license);
                    $mailKey = $email !== '' ? 'e:' . mb_strtolower($email) : null;
                    if (isset($seen[$licKey]) || ($mailKey && isset($seen[$mailKey]))) { $addRowError($line, 'duplicate of an earlier row in this file.'); continue; }

                    $check = $conn->prepare("SELECT id FROM customers WHERE license_no=? OR (?<>'' AND LOWER(email)=LOWER(?)) LIMIT 1");
                    $check->bind_param('sss', $license, $email, $email);
                    $check->execute();
                    $exists = $check->get_result()->fetch_assoc();
                    $check->close();
                    if ($exists) { $addRowError($line, 'a customer with this licence number or email already exists.'); continue; }

                    $stmt = $conn->prepare("INSERT INTO customers (full_name,phone_number,email,license_no) VALUES (?,?,?,?)");
                    $stmt->bind_param('ssss', $full, $phone, $email, $license);
                    if (!$stmt->execute()) { cr_db_error($stmt->error); $stmt->close(); $dbFailed = $line; break; }
                    $stmt->close();
                    $seen[$licKey] = true;
                    if ($mailKey) $seen[$mailKey] = true;
                    $inserted++;
                } else {
                    $reg = normalize_plate(csv_cell($row, $map, 'reg_no'));
                    $make = preg_replace('/\s+/u', ' ', csv_cell($row, $map, 'make'));
                    $model = preg_replace('/\s+/u', ' ', csv_cell($row, $map, 'model'));
                    $yearText = csv_cell($row, $map, 'year');
                    $price = cr_parse_money(csv_cell($row, $map, 'price_per_day'));
                    $status = csv_cell($row, $map, 'status');
                    $status = $status === '' ? 'Available' : ucfirst(strtolower($status));
                    $maxYear = (int)date('Y') + 1;

                    if (!preg_match('/^[A-Z0-9][A-Z0-9\s\-\.]{1,19}$/', $reg)) { $addRowError($line, 'invalid reg_no.'); continue; }
                    if ($make === '' || $model === '' || mb_strlen($make) > 100 || mb_strlen($model) > 100) { $addRowError($line, 'make and model are required (max 100 characters).'); continue; }
                    if (!preg_match('/^\d{4}$/', $yearText) || (int)$yearText < 1950 || (int)$yearText > $maxYear) { $addRowError($line, "year must be between 1950 and $maxYear."); continue; }
                    if ($price === null || $price <= 0) { $addRowError($line, 'price_per_day must be a valid amount greater than 0.'); continue; }
                    if (!in_array($status, ['Available', 'Maintenance'], true)) { $addRowError($line, 'status must be Available or Maintenance.'); continue; }
                    if (isset($seen[$reg])) { $addRowError($line, 'duplicate reg_no earlier in this file.'); continue; }

                    $check = $conn->prepare("SELECT id FROM cars WHERE reg_no=? LIMIT 1");
                    $check->bind_param('s', $reg);
                    $check->execute();
                    $exists = $check->get_result()->fetch_assoc();
                    $check->close();
                    if ($exists) { $addRowError($line, 'a car with this reg_no already exists.'); continue; }

                    $makeId = get_or_create_id($conn, 'makes', $make);
                    $modelId = $makeId ? get_or_create_id($conn, 'models', $model, $makeId) : 0;
                    $yearId = get_or_create_year_id($conn, (int)$yearText);
                    if (!$makeId || !$modelId || !$yearId) { $dbFailed = $line; break; }

                    $stmt = $conn->prepare("INSERT INTO cars (reg_no,make_id,model_id,year_id,availability_status,price_per_day) VALUES (?,?,?,?,?,?)");
                    $stmt->bind_param('siiisd', $reg, $makeId, $modelId, $yearId, $status, $price);
                    if (!$stmt->execute()) { cr_db_error($stmt->error); $stmt->close(); $dbFailed = $line; break; }
                    $stmt->close();
                    $seen[$reg] = true;
                    $inserted++;
                }
            }

            if ($dbFailed !== false) {
                $conn->rollback();
                $error = "A database error happened at row $dbFailed. Nothing was imported; please check the file and try again.";
            } elseif (!$conn->commit()) {
                $conn->rollback();
                $error = 'The import could not be saved. Nothing was imported.';
            } else {
                $msg = "Import finished. Imported: $inserted. Skipped: $skipped.";
            }
        }
    }
}
?>
<?php cr_layout_start('Import data'); ?>
<?= cr_page_header('Import data', 'Add many customers or cars at once from a CSV file.'); ?>
<?= cr_alerts($msg, $error); ?>
<?php if ($rowErrors): ?>
<?php
    $skippedHtml = '<strong>Skipped rows' . h(count($rowErrors) > CR_IMPORT_SHOW_ERRORS ? ' (first ' . CR_IMPORT_SHOW_ERRORS . ' of ' . count($rowErrors) . ')' : '') . ':</strong><ul class="mb-0">';
    foreach (array_slice($rowErrors, 0, CR_IMPORT_SHOW_ERRORS) as $re) $skippedHtml .= '<li>' . h($re) . '</li>';
    $skippedHtml .= '</ul>';
?>
<?= cr_alert('warning', $skippedHtml, true); ?>
<?php endif; ?>

<div class="grid grid-2">
    <form method="POST" enctype="multipart/form-data" class="card">
        <h2 class="section-title">Upload a file</h2>
        <div class="form-grid">
            <div class="field span-all">
                <label for="type">Import type <span class="req">*</span></label>
                <select id="type" name="type" required><option value="customers">Customers CSV</option><option value="cars">Cars CSV</option></select>
            </div>
            <div class="field span-all">
                <label for="csv_file">CSV file <span class="req">*</span></label>
                <input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" required>
                <span class="hint">Comma or semicolon separated, maximum 2 MB and <?= (int)CR_IMPORT_MAX_ROWS ?> rows.</span>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit">Import</button>
        </div>
    </form>

    <div class="card">
        <h2 class="section-title">Required CSV headers</h2>
        <dl class="details">
            <dt>Customers</dt>
            <dd><code>full_name</code>, <code>phone_number</code>, <code>license_no</code><span class="cell-sub">email optional but must be valid</span></dd>
            <dt>Cars</dt>
            <dd><code>reg_no</code>, <code>make</code>, <code>model</code>, <code>year</code>, <code>price_per_day</code><span class="cell-sub">status optional: Available or Maintenance</span></dd>
        </dl>
        <p class="muted small mt-4 mb-0">Invalid or duplicate rows are skipped and listed. If a database error happens, nothing is imported.</p>
    </div>
</div>
<?php cr_layout_end(); ?>
