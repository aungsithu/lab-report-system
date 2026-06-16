<?php
require 'includes/db.php';

$username = 'superadmin';
$password = password_hash('admin123', PASSWORD_DEFAULT);
$role = 'superadmin';

// Create table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100),
  password VARCHAR(255),
  role ENUM('superadmin', 'admin', 'clinic_user') NOT NULL,
  clinic_id INT DEFAULT NULL
)");

// Insert superadmin
$stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
$stmt->bind_param("sss", $username, $password, $role);
$stmt->execute();
echo "✅ Superadmin created. Username: superadmin, Password: admin123";
?>
