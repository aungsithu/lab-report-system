<?php
require 'includes/db.php';

$query = $_POST['query'] ?? '';
if (empty($query)) exit;

$query = $conn->real_escape_string($query);
$sql = "SELECT id, name, hn, ln FROM patients 
        WHERE name LIKE '%$query%' OR hn LIKE '%$query%' OR ln LIKE '%$query%'
        ORDER BY name LIMIT 10";

$result = $conn->query($sql);
while ($row = $result->fetch_assoc()) {
  $label = htmlspecialchars($row['name']) . ' | ' . htmlspecialchars($row['hn']) . ' / ' . htmlspecialchars($row['ln']);
  echo "<a class='list-group-item list-group-item-action autocomplete-item' data-id='{$row['id']}'>$label</a>";
}
