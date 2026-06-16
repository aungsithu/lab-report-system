<?php
// save_receipt_info.php
// NO BOM or whitespace before this tag!
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

require 'includes/session_check.php';
require 'includes/db.php';

$invoice_id      = (int)($_POST['invoice_id'] ?? 0);
$patient_name    = trim($_POST['patient_name'] ?? '');         // optional
$tax_id          = trim($_POST['tax_id'] ?? '');
$tel             = trim($_POST['tel'] ?? '');
$salesman_no     = trim($_POST['salesman_no'] ?? '');          // ok if you later stop sending it
// accept either "address" or "customer_address" from the client
$customer_address = trim($_POST['customer_address'] ?? ($_POST['address'] ?? ''));

if ($invoice_id <= 0) {
  echo json_encode(['success' => false, 'message' => 'Missing invoice_id']); 
  exit;
}

// normalize empty to a dash so prompts won't re-ask
$norm = function($v){ $v = trim((string)$v); return ($v === '') ? '-' : $v; };
$patient_name     = ($patient_name === '') ? '' : $patient_name;  // name can be blank (means don't overwrite if you don't send it)
$tax_id           = $norm($tax_id);
$tel              = $norm($tel);
$salesman_no      = $norm($salesman_no);
$customer_address = $norm($customer_address);

// check if customer_address column exists
$hasAddressCol = false;
$colCheck = $conn->query("SHOW COLUMNS FROM invoices LIKE 'customer_address'");
if ($colCheck && $colCheck->num_rows === 1) {
  $hasAddressCol = true;
}

// Build dynamic update for fields we actually want to touch
$fields = [];
$params = [];
$types  = '';

// Allow overwriting patient_name only if it was provided (can be empty string to clear)
if (isset($_POST['patient_name'])) {
  $fields[] = "patient_name = ?";
  $params[] = $patient_name;
  $types   .= 's';
}

$fields[] = "tax_id = ?";
$params[] = $tax_id;
$types   .= 's';

$fields[] = "tel = ?";
$params[] = $tel;
$types   .= 's';

$fields[] = "salesman_no = ?";
$params[] = $salesman_no;
$types   .= 's';

if ($hasAddressCol) {
  $fields[] = "customer_address = ?";
  $params[] = $customer_address;
  $types   .= 's';
}

$fields[] = "id = id"; // harmless no-op to simplify commas if nothing else
$sql = "UPDATE invoices SET " . implode(", ", $fields) . " WHERE id = ?";
$params[] = $invoice_id;
$types   .= 'i';

$stmt = $conn->prepare($sql);
if (!$stmt) {
  echo json_encode(['success'=>false,'message'=>'Prepare failed: '.$conn->error, 'sql'=>$sql]); 
  exit;
}

$stmt->bind_param($types, ...$params);

if (!$stmt->execute()) {
  echo json_encode(['success'=>false,'message'=>'Execute failed: '.$stmt->error]); 
  exit;
}

// Return the values we wrote (handy for updating the UI without reload)
echo json_encode([
  'success' => true,
  'data' => [
    'patient_name'     => isset($_POST['patient_name']) ? $patient_name : null,
    'tax_id'           => $tax_id,
    'tel'              => $tel,
    'salesman_no'      => $salesman_no,
    'customer_address' => $hasAddressCol ? $customer_address : null,
  ]
]);
