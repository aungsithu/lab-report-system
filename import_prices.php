<?php
// import_prices.php (FULL WORKING)
require 'includes/db.php';
require 'includes/session_check.php';
include 'includes/header.php';

function next_test_code(mysqli $conn): string {
    $sql = "SELECT MAX(CAST(SUBSTRING(test_code, 2) AS UNSIGNED)) AS mx FROM imported_prices WHERE test_code LIKE 'C%';";
    $res = $conn->query($sql);
    $mx = 0;
    if ($res && ($row = $res->fetch_assoc()) && $row['mx'] !== null) {
        $mx = (int)$row['mx'];
    }
    return 'C' . str_pad((string)($mx + 1), 3, '0', STR_PAD_LEFT);
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['add_test'])) {
    $code     = next_test_code($conn);
    $name     = trim($_POST['add_name'] ?? '');
    $specimen = trim($_POST['add_specimen'] ?? '');
    $method   = trim($_POST['add_method'] ?? '');
    $tat      = trim($_POST['add_tat'] ?? '');
    $price    = $_POST['add_price'] ?? null;
    if ($name === '' || $price === null || $price === '' || !is_numeric($price)) {
        echo "<script>alert('Invalid test name or price'); location.href='import_prices.php';</script>"; exit;
    }
    $price = (float)$price;
    $stmt = $conn->prepare("INSERT INTO imported_prices (test_code, test_name, specimen, method, tat_day, price) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssd", $code, $name, $specimen, $method, $tat, $price);
    $stmt->execute();
    echo "<script>location.href='import_prices.php';</script>"; exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['update_test'])) {
    $id       = (int)($_POST['edit_id'] ?? 0);
    $name     = trim($_POST['edit_name'] ?? '');
    $specimen = trim($_POST['edit_specimen'] ?? '');
    $method   = trim($_POST['edit_method'] ?? '');
    $tat      = trim($_POST['edit_tat'] ?? '');
    $price    = $_POST['edit_price'] ?? null;
    if ($id <= 0 || $name === '' || $price === null || $price === '' || !is_numeric($price)) {
        echo "<script>alert('Invalid edit data'); location.href='import_prices.php';</script>"; exit;
    }
    $price = (float)$price;
    $stmt = $conn->prepare("UPDATE imported_prices SET test_name=?, specimen=?, method=?, tat_day=?, price=? WHERE id=? LIMIT 1");
    $stmt->bind_param("ssssdi", $name, $specimen, $method, $tat, $price, $id);
    $stmt->execute();
    echo "<script>location.href='import_prices.php';</script>"; exit;
}

if (isset($_GET['delete_id'])) {
    $id = (int)$_GET['delete_id'];
    if ($id > 0) {
        $stmt = $conn->prepare("DELETE FROM imported_prices WHERE id=? LIMIT 1");
        $stmt->bind_param("i", $id);
        $stmt->execute();
    }
    echo "<script>location.href='import_prices.php';</script>"; exit;
}

