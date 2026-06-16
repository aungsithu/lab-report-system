<?php
require 'includes/db.php';
require 'includes/session_check.php';

header('Content-Type: application/json; charset=utf-8');

$draw   = isset($_POST['draw']) ? (int)$_POST['draw'] : 0;
$start  = isset($_POST['start']) ? (int)$_POST['start'] : 0;
$length = isset($_POST['length']) ? (int)$_POST['length'] : 25;

if ($length <= 0) $length = 25;
if ($length > 200) $length = 200;

$searchValue = trim($_POST['search']['value'] ?? '');
$col1Search  = $_POST['columns'][1]['search']['value'] ?? '';
$useRegex    = ($_POST['columns'][1]['search']['regex'] ?? 'false') === 'true';

$orderCol = (int)($_POST['order'][0]['column'] ?? 1);
$orderDir = ($_POST['order'][0]['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

$columnsMap = [
  0 => 'test_code',
  1 => 'test_name',
  2 => 'specimen',
  3 => 'method',
  4 => 'tat_day',
  5 => 'price'
];
$orderBy = $columnsMap[$orderCol] ?? 'test_name';

$where  = " WHERE 1=1 ";
$params = [];
$types  = "";

if ($searchValue !== '') {
  $where .= " AND (test_code LIKE ? OR test_name LIKE ? OR specimen LIKE ? OR method LIKE ?) ";
  $like = "%{$searchValue}%";
  $params = array_merge($params, [$like, $like, $like, $like]);
  $types .= "ssss";
}

if ($col1Search !== '') {
  if ($useRegex && preg_match('/^\^([A-Z])$/', $col1Search, $m)) {
    $where .= " AND test_name LIKE ? ";
    $params[] = $m[1] . "%";
    $types .= "s";
  } else {
    $where .= " AND test_name LIKE ? ";
    $params[] = "%{$col1Search}%";
    $types .= "s";
  }
}

$totalRes = $conn->query("SELECT COUNT(*) AS cnt FROM imported_prices");
$recordsTotal = (int)($totalRes->fetch_assoc()['cnt'] ?? 0);

$sqlCount = "SELECT COUNT(*) AS cnt FROM imported_prices $where";
$stmtCount = $conn->prepare($sqlCount);
if ($types !== '') $stmtCount->bind_param($types, ...$params);
$stmtCount->execute();
$recordsFiltered = (int)($stmtCount->get_result()->fetch_assoc()['cnt'] ?? 0);
$stmtCount->close();

$sql = "
  SELECT id, test_code, test_name, specimen, method, tat_day, price
  FROM imported_prices
  $where
  ORDER BY $orderBy $orderDir
  LIMIT ?, ?
";
$stmt = $conn->prepare($sql);

if ($types !== '') {
  $types2 = $types . "ii";
  $params2 = array_merge($params, [$start, $length]);
  $stmt->bind_param($types2, ...$params2);
} else {
  $stmt->bind_param("ii", $start, $length);
}

$stmt->execute();
$res = $stmt->get_result();

function h($v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function ha($v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$data = [];

while ($row = $res->fetch_assoc()) {
  $id = (int)$row['id'];

  $codeText = h($row['test_code'] ?? '');
  $nameText = h($row['test_name'] ?? '');
  $specText = h($row['specimen'] ?? '');
  $methText = h($row['method'] ?? '');
  $tatText  = h($row['tat_day'] ?? '');

  $priceVal = (float)($row['price'] ?? 0);

  // ✅ FIX: Send raw numeric value (no comma formatting) so JS parseFloat works correctly
  // The JS render function in import_prices.php handles the display formatting with toLocaleString
  $priceRaw = $priceVal; // e.g. 1100.00 — NOT "1,100.00"

  $codeAttr = ha($row['test_code'] ?? '');
  $nameAttr = ha($row['test_name'] ?? '');
  $specAttr = ha($row['specimen'] ?? '');
  $methAttr = ha($row['method'] ?? '');
  $tatAttr  = ha($row['tat_day'] ?? '');

  $actions = '
    <div class="d-flex justify-content-center gap-2">
      <button type="button" class="btn btn-outline-primary btn-icon edit-btn"
        title="Edit"
        data-id="'.$id.'"
        data-code="'.$codeAttr.'"
        data-name="'.$nameAttr.'"
        data-specimen="'.$specAttr.'"
        data-method="'.$methAttr.'"
        data-tat="'.$tatAttr.'"
        data-price="'.$priceVal.'">
        <i class="bi bi-pencil-square"></i>
      </button>
      <button type="button" class="btn btn-outline-danger btn-icon delete-btn"
        title="Delete"
        data-id="'.$id.'">
        <i class="bi bi-trash3-fill"></i>
      </button>
    </div>
  ';

  $data[] = [$codeText, $nameText, $specText, $methText, $tatText, $priceRaw, $actions];
}

$stmt->close();

echo json_encode([
  "draw"            => $draw,
  "recordsTotal"    => $recordsTotal,
  "recordsFiltered" => $recordsFiltered,
  "data"            => $data
]);