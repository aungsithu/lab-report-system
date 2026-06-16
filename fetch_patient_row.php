<?php
require 'includes/db.php';

$id = $_POST['id'] ?? 0;

$stmt = $conn->prepare("SELECT p.*, c.name AS clinic FROM patients p LEFT JOIN clinics c ON p.clinic_id = c.id WHERE p.id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if ($row) {
  echo '
    <tr>
      <td>1</td>
      <td>' . htmlspecialchars($row['name']) . '</td>
      <td>' . htmlspecialchars($row['hn']) . ' / ' . htmlspecialchars($row['ln']) . '</td>
      <td>' . htmlspecialchars($row['clinic']) . '</td>
      <td>' . $row['created_at'] . '</td>
      <td><span class="badge bg-' . ($row['status'] === 'approved' ? 'secondary' : 'dark') . '">' . $row['status'] . '</span></td>
      <td>' . ($row['pdf'] ? '📄' : '❌') . '</td>
      <td>
        <a class="btn btn-primary btn-sm">View</a>
        <a class="btn btn-secondary btn-sm">Upload</a>
        <a class="btn btn-danger btn-sm">Delete</a>
      </td>
    </tr>';
}
