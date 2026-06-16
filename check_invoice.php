<?php
require 'includes/db.php';

$clinic_id = intval($_GET['clinic_id'] ?? 0);
$today = date('Y-m-d');

$stmt = $conn->prepare("SELECT id FROM invoices WHERE clinic_id = ? AND DATE(created_at) = ?");
$stmt->bind_param("is", $clinic_id, $today);
$stmt->execute();
$stmt->store_result();

echo json_encode(['exists' => $stmt->num_rows > 0]);