$imported    = false;
$importError = '';
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_FILES["csv_file"])) {
    $file = $_FILES["csv_file"]["tmp_name"];
    if (!is_uploaded_file($file)) {
        $importError = "CSV upload failed.";
    } else {
        if (($handle = fopen($file, "r")) !== false) {
            $row = 0;
            $conn->begin_transaction();
            try {
                while (($data = fgetcsv($handle, 2000, ",")) !== false) {
                    if ($row++ === 0) continue;
                    $test_name = trim($data[1] ?? '');
                    $specimen  = trim($data[2] ?? '');
                    $method    = trim($data[3] ?? '');
                    $tat_day   = trim($data[4] ?? '');
                    $price_raw = str_replace(',', '', trim($data[5] ?? ''));
                    $price     = is_numeric($price_raw) ? (float)$price_raw : null;
                    if ($test_name !== '' && $price !== null) {
                        $code = next_test_code($conn);
                        $stmt = $conn->prepare("INSERT INTO imported_prices (test_code, test_name, specimen, method, tat_day, price) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("sssssd", $code, $test_name, $specimen, $method, $tat_day, $price);
                        $stmt->execute();
                    }
                }
                fclose($handle);
                $conn->commit();
                $imported = true;
            } catch (Throwable $e) {
                $conn->rollback();
                $importError = "CSV import error: " . $e->getMessage();
            }
        } else {
            $importError = "Cannot open CSV file.";
        }
    }
}
?>

<!-- DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
.ip-page {
  --ip-blue:   #2563eb;
  --ip-green:  #16a34a;
  --ip-red:    #dc2626;
  --ip-border: rgba(15,23,42,.08);
  --ip-shadow: 0 4px 24px rgba(15,23,42,.07);
  --ip-r:      14px;
}
.ip-hero {
  background: linear-gradient(120deg, #0f2444 0%, #1d4ed8 55%, #3b82f6 100%);
  border-radius: var(--ip-r); padding: 22px 28px; color: #fff;
  display: flex; align-items: center; justify-content: space-between;
  gap: 14px; flex-wrap: wrap; overflow: hidden;
  position: relative; margin-bottom: 20px;
}
.ip-hero::before { content:''; position:absolute; right:-40px; top:-40px; width:200px; height:200px; border-radius:50%; background:rgba(255,255,255,.05); }
.ip-hero::after  { content:''; position:absolute; right:80px; bottom:-60px; width:150px; height:150px; border-radius:50%; background:rgba(255,255,255,.04); }
.ip-hero h4 { font-size:20px; font-weight:750; margin:0 0 3px; letter-spacing:-.2px; position:relative; z-index:1; }
.ip-hero p  { margin:0; font-size:13px; opacity:.78; position:relative; z-index:1; }
.btn-create-invoice {
  background:#fff; color:#1d4ed8; border:none; border-radius:10px;
  padding:9px 20px; font-size:14px; font-weight:700;
  display:inline-flex; align-items:center; gap:8px;
  transition:opacity .15s; cursor:pointer; white-space:nowrap;
  position:relative; z-index:1; text-decoration:none;
}
.btn-create-invoice:hover { opacity:.88; color:#1e40af; }
.ip-card {
  background:#fff; border:1px solid var(--ip-border);
  border-radius:var(--ip-r); box-shadow:var(--ip-shadow);
  overflow:hidden; margin-bottom:18px;
}
.ip-card-head {
  background:#f8fafc; border-bottom:1px solid var(--ip-border);
  padding:13px 20px; display:flex; align-items:center; justify-content:space-between; gap:10px;
}
.ip-card-head .title { font-size:14px; font-weight:700; color:#111827; display:flex; align-items:center; gap:8px; }
.ip-card-head .hint  { font-size:12px; color:#9ca3af; }
.ip-card-body { padding:20px 22px; }
.ip-field { display:flex; flex-direction:column; gap:5px; }
.ip-field label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#6b7280; margin:0; }
.ip-field label .req { color:#dc2626; }
.ip-field .form-control {
  border-radius:10px; border:1.5px solid rgba(15,23,42,.11);
  font-size:14px; padding:8px 12px; background:#f9fafb; color:#111827;
  transition:border-color .15s, box-shadow .15s;
}
.ip-field .form-control:focus { border-color:var(--ip-blue); box-shadow:0 0 0 3px rgba(37,99,235,.13); background:#fff; }
.ip-field .form-control::placeholder { color:#9ca3af; }
.btn-add-test {
  background:linear-gradient(135deg,#16a34a,#22c55e); color:#fff; border:none; border-radius:10px;
  padding:9px 16px; font-size:14px; font-weight:600;
  display:inline-flex; align-items:center; gap:6px;
  transition:opacity .15s; cursor:pointer; width:100%; justify-content:center;
}
.btn-add-test:hover { opacity:.88; }
.ip-search-wrap { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:14px; }
.ip-search-box-wrap { position:relative; flex:1; min-width:220px; }
.ip-search-box-wrap i { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#9ca3af; font-size:15px; pointer-events:none; }
#searchBox {
  border-radius:10px; border:1.5px solid rgba(15,23,42,.11);
  font-size:14px; padding:8px 12px 8px 36px;
  background:#f9fafb; color:#111827; width:100%;
  transition:border-color .15s, box-shadow .15s;
}
#searchBox:focus { border-color:var(--ip-blue); box-shadow:0 0 0 3px rgba(37,99,235,.13); background:#fff; outline:none; }
#searchBox::placeholder { color:#9ca3af; }
.alpha-bar { display:flex; flex-wrap:wrap; gap:5px; padding-top:2px; }
.alpha-btn {
  width:34px; height:34px; border-radius:8px; font-size:13px; font-weight:700;
  border:1.5px solid rgba(15,23,42,.11); background:#f9fafb; color:#374151;
  display:grid; place-items:center; cursor:pointer; transition:all .12s; padding:0;
}
.alpha-btn:hover { background:#eff6ff; border-color:#bfdbfe; color:#1d4ed8; }
.alpha-btn.active { background:#2563eb; border-color:#2563eb; color:#fff; box-shadow:0 2px 8px rgba(37,99,235,.3); }
.alpha-btn.all-btn { width:auto; padding:0 12px; font-size:12px; }

/* Table card — no overflow:hidden so DataTables scrollbar renders */
.ip-table-card {
  background:#fff; border:1px solid var(--ip-border);
  border-radius:var(--ip-r); box-shadow:var(--ip-shadow);
  margin-bottom:18px;
}

/* Style the DataTables built-in scroll body scrollbar */
.dataTables_scrollBody {
  scrollbar-width: thin;
  scrollbar-color: #94a3b8 #f1f5f9;
}
.dataTables_scrollBody::-webkit-scrollbar { width:7px; height:7px; }
.dataTables_scrollBody::-webkit-scrollbar-track { background:#f1f5f9; }
.dataTables_scrollBody::-webkit-scrollbar-thumb { background:#94a3b8; border-radius:4px; }
.dataTables_scrollBody::-webkit-scrollbar-thumb:hover { background:#64748b; }

#priceTable thead th {
  background:#f8fafc !important; font-size:11px; font-weight:800; text-transform:uppercase;
  letter-spacing:.07em; color:#64748b; padding:13px 14px !important;
  border-bottom:2px solid rgba(15,23,42,.07) !important; white-space:nowrap;
}
#priceTable tbody td {
  padding:12px 14px !important; font-size:14px; color:#111827;
  vertical-align:middle; border-color:rgba(15,23,42,.05);
}
#priceTable tbody tr:hover { background:#f0f7ff; }
.test-code-badge {
  display:inline-block; background:#eff6ff; color:#1d4ed8;
  border:1px solid #bfdbfe; border-radius:6px;
  padding:3px 9px; font-size:12.5px; font-weight:700; font-family:monospace;
}
.test-name    { font-weight:700; color:#0f172a; font-size:14px; }
.specimen-cell, .method-cell { font-size:13.5px; color:#374151; }
.tat-pill {
  display:inline-flex; align-items:center; gap:5px;
  background:#f0fdf4; color:#15803d; border:1px solid #86efac;
  border-radius:999px; padding:3px 10px; font-size:12.5px; font-weight:700;
}
.price-cell { font-size:14px; font-weight:700; color:#15803d; font-variant-numeric:tabular-nums; white-space:nowrap; }
.btn-tbl-edit {
  background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; border-radius:8px;
  padding:5px 11px; font-size:12.5px; font-weight:600;
  display:inline-flex; align-items:center; gap:4px; cursor:pointer; transition:background .12s;
}
.btn-tbl-edit:hover { background:#dbeafe; }
.btn-tbl-del {
  background:#fef2f2; color:#dc2626; border:1px solid #fecaca; border-radius:8px;
  padding:5px 11px; font-size:12.5px; font-weight:600;
  display:inline-flex; align-items:center; gap:4px; cursor:pointer; transition:background .12s;
}
.btn-tbl-del:hover { background:#fee2e2; }

/* DataTables wrapper tweaks — hide duplicate filter, style controls */
.dataTables_wrapper .dataTables_filter { display:none; }
.dataTables_wrapper .dataTables_length { padding:10px 18px; display:inline-block; }
.dataTables_wrapper .dataTables_length select {
  border-radius:9px !important; border:1.5px solid rgba(15,23,42,.11) !important;
  padding:6px 10px !important; font-size:13.5px !important;
}
.dataTables_wrapper .dataTables_info { font-size:13px; color:#6b7280; padding:10px 18px; display:inline-block; }
.dataTables_wrapper .dataTables_paginate { padding:10px 18px; float:right; }
div.dataTables_wrapper div.dataTables_paginate ul.pagination .page-link {
  border-radius:8px !important; font-size:13px; border-color:rgba(15,23,42,.10); color:#374151;
}
div.dataTables_wrapper div.dataTables_paginate ul.pagination .page-item.active .page-link {
  background:#2563eb; border-color:#2563eb;
}
/* Round the top corners of the scrollHead to match card */
.dataTables_scrollHead { border-radius:var(--ip-r) var(--ip-r) 0 0; overflow:hidden; }

.ip-modal .modal-content  { border-radius:16px; border:none; overflow:hidden; box-shadow:0 20px 60px rgba(15,23,42,.18); }
.ip-modal .modal-header   { background:linear-gradient(120deg,#0f2444,#1d4ed8); color:#fff; border:none; padding:18px 22px; }
.ip-modal .modal-header .btn-close { filter:brightness(0) invert(1); opacity:.8; }
.ip-modal .modal-title    { font-size:16px; font-weight:700; }
.ip-modal .modal-body     { padding:22px; }
.ip-modal .modal-footer   { border-top:1px solid rgba(15,23,42,.07); padding:14px 22px; }
.ip-modal .modal-label    { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#6b7280; margin:0 0 5px; display:block; }
.ip-modal .form-control   { border-radius:10px; border:1.5px solid rgba(15,23,42,.11); font-size:14px; padding:8px 12px; background:#f9fafb; color:#111827; }
.ip-modal .form-control:focus { border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.13); background:#fff; }
.ip-modal .form-control[readonly] { background:#f1f5f9; color:#374151; }
.btn-modal-save {
  background:linear-gradient(135deg,#2563eb,#4f46e5); color:#fff; border:none; border-radius:10px;
  padding:9px 22px; font-size:14px; font-weight:600;
  display:inline-flex; align-items:center; gap:7px; cursor:pointer; transition:opacity .15s;
}
.btn-modal-save:hover { opacity:.88; }
.btn-modal-cancel {
  background:#f1f5f9; color:#374151; border:1.5px solid rgba(15,23,42,.11); border-radius:10px;
  padding:9px 18px; font-size:14px; font-weight:600; cursor:pointer; transition:background .12s;
}
.btn-modal-cancel:hover { background:#e2e8f0; }
.ip-alert-success { background:#f0fdf4; border:1px solid #86efac; border-radius:10px; padding:12px 18px; color:#15803d; font-weight:600; font-size:14px; display:flex; align-items:center; gap:8px; margin-bottom:16px; }
.ip-alert-danger  { background:#fef2f2; border:1px solid #fecaca; border-radius:10px; padding:12px 18px; color:#dc2626; font-weight:600; font-size:14px; display:flex; align-items:center; gap:8px; margin-bottom:16px; }
</style>

<div class="ip-page">

  <div class="ip-hero">
    <div>
      <h4><i class="bi bi-tags me-2" style="opacity:.85;"></i>Price List</h4>
      <p>Add, edit and manage test pricing. Codes are auto-generated (C001, C002…)</p>
    </div>
    <button type="button" class="btn-create-invoice" data-bs-toggle="modal" data-bs-target="#goCreateInvoiceModal">
      <i class="bi bi-receipt-cutoff"></i> Create Invoice
    </button>
  </div>

  <?php if ($imported): ?>
    <div class="ip-alert-success"><i class="bi bi-check-circle-fill"></i> CSV imported successfully!</div>
  <?php endif; ?>
  <?php if ($importError): ?>
    <div class="ip-alert-danger"><i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($importError) ?></div>
  <?php endif; ?>

  <div class="ip-card">
    <div class="ip-card-head">
      <span class="title"><i class="bi bi-plus-circle text-success"></i> Add New Test</span>
      <span class="hint">Code is auto-generated (C001, C002…)</span>
    </div>
    <div class="ip-card-body">
      <form method="POST" autocomplete="off">
        <div class="row g-3 align-items-end">
          <div class="col-md-4">
            <div class="ip-field">
              <label>Test Name <span class="req">*</span></label>
              <input type="text" name="add_name" class="form-control" placeholder="e.g. Complete Blood Count" required>
            </div>
          </div>
          <div class="col-md-2">
            <div class="ip-field">
              <label>Specimen</label>
              <input type="text" name="add_specimen" class="form-control" placeholder="e.g. Blood">
            </div>
          </div>
          <div class="col-md-2">
            <div class="ip-field">
              <label>Method</label>
              <input type="text" name="add_method" class="form-control" placeholder="e.g. Automate">
            </div>
          </div>
          <div class="col-md-1">
            <div class="ip-field">
              <label>TAT</label>
              <input type="text" name="add_tat" class="form-control" placeholder="Days">
            </div>
          </div>
          <div class="col-md-2">
            <div class="ip-field">
              <label>Price (฿) <span class="req">*</span></label>
              <input type="number" step="0.01" min="0" name="add_price" class="form-control" placeholder="0.00" required>
            </div>
          </div>
          <div class="col-md-1">
            <button type="submit" name="add_test" class="btn-add-test">
              <i class="bi bi-plus-circle"></i> Add
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <div class="ip-card">
    <div class="ip-card-head">
      <span class="title"><i class="bi bi-search text-primary"></i> Search & Filter</span>
    </div>
    <div class="ip-card-body">
      <div class="ip-search-wrap">
        <div class="ip-search-box-wrap">
          <i class="bi bi-search"></i>
          <input type="text" id="searchBox" placeholder="Search by test name, method, specimen…">
        </div>
        <span style="font-size:12.5px;color:#6b7280;font-weight:600;white-space:nowrap;">
          <i class="bi bi-info-circle me-1"></i>Tap a letter to filter
        </span>
      </div>
      <div class="alpha-bar" id="alphabet-filter">
        <?php foreach (range('A', 'Z') as $letter): ?>
          <button type="button" class="alpha-btn" data-letter="<?= $letter ?>"><?= $letter ?></button>
        <?php endforeach; ?>
        <button type="button" class="alpha-btn all-btn active" data-letter="">All</button>
      </div>
    </div>
  </div>

  <div class="ip-table-card">
    <table id="priceTable" class="table table-hover align-middle w-100 mb-0">
      <thead>
        <tr>
          <th>Code</th>
          <th>Test Name</th>
          <th>Specimen</th>
          <th>Method</th>
          <th>TAT (Day)</th>
          <th class="text-end">Price (฿)</th>
          <th class="text-center" style="width:140px;">Actions</th>
        </tr>
      </thead>
    </table>
  </div>

</div>

<!-- Modal: Create Invoice -->
<div class="modal fade ip-modal" id="goCreateInvoiceModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-receipt-cutoff me-2"></i>Create Invoice</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label class="modal-label">Select Clinic</label>
        <select id="goClinic" class="form-control">
          <option value="">— Select a clinic —</option>
          <?php
            $rs = $conn->query("SELECT id, name FROM clinics ORDER BY name ASC");
            while ($c = $rs->fetch_assoc()):
          ?>
            <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
          <?php endwhile; ?>
        </select>
        <p style="font-size:12.5px;color:#9ca3af;margin-top:10px;margin-bottom:0;">
          <i class="bi bi-info-circle me-1"></i>You can adjust prices and discounts on the next page.
        </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
        <button type="button" id="goCreateInvoiceBtn" class="btn-modal-save">
          <i class="bi bi-arrow-right-circle"></i> Continue
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Edit Test -->
<div class="modal fade ip-modal" id="editModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" id="editForm">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Edit Test</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="edit_id" id="edit_id">
          <div class="row g-3">
            <div class="col-12">
              <label class="modal-label">Test Code</label>
              <input type="text" id="edit_code" class="form-control" readonly>
            </div>
            <div class="col-12">
              <label class="modal-label">Test Name *</label>
              <input type="text" name="edit_name" id="edit_name" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="modal-label">Specimen</label>
              <input type="text" name="edit_specimen" id="edit_specimen" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="modal-label">Method</label>
              <input type="text" name="edit_method" id="edit_method" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="modal-label">TAT (Day)</label>
              <input type="text" name="edit_tat" id="edit_tat" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="modal-label">Price (฿) *</label>
              <input type="number" step="0.01" min="0" name="edit_price" id="edit_price" class="form-control" required>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="update_test" class="btn-modal-save">
            <i class="bi bi-floppy"></i> Save Changes
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
$(document).ready(function () {

  const table = $('#priceTable').DataTable({
    processing: true,
    serverSide: true,
    deferRender: true,
    searchDelay: 300,
    ajax: { url: 'ajax_table_data.php', type: 'POST' },
    pageLength: 50,
    order: [[1, 'asc']],
    scrollY: '520px',
    scrollCollapse: true,
    columns: [
      { data: 0, render: d => '<span class="test-code-badge">' + d + '</span>' },
      { data: 1, render: d => '<span class="test-name">' + d + '</span>' },
      { data: 2, render: d => d ? '<span class="specimen-cell">' + d + '</span>' : '<span style="color:#d1d5db;">—</span>' },
      { data: 3, render: d => d ? '<span class="method-cell">' + d + '</span>' : '<span style="color:#d1d5db;">—</span>' },
      { data: 4, render: d => d ? '<span class="tat-pill"><i class="bi bi-clock" style="font-size:11px;"></i> ' + d + '</span>' : '<span style="color:#d1d5db;">—</span>' },
      {
        data: 5, className: 'text-end',
        render: d => {
          const num = parseFloat(d);
          return '<span class="price-cell">฿' + (isNaN(num) ? d : num.toLocaleString('en-US', {minimumFractionDigits:2,maximumFractionDigits:2})) + '</span>';
        }
      },
      { data: 6, orderable: false, searchable: false, className: 'text-center', render: d => d }
    ],
    autoWidth: false,
    language: {
      processing:  '<div style="color:#2563eb;font-weight:600;padding:10px;">Loading…</div>',
      emptyTable:  '<div style="color:#9ca3af;padding:30px 0;font-size:14px;text-align:center;">No tests found.</div>',
      zeroRecords: '<div style="color:#9ca3af;padding:30px 0;font-size:14px;text-align:center;">No matching tests found.</div>'
    }
  });

  $('#searchBox').on('keyup', function () { table.search(this.value).draw(); });

  $('.alpha-btn').on('click', function () {
    const letter = $(this).data('letter');
    table.column(1).search(letter ? ('^' + letter) : '', true, false).draw();
    $('.alpha-btn').removeClass('active');
    $(this).addClass('active');
  });

  $(document).on('click', '.edit-btn', function () {
    $('#edit_id').val($(this).data('id'));
    $('#edit_code').val($(this).data('code') || '');
    $('#edit_name').val($(this).data('name') || '');
    $('#edit_specimen').val($(this).data('specimen') || '');
    $('#edit_method').val($(this).data('method') || '');
    $('#edit_tat').val($(this).data('tat') || '');
    $('#edit_price').val($(this).data('price') || '');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('editModal')).show();
  });

  $(document).on('click', '.delete-btn', function () {
    const id = $(this).data('id');
    Swal.fire({
      title: 'Delete this test?', text: 'This test will be permanently removed.',
      icon: 'warning', showCancelButton: true,
      confirmButtonText: 'Yes, delete', cancelButtonText: 'Cancel',
      confirmButtonColor: '#dc2626', cancelButtonColor: '#6b7280'
    }).then(r => { if (r.isConfirmed) window.location.href = 'import_prices.php?delete_id=' + encodeURIComponent(id); });
  });

  document.getElementById('goCreateInvoiceBtn').addEventListener('click', function () {
    const cid = document.getElementById('goClinic').value || '';
    window.location = 'create_invoice.php' + (cid ? ('?clinic_id=' + encodeURIComponent(cid)) : '');
  });

});
</script>

<?php include 'includes/footer.php'; ?>