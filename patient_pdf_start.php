<?php
// patient_pdf_start.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'includes/db.php';
require 'includes/session_check.php';

$clinic_id    = (int)($_POST['clinic_id']  ?? 0);
$invoice_id   = (int)($_POST['invoice_id'] ?? 0);
$patient_name = trim($_POST['patient_name'] ?? '');

if ($clinic_id <= 0 || $invoice_id <= 0 || $patient_name === '') {
  die('Missing data.');
}

/** Ensure invoices.patient_name column exists (safe one-time guard) */
$colCheck = $conn->query("SHOW COLUMNS FROM `invoices` LIKE 'patient_name'");
if (!$colCheck || $colCheck->num_rows === 0) {
  $conn->query("ALTER TABLE `invoices` ADD COLUMN `patient_name` VARCHAR(255) NULL AFTER `clinic_id`");
}

/** Save the patient name on this invoice */
$upd = $conn->prepare("UPDATE invoices SET patient_name = ? WHERE id = ? LIMIT 1");
$upd->bind_param('si', $patient_name, $invoice_id);
$upd->execute();
$upd->close();

/** Redirect to the PDF (reads the name from invoices) */
$query = http_build_query([
  'clinic_id'  => $clinic_id,
  'invoice_id' => $invoice_id
]);
header("Location: generate_pdf_patient.php?{$query}");
exit;
