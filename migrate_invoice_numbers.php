<?php
// migrate_invoice_numbers.php  (put in project root, visit once while logged in as admin)
ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'includes/db.php';
require 'includes/session_check.php';

// Only allow superadmin/admin just in case
$role = $_SESSION['role'] ?? $_SESSION['user_role'] ?? '';
if (!in_array($role, ['superadmin','admin'])) { die('Forbidden'); }

$dryRun = isset($_GET['dry']) ? true : false; // add ?dry=1 to preview only

$rows = $conn->query("SELECT id, created_at FROM invoices ORDER BY created_at ASC, id ASC");

$seqByMonth = [];   // 'yymm' => next seq
$updates = [];

while ($r = $rows->fetch_assoc()) {
    $id = (int)$r['id'];
    $ts = strtotime($r['created_at'] ?? 'now');
    $yymm = date('ym', $ts);              // e.g. '2509'
    $seqByMonth[$yymm] = ($seqByMonth[$yymm] ?? 0) + 1;
    $seq = str_pad((string)$seqByMonth[$yymm], 4, '0', STR_PAD_LEFT);
    $newNo = "Q-{$yymm}{$seq}";
    $updates[] = [$id, $newNo];
}

echo "<pre>";
echo "Will update ".count($updates)." invoices\n\n";
foreach ($updates as [$id, $newNo]) {
    echo "ID {$id} -> {$newNo}\n";
}
echo "</pre>";

if ($dryRun) {
    echo "<p><b>Dry-run only.</b> Append <code>?go=1</code> to actually write.</p>";
    exit;
}

if (!isset($_GET['go'])) {
    echo "<p>To write changes, run: <code>?go=1</code> (you can preview with <code>?dry=1</code>)</p>";
    exit;
}

$conn->begin_transaction();

$upd = $conn->prepare("UPDATE invoices SET invoice_number = ? WHERE id = ?");
foreach ($updates as [$id, $newNo]) {
    $upd->bind_param('si', $newNo, $id);
    $upd->execute();
}
$upd->close();

$conn->commit();

echo "<p><b>Done!</b> All invoice numbers updated.</p>";
