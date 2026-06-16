<?php
// test_prices_by_clinic.php (FULL COMPLETE WORKING) — UI improved only
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'includes/session_check.php';
require 'includes/db.php';
include 'includes/header.php';

date_default_timezone_set('Asia/Bangkok');

/* --------- Inputs --------- */
$clinic_id  = (int)($_GET['clinic_id'] ?? 0);
$invoice_id = (int)($_GET['invoice_id'] ?? 0);

/* Auto-derive clinic_id from invoice if missing */
if ($clinic_id <= 0 && $invoice_id > 0) {
    $row = $conn->query("SELECT clinic_id FROM invoices WHERE id = {$invoice_id}")->fetch_assoc();
    if (!empty($row['clinic_id'])) $clinic_id = (int)$row['clinic_id'];
}

/* Optional: allow saving patient_name via GET */
if (!empty($_GET['patient_name']) && $invoice_id > 0) {
    $pn = trim($_GET['patient_name']);
    $stmt = $conn->prepare("UPDATE invoices SET patient_name = ? WHERE id = ?");
    $stmt->bind_param("si", $pn, $invoice_id);
    $stmt->execute();
    header("Location: test_prices_by_clinic.php?clinic_id={$clinic_id}&invoice_id={$invoice_id}");
    exit;
}

/* Validate */
if ($clinic_id <= 0 || $invoice_id <= 0) {
    echo "<div class='alert alert-danger m-3'>Invalid clinic or invoice ID.</div>";
    include 'includes/footer.php'; exit;
}

$invChk = $conn->query("SELECT id FROM invoices WHERE id = {$invoice_id} AND clinic_id = {$clinic_id}")->fetch_assoc();
if (!$invChk) {
    echo "<div class='alert alert-danger m-3'>Invoice does not belong to this clinic.</div>";
    include 'includes/footer.php'; exit;
}

function norm_name(string $s): string {
    return preg_replace('/\s+/u', ' ', trim($s));
}

/* ---------- Save Changes ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!empty($_POST['deleted_ids_json'])) {
        $deleted_ids = json_decode($_POST['deleted_ids_json'], true) ?: [];
        foreach ($deleted_ids as $id) {
            $id = (int)$id;
            if ($id <= 0) continue;
            $stmt = $conn->prepare("DELETE FROM test_prices WHERE id = ? AND clinic_id = ? AND invoice_id = ?");
            $stmt->bind_param("iii", $id, $clinic_id, $invoice_id);
            $stmt->execute();
        }
    }

    if (!empty($_POST['tests']) && is_array($_POST['tests'])) {
        foreach ($_POST['tests'] as $row) {
            $row_id    = (int)($row['id'] ?? 0);
            $test_code = trim((string)($row['test_code'] ?? ''));
            $test_name = norm_name((string)($row['test_name'] ?? ''));
            if ($test_name === '' && $test_code === '') continue;

            $price    = (float)($row['price'] ?? 0);
            $discount = (float)($row['discount'] ?? 0);

            if ($test_name === '' && $test_code !== '') {
                $stmt = $conn->prepare("SELECT test_name FROM imported_prices WHERE test_code = ? LIMIT 1");
                $stmt->bind_param("s", $test_code); $stmt->execute();
                $tmp = $stmt->get_result()->fetch_assoc(); $stmt->close();
                if (!empty($tmp['test_name'])) $test_name = norm_name($tmp['test_name']);
            }
            if ($test_code === '' && $test_name !== '') {
                $stmt = $conn->prepare("SELECT test_code FROM imported_prices WHERE test_name = ? LIMIT 1");
                $stmt->bind_param("s", $test_name); $stmt->execute();
                $tmp = $stmt->get_result()->fetch_assoc(); $stmt->close();
                if (!empty($tmp['test_code'])) $test_code = trim($tmp['test_code']);
            }

            if ($row_id > 0) {
                // Update existing row by its PK — no dedup needed
                $upd = $conn->prepare("UPDATE test_prices SET test_code=?, test_name=?, price=?, discount_percent=? WHERE id=? AND clinic_id=? AND invoice_id=?");
                $upd->bind_param("ssddiii", $test_code, $test_name, $price, $discount, $row_id, $clinic_id, $invoice_id);
                $upd->execute(); $upd->close(); continue;
            }

            // New row (row_id = 0): check for duplicate by test_code (preferred) or name
            // FIX: previously name-based dedup collapsed tests with same name but different codes
            if ($test_code !== '') {
                $chk = $conn->prepare("SELECT id FROM test_prices WHERE invoice_id=? AND test_code=? LIMIT 1");
                $chk->bind_param("is", $invoice_id, $test_code);
            } else {
                $chk = $conn->prepare("SELECT id FROM test_prices WHERE invoice_id=? AND test_name=? LIMIT 1");
                $chk->bind_param("is", $invoice_id, $test_name);
            }
            $chk->execute();
            $exists = $chk->get_result()->fetch_assoc(); $chk->close();

            if ($exists && !empty($exists['id'])) {
                $exist_id = (int)$exists['id'];
                $upd = $conn->prepare("UPDATE test_prices SET test_code=?, test_name=?, price=?, discount_percent=? WHERE id=? AND clinic_id=? AND invoice_id=?");
                $upd->bind_param("ssddiii", $test_code, $test_name, $price, $discount, $exist_id, $clinic_id, $invoice_id);
                $upd->execute(); $upd->close();
            } else {
                $ins = $conn->prepare("INSERT INTO test_prices (clinic_id, invoice_id, test_code, test_name, price, discount_percent, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                $ins->bind_param("iissdd", $clinic_id, $invoice_id, $test_code, $test_name, $price, $discount);
                $ins->execute(); $ins->close();
            }
        }
    }

    echo "<div class='tp-alert-success mb-3'><i class='bi bi-check-circle-fill'></i> Saved successfully.</div>";
}

/* --------- Fetch Data --------- */
$stmt = $conn->prepare("SELECT id, test_code, test_name, price, discount_percent FROM test_prices WHERE clinic_id=? AND invoice_id=? ORDER BY id ASC");
$stmt->bind_param("ii", $clinic_id, $invoice_id);
$stmt->execute();
$data = $stmt->get_result();
$rows = [];
while ($r = $data->fetch_assoc()) $rows[] = $r;
$stmt->close();

