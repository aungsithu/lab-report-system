<?php
// save_patient_name.php (JSON endpoint)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

require 'includes/session_check.php';
require 'includes/db.php';

$invoice_id   = (int)($_POST['invoice_id'] ?? 0);
$patient_name = trim((string)($_POST['patient_name'] ?? ''));

if ($invoice_id <= 0) {
  echo json_encode(['success' => false, 'message' => 'Missing invoice_id']); exit;
}

try {
  $stmt = $conn->prepare("UPDATE invoices SET patient_name = ? WHERE id = ?");
  $stmt->bind_param("si", $patient_name, $invoice_id);
  $stmt->execute();
  echo json_encode(['success' => true]);
} catch (Throwable $e) {
  echo json_encode(['success' => false, 'message' => 'DB error: '.$e->getMessage()]);
}
