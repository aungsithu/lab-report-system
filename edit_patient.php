<?php
require 'includes/db.php';
require 'includes/session_check.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = intval($_POST['id']);
    $name = trim($_POST['name']);
    $hn = trim($_POST['hn']);
    $ln = trim($_POST['ln']);

    if ($id > 0 && $name) {
        $stmt = $conn->prepare("UPDATE patients SET name = ?, hn = ?, ln = ? WHERE id = ?");
        $stmt->bind_param("sssi", $name, $hn, $ln, $id);
        $stmt->execute();
    }
}

header("Location: search_patient.php");
exit;