$clinic = $conn->query("SELECT name FROM clinics WHERE id={$clinic_id}")->fetch_assoc() ?: ['name' => ''];

$invRow = $conn->query("SELECT patient_name, tax_id, tel, customer_address, salesman_no FROM invoices WHERE id={$invoice_id}")->fetch_assoc() ?: [];
$patient_name_existing = $invRow['patient_name'] ?? '';
$tax_id_existing       = $invRow['tax_id']       ?? '';
$tel_existing          = $invRow['tel']           ?? '';
$sales_no_existing     = $invRow['salesman_no']   ?? '';
$addr_existing         = $invRow['customer_address'] ?? '';
?>

<style>
.tp-page {
  --tp-blue:   #2563eb;
  --tp-green:  #16a34a;
  --tp-red:    #dc2626;
  --tp-amber:  #d97706;
  --tp-border: rgba(15,23,42,.08);
  --tp-shadow: 0 4px 24px rgba(15,23,42,.07);
  --tp-r:      14px;
}

/* ── Hero ── */
.tp-hero {
  background: linear-gradient(120deg, #0f2444 0%, #1d4ed8 55%, #3b82f6 100%);
  border-radius: var(--tp-r);
  padding: 20px 26px;
  color: #fff;
  display: flex; align-items: center; justify-content: space-between;
  gap: 14px; flex-wrap: wrap; overflow: hidden;
  position: relative; margin-bottom: 20px;
}
.tp-hero::before { content:''; position:absolute; right:-40px; top:-40px; width:200px; height:200px; border-radius:50%; background:rgba(255,255,255,.05); }
.tp-hero::after  { content:''; position:absolute; right:80px; bottom:-60px; width:150px; height:150px; border-radius:50%; background:rgba(255,255,255,.04); }
.tp-hero h4 { font-size:18px; font-weight:750; margin:0 0 3px; letter-spacing:-.2px; position:relative; z-index:1; line-height:1.3; }
.tp-hero p  { margin:0; font-size:13px; opacity:.75; position:relative; z-index:1; }
.tp-hero-badges { display:flex; gap:8px; flex-wrap:wrap; align-items:center; position:relative; z-index:1; }

/* ── Customer info strip ── */
.tp-customer-strip {
  background: #f0fdf4; border: 1px solid #86efac; border-radius: 10px;
  padding: 10px 16px; display: flex; align-items: center; gap: 10px;
  flex-wrap: wrap; margin-bottom: 18px;
}
.tp-customer-strip .label { font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:#15803d; }
.tp-customer-chip {
  display: inline-flex; align-items: center; gap: 5px;
  background: #fff; border: 1px solid #86efac; border-radius: 7px;
  padding: 3px 10px; font-size: 13px; font-weight: 600; color: #111827;
}
.btn-edit-customer {
  background: #fff; color: #15803d; border: 1.5px solid #86efac; border-radius: 8px;
  padding: 4px 12px; font-size: 13px; font-weight: 600; cursor: pointer;
  display: inline-flex; align-items: center; gap: 5px; transition: background .12s;
  margin-left: auto;
}
.btn-edit-customer:hover { background: #f0fdf4; }

/* ── Cards ── */
.tp-card {
  background: #fff; border: 1px solid var(--tp-border);
  border-radius: var(--tp-r); box-shadow: var(--tp-shadow); overflow: hidden; margin-bottom: 18px;
}
.tp-card-head {
  background: #f8fafc; border-bottom: 1px solid var(--tp-border);
  padding: 12px 18px;
  display: flex; align-items: center; justify-content: space-between; gap: 10px;
}
.tp-card-head .title { font-size: 14px; font-weight: 700; color: #111827; display: flex; align-items: center; gap: 8px; }
.tp-card-head .hint  { font-size: 12px; color: #9ca3af; }
.tp-card-body { padding: 18px 20px; }

/* ── Search row ── */
.tp-search-row {
  display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
}
.tp-search-wrap { position: relative; flex: 1; min-width: 240px; }
.tp-search-wrap i { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#9ca3af; font-size:15px; pointer-events:none; }
#test_search {
  border-radius: 10px; border: 1.5px solid rgba(15,23,42,.11);
  font-size: 14px; padding: 9px 12px 9px 36px; background: #f9fafb;
  color: #111827; width: 100%; transition: border-color .15s, box-shadow .15s;
}
#test_search:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.13); background:#fff; outline:none; }
#test_search::placeholder { color: #9ca3af; }
#autocomplete-results {
  position: absolute; width: 100%; top: calc(100% + 4px); left: 0;
  background: #fff; border: 1px solid rgba(15,23,42,.10);
  border-radius: 10px; box-shadow: 0 8px 30px rgba(15,23,42,.12);
  overflow: hidden; z-index: 1000;
}
#autocomplete-results .list-group-item {
  border: none; border-bottom: 1px solid rgba(15,23,42,.05);
  padding: 10px 14px; font-size: 14px; color: #111827;
  cursor: pointer; transition: background .1s;
}
#autocomplete-results .list-group-item:hover { background: #eff6ff; color: #1d4ed8; }
#autocomplete-results .list-group-item:last-child { border-bottom: none; }

.btn-add-row {
  background: linear-gradient(135deg, #16a34a, #22c55e); color: #fff; border: none;
  border-radius: 10px; padding: 9px 20px; font-size: 14px; font-weight: 600;
  display: inline-flex; align-items: center; gap: 7px; cursor: pointer;
  transition: opacity .15s; white-space: nowrap;
}
.btn-add-row:hover { opacity: .88; }

/* ── Tests table with scrollbar ── */
.tp-table-card { background: #fff; border: 1px solid var(--tp-border); border-radius: var(--tp-r); box-shadow: var(--tp-shadow); margin-bottom: 18px; }
.tp-table-scroll {
  max-height: 600px;
  overflow-y: scroll;   /* always show scrollbar so it never clips */
  overflow-x: auto;
  border-radius: var(--tp-r);
}
.tp-table-scroll::-webkit-scrollbar { width: 8px; height: 8px; }
.tp-table-scroll::-webkit-scrollbar-track { background: #f1f5f9; }
.tp-table-scroll::-webkit-scrollbar-thumb { background: #94a3b8; border-radius: 4px; }
.tp-table-scroll::-webkit-scrollbar-thumb:hover { background: #475569; }
/* Firefox */
.tp-table-scroll { scrollbar-width: thin; scrollbar-color: #94a3b8 #f1f5f9; }

#testTable thead th {
  background: #f8fafc; font-size: 11px; font-weight: 800;
  text-transform: uppercase; letter-spacing: .07em; color: #64748b;
  padding: 12px 14px !important; border-bottom: 2px solid rgba(15,23,42,.07);
  white-space: nowrap;
  position: sticky; top: 0; z-index: 2;
}
#testTable tbody td {
  padding: 10px 14px !important; font-size: 14px; color: #111827;
  vertical-align: middle; border-color: rgba(15,23,42,.05);
}
#testTable tbody tr:hover { background: #f8fafc; }
#testTable tbody tr:hover td:first-child { color: #2563eb; }

.row-num { font-size:13px; font-weight:700; color:#374151; }

/* ── Inline inputs ── */
.tp-code-field {
  font-family: monospace; font-size: 13px; font-weight: 700; color: #1d4ed8;
  background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px;
  padding: 3px 8px; display: inline-block;
}
.tp-name-field {
  font-size: 14px; font-weight: 600; color: #0f172a;
}
.tp-price-input, .tp-discount-input {
  border-radius: 9px; border: 1.5px solid rgba(15,23,42,.11);
  font-size: 14px; padding: 6px 10px; background: #f9fafb; color: #111827;
  font-weight: 600; width: 120px;
  transition: border-color .15s, box-shadow .15s;
}
.tp-price-input:focus, .tp-discount-input:focus {
  border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.12); background: #fff; outline: none;
}
.btn-del-row {
  background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; border-radius: 8px;
  padding: 5px 12px; font-size: 12.5px; font-weight: 600;
  display: inline-flex; align-items: center; gap: 4px; cursor: pointer; transition: background .12s;
}
.btn-del-row:hover { background: #fee2e2; }

/* ── Empty state ── */
.tp-empty { text-align: center; padding: 40px 20px; color: #9ca3af; }
.tp-empty i { font-size: 32px; display: block; margin-bottom: 8px; opacity:.35; }
.tp-empty p { margin:0; font-size:14px; }

/* ── Summary card ── */
.tp-summary-card {
  background: #fff; border: 1px solid var(--tp-border); border-radius: var(--tp-r);
  box-shadow: var(--tp-shadow); overflow: hidden; margin-bottom: 18px;
}
.tp-summary-head {
  background: #f8fafc; border-bottom: 1px solid var(--tp-border);
  padding: 12px 18px; font-size: 14px; font-weight: 700; color: #111827;
  display: flex; align-items: center; gap: 8px;
}
.tp-summary-body { padding: 18px 20px; }
.tp-summary-row {
  display: flex; align-items: center; justify-content: space-between;
  padding: 10px 0; border-bottom: 1px solid rgba(15,23,42,.06);
  font-size: 14px;
}
.tp-summary-row:last-child { border-bottom: none; }
.tp-summary-row .s-label { font-weight: 600; color: #374151; display: flex; align-items: center; gap: 7px; }
.tp-summary-row .s-val   { font-size: 15px; font-weight: 700; color: #0f172a; font-variant-numeric: tabular-nums; }
.tp-summary-net { background: #f0fdf4; border-radius: 10px; padding: 12px 16px; margin-top: 8px; display: flex; align-items: center; justify-content: space-between; }
.tp-summary-net .s-label { font-size: 15px; font-weight: 800; color: #15803d; display: flex; align-items: center; gap: 8px; }
.tp-summary-net .s-val   { font-size: 22px; font-weight: 800; color: #15803d; font-variant-numeric: tabular-nums; }

/* ── Action buttons row ── */
.tp-actions {
  display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 24px;
}
.btn-save-changes {
  background: linear-gradient(135deg, #2563eb, #4f46e5); color: #fff; border: none; border-radius: 10px;
  padding: 10px 24px; font-size: 14px; font-weight: 700;
  display: inline-flex; align-items: center; gap: 8px; cursor: pointer; transition: opacity .15s;
}
.btn-save-changes:hover { opacity: .88; }
.btn-tp-pdf {
  border-radius: 10px; padding: 9px 18px; font-size: 14px; font-weight: 600;
  display: inline-flex; align-items: center; gap: 7px; text-decoration: none;
  transition: opacity .15s; border: none; cursor: pointer;
}
.btn-tp-pdf:hover { opacity: .85; }
.btn-tp-patient  { background: #0891b2; color: #fff; }
.btn-tp-quotation{ background: #7c3aed; color: #fff; }
.btn-tp-alltests { background: #475569; color: #fff; }
.btn-tp-receipt  { background: #d97706; color: #fff; }

/* ── Alerts ── */
.tp-alert-success { background:#f0fdf4; border:1px solid #86efac; border-radius:10px; padding:12px 18px; color:#15803d; font-weight:600; font-size:14px; display:flex; align-items:center; gap:8px; }
.tp-alert-danger  { background:#fef2f2; border:1px solid #fecaca; border-radius:10px; padding:12px 18px; color:#dc2626; font-weight:600; font-size:14px; display:flex; align-items:center; gap:8px; }

/* ── Modals ── */
.tp-modal .modal-content  { border-radius:16px; border:none; overflow:hidden; box-shadow:0 20px 60px rgba(15,23,42,.18); }
.tp-modal .modal-header   { background:linear-gradient(120deg,#0f2444,#1d4ed8); color:#fff; border:none; padding:18px 22px; }
.tp-modal .modal-header .btn-close { filter:brightness(0) invert(1); opacity:.8; }
.tp-modal .modal-title    { font-size:16px; font-weight:700; }
.tp-modal .modal-body     { padding:22px; }
.tp-modal .modal-footer   { border-top:1px solid rgba(15,23,42,.07); padding:14px 22px; }
.tp-modal .modal-label    { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#6b7280; margin:0 0 5px; display:block; }
.tp-modal .form-control, .tp-modal .form-control-plaintext {
  border-radius:10px; border:1.5px solid rgba(15,23,42,.11); font-size:14px;
  padding:8px 12px; background:#f9fafb; color:#111827;
}
.tp-modal .form-control:focus { border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.13); background:#fff; }
.tp-modal textarea.form-control { min-height:80px; resize:vertical; }
.btn-modal-save   { background:linear-gradient(135deg,#2563eb,#4f46e5); color:#fff; border:none; border-radius:10px; padding:9px 22px; font-size:14px; font-weight:600; display:inline-flex; align-items:center; gap:7px; cursor:pointer; transition:opacity .15s; }
.btn-modal-save:hover { opacity:.88; }
.btn-modal-cancel { background:#f1f5f9; color:#374151; border:1.5px solid rgba(15,23,42,.11); border-radius:10px; padding:9px 18px; font-size:14px; font-weight:600; cursor:pointer; transition:background .12s; }
.btn-modal-cancel:hover { background:#e2e8f0; }

/* ── Invoice badge chip ── */
.tp-inv-chip {
  background:rgba(255,255,255,.18); border:1px solid rgba(255,255,255,.28);
  border-radius:8px; padding:4px 12px; font-size:13px; font-weight:700;
  display:inline-flex; align-items:center; gap:6px;
}
</style>

<div class="tp-page">

  <!-- Hero -->
  <div class="tp-hero">
    <div>
      <h4>
        <i class="bi bi-receipt-cutoff me-2" style="opacity:.85;"></i>
        <?= htmlspecialchars($clinic['name'] ?? '') ?>
      </h4>
      <p>Test prices for this invoice — add, adjust, and save</p>
    </div>
    <div class="tp-hero-badges">
      <span class="tp-inv-chip">
        <i class="bi bi-hash" style="font-size:12px;"></i>
        Invoice <?= (int)$invoice_id ?>
      </span>
    </div>
  </div>

  <!-- Customer info strip -->
  <?php if ($patient_name_existing || $tax_id_existing || $tel_existing || $addr_existing): ?>
    <div class="tp-customer-strip">
      <span class="label"><i class="bi bi-person-check me-1"></i>Customer</span>
      <?php if ($patient_name_existing): ?>
        <span class="tp-customer-chip"><i class="bi bi-person" style="font-size:12px;color:#15803d;"></i><?= htmlspecialchars($patient_name_existing) ?></span>
      <?php endif; ?>
      <?php if ($tax_id_existing): ?>
        <span class="tp-customer-chip"><i class="bi bi-card-text" style="font-size:12px;color:#0369a1;"></i>Tax: <?= htmlspecialchars($tax_id_existing) ?></span>
      <?php endif; ?>
      <?php if ($tel_existing): ?>
        <span class="tp-customer-chip"><i class="bi bi-telephone" style="font-size:12px;color:#6b7280;"></i><?= htmlspecialchars($tel_existing) ?></span>
      <?php endif; ?>
      <?php if ($addr_existing): ?>
        <span class="tp-customer-chip"><i class="bi bi-geo-alt" style="font-size:12px;color:#6b7280;"></i><?= htmlspecialchars(mb_strimwidth($addr_existing, 0, 40, '…')) ?></span>
      <?php endif; ?>
      <button type="button" class="btn-edit-customer" id="btnEditCustomer">
        <i class="bi bi-pencil" style="font-size:12px;"></i> Edit
      </button>
    </div>
  <?php else: ?>
    <div id="customerInfoBox" style="display:none;"></div>
  <?php endif; ?>

  <form method="POST" id="mainForm">
    <input type="hidden" name="deleted_ids_json" id="deleted_ids_json" value="[]">

    <!-- Search & Add -->
    <div class="tp-card">
      <div class="tp-card-head">
        <span class="title"><i class="bi bi-search text-primary"></i> Search & Add Test</span>
        <span class="hint">Type code or name, select from dropdown, then click Add</span>
      </div>
      <div class="tp-card-body">
        <div class="tp-search-row">
          <div class="tp-search-wrap">
            <i class="bi bi-search"></i>
            <input type="text" id="test_search" placeholder="Search by test code or name…" autocomplete="off">
            <div id="autocomplete-results"></div>
          </div>
          <button type="button" class="btn-add-row" id="btnAddRow">
            <i class="bi bi-plus-circle"></i> Add Test
          </button>
        </div>
      </div>
    </div>

    <!-- Tests Table -->
    <div class="tp-table-card">
      <div class="tp-table-scroll">
        <table class="table table-hover align-middle mb-0" id="testTable">
          <thead>
            <tr>
              <th style="width:50px;" class="text-center">#</th>
              <th style="width:110px;">Code</th>
              <th>Test Name</th>
              <th style="width:150px;">Price (฿)</th>
              <th style="width:150px;">Discount (%)</th>
              <th style="width:110px;" class="text-center">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr id="emptyRow">
                <td colspan="6">
                  <div class="tp-empty">
                    <i class="bi bi-flask"></i>
                    <p>No tests added yet. Search above to add tests to this invoice.</p>
                  </div>
                </td>
              </tr>
            <?php else: ?>
              <?php $idx = 0; $num = 1; foreach ($rows as $row): $rid = (int)$row['id']; ?>
                <tr data-row-id="<?= $rid ?>">
                  <td class="text-center"><span class="row-num"><?= $num++ ?></span></td>
                  <td>
                    <input type="hidden" name="tests[<?= $idx ?>][id]" value="<?= $rid ?>">
                    <span class="tp-code-field"><?= htmlspecialchars($row['test_code'] ?? '—') ?></span>
                    <input type="hidden" name="tests[<?= $idx ?>][test_code]" value="<?= htmlspecialchars($row['test_code'] ?? '') ?>">
                  </td>
                  <td>
                    <span class="tp-name-field"><?= htmlspecialchars($row['test_name'] ?? '') ?></span>
                    <input type="hidden" name="tests[<?= $idx ?>][test_name]" value="<?= htmlspecialchars($row['test_name'] ?? '') ?>">
                  </td>
                  <td>
                    <input type="number" step="0.01"
                           name="tests[<?= $idx ?>][price]"
                           value="<?= number_format((float)$row['price'], 2, '.', '') ?>"
                           class="tp-price-input form-control">
                  </td>
                  <td>
                    <input type="number" step="0.01"
                           name="tests[<?= $idx ?>][discount]"
                           value="<?= number_format((float)$row['discount_percent'], 2, '.', '') ?>"
                           class="tp-discount-input form-control">
                  </td>
                  <td class="text-center">
                    <button type="button" class="btn-del-row btnDelRow">
                      <i class="bi bi-trash3" style="font-size:12px;"></i> Del
                    </button>
                  </td>
                </tr>
              <?php $idx++; endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Summary -->
    <div class="tp-summary-card">
      <div class="tp-summary-head">
        <i class="bi bi-calculator text-primary"></i> Invoice Summary
      </div>
      <div class="tp-summary-body">
        <div class="tp-summary-row">
          <span class="s-label"><i class="bi bi-list-ul" style="color:#6b7280;font-size:13px;"></i>Subtotal</span>
          <span class="s-val" id="subtotal">฿0.00</span>
        </div>
        <div class="tp-summary-row">
          <span class="s-label"><i class="bi bi-percent" style="color:#d97706;font-size:13px;"></i>Total Discount</span>
          <span class="s-val" style="color:#d97706;" id="discount_total">฿0.00</span>
        </div>
        <div class="tp-summary-net">
          <span class="s-label"><i class="bi bi-check-circle-fill"></i>Net Total</span>
          <span class="s-val" id="net_total">฿0.00</span>
        </div>
      </div>
    </div>

    <!-- Actions -->
    <div class="tp-actions">
      <button type="submit" class="btn-save-changes">
        <i class="bi bi-floppy"></i> Save Changes
      </button>

      <button type="button" class="btn-tp-pdf btn-tp-patient" id="btnPatientPdf">
        <i class="bi bi-file-earmark-person"></i> Patient PDF
      </button>

      <a href="generate_pdf_patient_quotation.php?clinic_id=<?= (int)$clinic_id ?>&invoice_id=<?= (int)$invoice_id ?>"
         target="_blank" class="btn-tp-pdf btn-tp-quotation" id="btnPatientQuotation">
        <i class="bi bi-file-earmark-text"></i> Quotation
      </a>

      <a href="generate_pdf_alltests.php?clinic_id=<?= (int)$clinic_id ?>"
         target="_blank" class="btn-tp-pdf btn-tp-alltests">
        <i class="bi bi-journals"></i> All Tests PDF
      </a>

      <a href="generate_receipt.php?clinic_id=<?= (int)$clinic_id ?>&invoice_id=<?= (int)$invoice_id ?>"
         target="_blank" class="btn-tp-pdf btn-tp-receipt">
        <i class="bi bi-receipt"></i> Receipt PDF
      </a>
    </div>

  </form>
</div><!-- /.tp-page -->

<!-- ══════════════════════════════════════
     Modal: Patient Name (for PDF)
══════════════════════════════════════ -->
<div class="modal fade tp-modal" id="patientNameModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" id="patientNameForm">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-person-badge me-2"></i>Patient Name</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="invoice_id" value="<?= (int)$invoice_id ?>">
        <label class="modal-label">Patient Name <span style="color:#dc2626;">*</span></label>
        <input type="text" class="form-control" name="patient_name" id="patient_name"
               value="<?= htmlspecialchars($patient_name_existing) ?>"
               placeholder="Enter patient full name" required>
        <p style="font-size:12px;color:#9ca3af;margin-top:8px;margin-bottom:0;">
          <i class="bi bi-info-circle me-1"></i>This will appear on the patient PDF instead of the clinic name.
        </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn-modal-save">
          <i class="bi bi-file-earmark-person"></i> Generate PDF
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ══════════════════════════════════════
     Modal: Edit Customer Info
══════════════════════════════════════ -->
<div class="modal fade tp-modal" id="customerModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" id="customerForm">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-person-vcard me-2"></i>Edit Customer Info</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="invoice_id" value="<?= (int)$invoice_id ?>">
        <div class="row g-3">
          <div class="col-12">
            <label class="modal-label">Customer Name</label>
            <input type="text" class="form-control" name="patient_name"
                   value="<?= htmlspecialchars($patient_name_existing) ?>"
                   placeholder="e.g. Somchai Jaidee">
          </div>
          <div class="col-md-6">
            <label class="modal-label">Tax ID</label>
            <input type="text" class="form-control" name="tax_id"
                   value="<?= htmlspecialchars($tax_id_existing) ?>"
                   placeholder="13-digit Tax ID">
          </div>
          <div class="col-md-6">
            <label class="modal-label">Telephone</label>
            <input type="text" class="form-control" name="tel"
                   value="<?= htmlspecialchars($tel_existing) ?>"
                   placeholder="e.g. 089-123-4567">
          </div>
          <div class="col-12">
            <label class="modal-label">Address</label>
            <textarea class="form-control" name="customer_address"
                      placeholder="Full billing address"><?= htmlspecialchars($addr_existing) ?></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn-modal-save">
          <i class="bi bi-floppy"></i> Save
        </button>
      </div>
    </form>
  </div>
</div>

<script>
/* ---------- Server-provided values ---------- */
let patientNameExisting = <?= json_encode($patient_name_existing) ?>;
const RECEIPT = {
  clinicId : <?= (int)$clinic_id ?>,
  invoiceId: <?= (int)$invoice_id ?>,
  taxId    : <?= json_encode($tax_id_existing) ?>,
  tel      : <?= json_encode($tel_existing) ?>,
  salesNo  : <?= json_encode($sales_no_existing) ?>,
  addr     : <?= json_encode($addr_existing) ?>
};

const input      = document.getElementById('test_search');
const resultsBox = document.getElementById('autocomplete-results');
let lastPick     = null;

/* ---------- Autocomplete ---------- */
if (input) {
  input.addEventListener('input', function () {
    const term = this.value.trim();
    lastPick = null;
    if (term.length < 1) { resultsBox.innerHTML = ''; return; }
    fetch(`fetch_test_names.php?term=${encodeURIComponent(term)}&with_code=1`)
      .then(res => res.json())
      .then(data => {
        resultsBox.innerHTML = '';
        (data || []).forEach(item => {
          const code  = item.test_code || '';
          const name  = item.test_name || '';
          const price = parseFloat(item.price || 0);
          const div   = document.createElement('div');
          div.className = 'list-group-item list-group-item-action';
          div.innerHTML = code
            ? `<span style="font-family:monospace;color:#1d4ed8;font-weight:700;margin-right:6px;">${code}</span><span style="color:#111827;">${name}</span>`
            : `<span style="color:#111827;">${name}</span>`;
          div.onclick = () => {
            input.value = (code ? (code + ' — ') : '') + name;
            lastPick = { code, name, price };
            resultsBox.innerHTML = '';
          };
          resultsBox.appendChild(div);
        });
      });
  });
  document.addEventListener('click', e => {
    if (!input.contains(e.target) && !resultsBox.contains(e.target)) resultsBox.innerHTML = '';
  });
}

/* ---------- Row helpers ---------- */
function removeEmptyRow() {
  const er = document.getElementById('emptyRow');
  if (er) er.remove();
}

function renumberRows() {
  document.querySelectorAll('#testTable tbody tr').forEach((tr, i) => {
    const numCell = tr.querySelector('td:first-child span.row-num');
    if (numCell) numCell.textContent = i + 1;
    tr.querySelectorAll('input').forEach(inp => {
      if (inp.name) inp.name = inp.name.replace(/tests\[\d+\]/, `tests[${i}]`);
    });
  });
}

function calculateTotals() {
  let subtotal = 0, discountTotal = 0;
  document.querySelectorAll('#testTable tbody tr').forEach(row => {
    const price    = parseFloat(row.querySelector('input[name*="[price]"]')?.value) || 0;
    const discount = parseFloat(row.querySelector('input[name*="[discount]"]')?.value) || 0;
    subtotal      += price;
    discountTotal += price * (discount / 100);
  });
  const net = subtotal - discountTotal;
  document.getElementById('subtotal').textContent      = '฿' + subtotal.toFixed(2);
  document.getElementById('discount_total').textContent = '฿' + discountTotal.toFixed(2);
  document.getElementById('net_total').textContent      = '฿' + net.toFixed(2);
}

document.addEventListener('input', e => {
  if (e.target.name?.includes('[price]') || e.target.name?.includes('[discount]')) calculateTotals();
});

/* ---------- Delete tracking ---------- */
function pushDeletedId(rowId) {
  const el  = document.getElementById('deleted_ids_json');
  const arr = JSON.parse(el.value || '[]');
  const id  = parseInt(rowId || 0);
  if (id > 0 && !arr.includes(id)) arr.push(id);
  el.value  = JSON.stringify(arr);
}

/* ---------- Add Row ---------- */
document.getElementById('btnAddRow').addEventListener('click', function () {
  let code = '', name = '', price = 0;

  if (lastPick) {
    code  = (lastPick.code  || '').trim();
    name  = (lastPick.name  || '').trim();
    price = parseFloat(lastPick.price || 0);
  } else {
    const raw = (input.value || '').trim();
    if (!raw) return;
    const m = raw.match(/^([A-Za-z]\d{3,})\s*[-—]\s*(.+)$/);
    if (m) { code = m[1].trim(); name = m[2].trim(); }
    else    { name = raw; }
  }
  if (!name && !code) return;

  const exists = Array.from(document.querySelectorAll('#testTable tbody tr')).some(tr => {
    const c = (tr.querySelector('input[name*="[test_code]"]')?.value || '').trim().toLowerCase();
    const n = (tr.querySelector('input[name*="[test_name]"]')?.value || '').trim().toLowerCase();
    return code ? c === code.toLowerCase() : n === name.toLowerCase();
  });
  if (exists) { alert('Test already added.'); return; }

  removeEmptyRow();
  const tbody = document.querySelector('#testTable tbody');
  const idx   = tbody.querySelectorAll('tr').length;
  const tr    = document.createElement('tr');
  tr.setAttribute('data-row-id', '0');
  tr.innerHTML = `
    <td class="text-center"><span class="row-num"></span></td>
    <td>
      <input type="hidden" name="tests[${idx}][id]" value="0">
      <span class="tp-code-field">${code || '—'}</span>
      <input type="hidden" name="tests[${idx}][test_code]" value="${code.replace(/"/g,'&quot;')}">
    </td>
    <td>
      <span class="tp-name-field">${name.replace(/</g,'&lt;')}</span>
      <input type="hidden" name="tests[${idx}][test_name]" value="${name.replace(/"/g,'&quot;')}">
    </td>
    <td><input type="number" step="0.01" name="tests[${idx}][price]" class="tp-price-input form-control" value="${Number(price||0).toFixed(2)}"></td>
    <td><input type="number" step="0.01" name="tests[${idx}][discount]" class="tp-discount-input form-control" value="0.00"></td>
    <td class="text-center"><button type="button" class="btn-del-row btnDelRow"><i class="bi bi-trash3" style="font-size:12px;"></i> Del</button></td>
  `;
  tbody.appendChild(tr);
  input.value = ''; lastPick = null; resultsBox.innerHTML = '';
  renumberRows(); calculateTotals();
});

/* ---------- Delete Row ---------- */
document.addEventListener('click', function (e) {
  if (!e.target.closest('.btnDelRow')) return;
  const tr    = e.target.closest('tr');
  const rowId = tr.getAttribute('data-row-id') || '0';
  if (parseInt(rowId) > 0) pushDeletedId(rowId);
  tr.remove();
  renumberRows(); calculateTotals();

  if (!document.querySelectorAll('#testTable tbody tr').length) {
    const tbody = document.querySelector('#testTable tbody');
    tbody.innerHTML = `<tr id="emptyRow"><td colspan="6"><div class="tp-empty"><i class="bi bi-flask"></i><p>No tests added. Search above to add tests.</p></div></td></tr>`;
  }
});

calculateTotals();

/* ---------- Customer modal ---------- */
document.getElementById('btnEditCustomer')?.addEventListener('click', function () {
  new bootstrap.Modal(document.getElementById('customerModal')).show();
});

/* ---------- Receipt PDF flow ---------- */
document.getElementById('btnReceiptPdf')?.addEventListener('click', async function () {
  let taxId   = (RECEIPT.taxId   || '').trim();
  let tel     = (RECEIPT.tel     || '').trim();
  let salesNo = (RECEIPT.salesNo || '').trim();
  let addr    = (RECEIPT.addr    || '').trim();

  if (!taxId)   { const v = prompt("Enter Tax ID (optional):", "");    taxId   = (v === null || v.trim() === "") ? "-" : v.trim(); }
  if (!tel)     { const v = prompt("Enter Telephone (optional):", ""); tel     = (v === null || v.trim() === "") ? "-" : v.trim(); }
  if (!salesNo) { const v = prompt("Enter Salesman No. (optional):",""); salesNo = (v === null || v.trim() === "") ? "-" : v.trim(); }
  if (!addr)    { const v = prompt("Enter Customer Address:", "-");    addr    = (v === null || v.trim() === "") ? "-" : v.trim(); }

  const changed = taxId !== (RECEIPT.taxId||'') || tel !== (RECEIPT.tel||'') || salesNo !== (RECEIPT.salesNo||'') || addr !== (RECEIPT.addr||'');
  if (changed) {
    const fd = new FormData();
    fd.append('invoice_id', RECEIPT.invoiceId);
    fd.append('tax_id', taxId); fd.append('tel', tel);
    fd.append('salesman_no', salesNo); fd.append('customer_address', addr);
    try {
      const res  = await fetch('save_receipt_info.php', { method:'POST', body:fd, headers:{'X-Requested-With':'fetch'} });
      const data = await res.json();
      if (!data.success) { alert(data.message || 'Failed to save receipt info.'); return; }
      RECEIPT.taxId = taxId; RECEIPT.tel = tel; RECEIPT.salesNo = salesNo; RECEIPT.addr = addr;
    } catch (err) { console.error(err); alert('Network error.'); return; }
  }
  window.open('generate_receipt.php?clinic_id=' + encodeURIComponent(RECEIPT.clinicId) + '&invoice_id=' + encodeURIComponent(RECEIPT.invoiceId), '_blank');
});

/* ---------- Customer form submit ---------- */
document.getElementById('customerForm')?.addEventListener('submit', async function (e) {
  e.preventDefault();
  const fd = new FormData(this);
  try {
    const res  = await fetch('save_receipt_info.php', { method:'POST', body:fd });
    const data = await res.json();
    if (!data.success) { alert(data.message || 'Save failed'); return; }
    location.reload();
  } catch (err) { console.error(err); alert('Network error'); }
});

/* ---------- Patient PDF flow ---------- */
document.getElementById('btnPatientPdf')?.addEventListener('click', function () {
  if (patientNameExisting && patientNameExisting.trim() !== '') {
    window.open('generate_pdf_patient.php?clinic_id=<?= (int)$clinic_id ?>&invoice_id=<?= (int)$invoice_id ?>', '_blank');
    return;
  }
  new bootstrap.Modal(document.getElementById('patientNameModal')).show();
});

document.getElementById('patientNameForm')?.addEventListener('submit', async function (e) {
  e.preventDefault();
  const fd   = new FormData(this);
  const name = (fd.get('patient_name') || '').trim();
  if (!name) { alert('Please enter the patient name.'); return; }
  try {
    const res  = await fetch('save_patient_name.php', { method:'POST', body:fd });
    const data = await res.json();
    if (!data.success) { alert(data.message || 'Failed to save patient name.'); return; }
    patientNameExisting = name;
    bootstrap.Modal.getInstance(document.getElementById('patientNameModal'))?.hide();
    window.open('generate_pdf_patient.php?clinic_id=<?= (int)$clinic_id ?>&invoice_id=<?= (int)$invoice_id ?>', '_blank');
  } catch (err) { console.error(err); alert('Network error.'); }
});
</script>

<?php include 'includes/footer.php'; ?>