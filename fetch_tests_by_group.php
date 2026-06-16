<?php
require 'includes/db.php';

$q = $_GET['q'] ?? '';
$q = "%$q%";

$stmt = $conn->prepare("SELECT name FROM lab_tests WHERE name LIKE ? LIMIT 10");
$stmt->bind_param("s", $q);
$stmt->execute();

$res = $stmt->get_result();
$data = [];

while ($row = $res->fetch_assoc()) {
  $data[] = $row['name'];
}
echo json_encode($data);
