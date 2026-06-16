<?php
// 💥 CHANGE THESE DB SETTINGS
$host = "localhost";
$user = "root";
$pass = "root";
$dbname = "celtac_lab";

// ✅ Connect to DB
$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
  die("❌ Connection failed: " . $conn->connect_error);
}

// ✅ Create users table if not exists
$conn->query("DROP TABLE IF EXISTS users");
$conn->query("
  CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100),
    password VARCHAR(255),
    role VARCHAR(50)
  )
");

// ✅ Insert fresh superadmin with password: admin123
$username = 'superadmin';
$password = password_hash('admin123', PASSWORD_DEFAULT);
$role = 'superadmin';

$stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
$stmt->bind_param("sss", $username, $password, $role);
$stmt->execute();

// ✅ Login test
echo "<h3>Testing Login...</h3>";
$input_user = 'superadmin';
$input_pass = 'admin123';

$stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
$stmt->bind_param("s", $input_user);
$stmt->execute();
$res = $stmt->get_result();
$user = $res->fetch_assoc();

if (!$user) {
  echo "❌ User not found.";
} else {
  echo "✅ User found: " . $user['username'] . "<br>";
  if (password_verify($input_pass, $user['password'])) {
    echo "✅ Password matches. Login successful!";
  } else {
    echo "❌ Password does not match!";
  }
}
