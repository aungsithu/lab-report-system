<?php
require 'includes/db.php';
$query = $_POST['query'] ?? '';

$sql = "SELECT * FROM patients WHERE name LIKE ? OR hn LIKE ? OR ln LIKE ? ORDER BY id DESC";
$stmt = $conn->prepare($sql);
$search = "%$query%";
$stmt->bind_param("sss", $search, $search, $search);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
  echo "<tr>
    <td>{$row['id']}</td>
    <td>{$row['name']}</td>
    <td>{$row['hn']} / {$row['ln']}</td>
    <td>{$row['clinic']}</td>
    <td>{$row['registered_date']}</td>
    <td>{$row['status']}</td>
    <td>[PDF Icon]</td>
    <td>[Actions]</td>
  </tr>";
}
?>
