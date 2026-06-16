<?php
// export_clinic_summary_csv.php
session_start();
require 'includes/db.php';
include 'includes/header.php';
require 'includes/session_check.php';
date_default_timezone_set('Asia/Bangkok');

// ROLE CHECK
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['superadmin', 'admin'])) {
    die('Access denied.');
}

$clinic_id = isset($_GET['clinic_id']) ? (int)$_GET['clinic_id'] : 0;
$mode      = isset($_GET['mode']) ? $_GET['mode'] : 'month';
$month     = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$yearIn    = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

if ($clinic_id <= 0) {
    die('Invalid clinic');
}

// Convert Thai year if needed
$queryYear = $yearIn > 2500 ? $yearIn - 543 : $yearIn;

if ($mode === 'year') {
    $startDate = sprintf('%04d-01-01', $queryYear);
    $endDate   = sprintf('%04d-12-31', $queryYear);
} else {
    $startDate = sprintf('%04d-%02d-01', $queryYear, $month);
    $endDate   = date('Y-m-t', strtotime($startDate));
}

// SAME QUERY AS clinic_summary.php
$sql = "
    SELECT
        p.id              AS patient_id,
        p.hn              AS lab_no,
        p.name            AS first_name,
        p.ln              AS last_name,
        DATE(p.register_date) AS service_date,
        lr.test_name      AS test_name,
        COALESCE(ip.price, 0) AS price
    FROM patients p
    INNER JOIN lab_results lr 
        ON lr.patient_id = p.id
    LEFT JOIN imported_prices ip 
        ON ip.test_name = lr.test_name
    WHERE p.clinic_id = ?
      AND DATE(p.register_date) BETWEEN ? AND ?
    ORDER BY p.register_date ASC,
             p.id ASC,
             lr.excel_order ASC,
             lr.test_name ASC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('iss', $clinic_id, $startDate, $endDate);
$stmt->execute();
$result = $stmt->get_result();

// Filename
$filename = "clinic_summary_{$clinic_id}_{$mode}_{$yearIn}";
if ($mode === 'month') {
    $filename .= sprintf('%02d', $month);
}
$filename .= ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');

// UTF-8 BOM for Excel
fwrite($out, "\xEF\xBB\xBF");

// Header row
fputcsv($out, ['No.', 'Lab No.', 'Name', 'Surname', 'Test', 'Price']);

$patientIndex = 0;
$lastPatientId = null;
$grandTotal = 0;

while ($row = $result->fetch_assoc()) {
    if ($row['patient_id'] != $lastPatientId) {
        $patientIndex++;
        $showPatient   = true;
        $lastPatientId = $row['patient_id'];
    } else {
        $showPatient   = false;
    }

    $grandTotal += (float)$row['price'];

    fputcsv($out, [
        $showPatient ? $patientIndex      : '',
        $showPatient ? $row['lab_no']     : '',
        $showPatient ? $row['first_name'] : '',
        $showPatient ? $row['last_name']  : '',
        $row['test_name'],
        number_format($row['price'], 2, '.', ''),
    ]);
}

// Grand total row
fputcsv($out, ['', '', '', '', 'Grand Total', number_format($grandTotal, 2, '.', '')]);

fclose($out);
$stmt->close();
exit;
?>

<?php include 'includes/footer.php'; ?>
