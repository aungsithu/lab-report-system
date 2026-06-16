<?php
require 'includes/db.php';
header('Content-Type: application/json; charset=utf-8');

$term = trim($_GET['term'] ?? '');
$with_code = (int)($_GET['with_code'] ?? 0);

if ($term === '') {
  echo json_encode([]);
  exit;
}

$like = "%{$term}%";

/**
 * Recommended: use imported_prices because it contains test_code + test_name + price.
 * Search by code OR name.
 */
if ($with_code === 1) {

  // If your column names differ, change them here:
  // imported_prices: test_code, test_name, price
  $sql = "
    SELECT test_code, test_name, price
    FROM imported_prices
    WHERE (test_name LIKE ? OR test_code LIKE ?)
    ORDER BY
      CASE
        WHEN test_code = ? THEN 0
        WHEN test_name = ? THEN 1
        WHEN test_code LIKE ? THEN 2
        WHEN test_name LIKE ? THEN 3
        ELSE 4
      END,
      test_name ASC
    LIMIT 20
  ";

  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    echo json_encode(["error" => $conn->error]);
    exit;
  }

  // Ranking helpers
  $codeExact = $term;
  $nameExact = $term;

  $stmt->bind_param(
    "ssssss",
    $like,        // test_name LIKE
    $like,        // test_code LIKE
    $codeExact,   // exact code
    $nameExact,   // exact name
    $like,        // code like
    $like         // name like
  );

  $stmt->execute();
  $res = $stmt->get_result();

  $out = [];
  while ($row = $res->fetch_assoc()) {
    $out[] = [
      'test_code' => $row['test_code'] ?? '',
      'test_name' => $row['test_name'] ?? '',
      'price'     => $row['price'] ?? 0,
    ];
  }

  echo json_encode($out);
  exit;
}

/**
 * Backward-compatible mode:
 * Returns ["ACTH","AFP",...]
 * We can still use imported_prices for better matching,
 * but fallback to lab_tests if imported_prices has no results.
 */

// 1) Try imported_prices first
$stmt = $conn->prepare("
  SELECT DISTINCT test_name AS name
  FROM imported_prices
  WHERE test_name LIKE ?
  ORDER BY test_name ASC
  LIMIT 20
");
$stmt->bind_param("s", $like);
$stmt->execute();
$res = $stmt->get_result();

$names = [];
while ($row = $res->fetch_assoc()) {
  if (!empty($row['name'])) $names[] = $row['name'];
}
$stmt->close();

// 2) If still empty, fallback to lab_tests (your old behavior)
if (count($names) === 0) {
  $stmt = $conn->prepare("
    SELECT DISTINCT name
    FROM lab_tests
    WHERE name LIKE ?
    ORDER BY name ASC
    LIMIT 20
  ");
  $stmt->bind_param("s", $like);
  $stmt->execute();
  $res = $stmt->get_result();

  while ($row = $res->fetch_assoc()) {
    if (!empty($row['name'])) $names[] = $row['name'];
  }
  $stmt->close();
}

echo json_encode($names);
