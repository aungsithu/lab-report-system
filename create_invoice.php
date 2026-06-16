<?php
// create_invoice.php (FULL COMPLETE WORKING)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'includes/db.php';
require 'includes/session_check.php';

date_default_timezone_set('Asia/Bangkok');

/* ---------- Helpers ---------- */

// Generate: Q-YYMM####  (#### restarts every month)
function generate_q_invoice_no(mysqli $conn, ?DateTimeInterface $when = null): string {
    $when  = $when ?: new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok'));
    $yymm  = $when->format('ym'); // e.g. 2509

    $sql = "
      SELECT MAX(CAST(SUBSTRING(invoice_number, 7, 4) AS UNSIGNED)) AS maxseq
      FROM invoices
      WHERE invoice_number LIKE CONCAT('Q-', ?, '%')
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $yymm);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $next = (int)($row['maxseq'] ?? 0) + 1;
    return sprintf('Q-%s%04d', $yymm, $next); // Q-25090001
}

function norm_name(string $s): string {
  return preg_replace('/\s+/u', ' ', trim($s));
}

// Generate next code for CUSTOM tests inside invoice table (C001, C002...)
function next_custom_test_code(mysqli $conn): string {
  $res = $conn->query("
    SELECT MAX(CAST(SUBSTRING(test_code, 2) AS UNSIGNED)) AS mx
    FROM test_prices
    WHERE test_code LIKE 'C%'
  ");
  $mx = 0;
  if ($res && ($row = $res->fetch_assoc()) && $row['mx'] !== null) {
    $mx = (int)$row['mx'];
  }
  $next = $mx + 1;
  return 'C' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

/* ---------- HANDLE POST FIRST (no output before redirects) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_invoice'])) {
  $clinic_id = (int)($_POST['clinic_id'] ?? 0);
  if ($clinic_id <= 0) {
    echo "<script>alert('Please select a clinic');history.back();</script>";
    exit;
  }

  $tests  = $_POST['tests']  ?? [];
  $custom = $_POST['custom'] ?? [];

  // Append custom tests (and generate code)
  if (!empty($custom['name']) && is_array($custom['name'])) {
    foreach ($custom['name'] as $i => $n) {
      $n = norm_name((string)$n);
      if ($n === '') continue;
      $p = (float)($custom['price'][$i] ?? 0);
      $d = (float)($custom['discount'][$i] ?? 0);

      $code = next_custom_test_code($conn);

      $tests[] = ['code'=>$code, 'name'=>$n, 'price'=>$p, 'discount'=>$d];
    }
  }

  if (empty($tests)) {
    echo "<script>alert('Select at least one test or add a custom test');history.back();</script>";
    exit;
  }

  // 1) Create invoice with a Q-YYMM#### number (retry on rare duplicate)
  $attempts = 0;
  $invoice_id = 0;
  $inv_no = '';

  do {
    $attempts++;
    $inv_no = generate_q_invoice_no($conn);

    $stmt = $conn->prepare("INSERT INTO invoices (invoice_number, clinic_id, created_at) VALUES (?, ?, NOW())");
    $stmt->bind_param("si", $inv_no, $clinic_id);

    try {
      $stmt->execute();
      $invoice_id = $stmt->insert_id;
    } catch (mysqli_sql_exception $e) {
      // Duplicate race -> retry
      if (strpos($e->getMessage(), 'Duplicate') === false || $attempts >= 5) {
        throw $e;
      }
    } finally {
      $stmt->close();
    }
  } while ($invoice_id === 0 && $attempts < 5);

  // 2) Items — dedupe by test_code (if available) OR normalized name
  //    FIX: previously keyed only by name which collapsed tests with duplicate names
  $conn->begin_transaction();
  try {
    $clean = [];

    foreach ($tests as $row) {
      $name = norm_name((string)($row['name'] ?? ''));
      if ($name === '') continue;

      $code  = trim((string)($row['code'] ?? ''));
      $price = (float)($row['price'] ?? 0);
      $disc  = (float)($row['discount'] ?? 0);

      // Use code as dedup key when available, fall back to lowercased name
      // This ensures tests with same name but different codes are all kept
      $key = ($code !== '') ? ('code:' . strtoupper($code)) : ('name:' . mb_strtolower($name, 'UTF-8'));

      // keep latest if truly duplicated
      $clean[$key] = ['code'=>$code, 'name'=>$name, 'price'=>$price, 'discount'=>$disc];
    }

    if (empty($clean)) {
      throw new mysqli_sql_exception("No valid tests found.");
    }

    $sql = "
      INSERT INTO test_prices (clinic_id, invoice_id, test_code, test_name, price, discount_percent, created_at)
      VALUES (?, ?, ?, ?, ?, ?, NOW())
    ";
    $ins = $conn->prepare($sql);

    foreach ($clean as $row) {
      $code = ($row['code'] !== '') ? $row['code'] : null; // allow NULL if missing
      $ins->bind_param("iissdd", $clinic_id, $invoice_id, $code, $row['name'], $row['price'], $row['discount']);
      $ins->execute();
    }

    $ins->close();
    $conn->commit();

  } catch (mysqli_sql_exception $e) {
    $conn->rollback();
    echo "<script>alert('Could not save items: ".htmlspecialchars($e->getMessage(), ENT_QUOTES)."');history.back();</script>";
    exit;
  }

  header("Location: invoice_list.php?created=" . rawurlencode($inv_no));
  exit;
}

/* ---------- Only GET below this line prints HTML ---------- */
include 'includes/header.php';

/* Data for page */
$pre_clinic_id = isset($_GET['clinic_id']) ? (int)$_GET['clinic_id'] : 0;

$clinics = [];
$rs = $conn->query("SELECT id, name FROM clinics ORDER BY name ASC");
while ($c = $rs->fetch_assoc()) $clinics[] = $c;

$prices = [];
$rs2 = $conn->query("
  SELECT id, test_code, test_name, specimen, method, tat_day, price
  FROM imported_prices
  ORDER BY test_name ASC
");
while ($p = $rs2->fetch_assoc()) $prices[] = $p;
?>

<!-- DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" />
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<style>
  #pickTable { width:100% !important; }
  .dataTables_wrapper .dataTables_filter { float:right; text-align:right; }
  .dataTables_wrapper .dataTables_length { float:left; }

  /* DataTables scroll body scrollbar */
  .dataTables_scrollBody {
    scrollbar-width: thin;
    scrollbar-color: #94a3b8 #f1f5f9;
  }
  .dataTables_scrollBody::-webkit-scrollbar { width: 7px; height: 7px; }
  .dataTables_scrollBody::-webkit-scrollbar-track { background: #f1f5f9; }
  .dataTables_scrollBody::-webkit-scrollbar-thumb { background: #94a3b8; border-radius: 4px; }
  .dataTables_scrollBody::-webkit-scrollbar-thumb:hover { background: #64748b; }

  /* Keep frozen header aligned with body columns */
  .dataTables_scrollHead { overflow: hidden !important; background: #f8f9fa; }
  .dataTables_scrollHeadInner, .dataTables_scrollHeadInner table { width: 100% !important; }

  /* Length + search controls above table */
  .dataTables_wrapper .dataTables_length { float: left; padding: 8px 4px; }
  .dataTables_wrapper .dataTables_filter { float: right; text-align: right; padding: 8px 4px; }
  .dataTables_wrapper .dataTables_length select {
    border-radius: 8px; border: 1.5px solid rgba(15,23,42,.12);
    padding: 4px 8px; font-size: 13px; background: #f9fafb;
  }
  .dataTables_wrapper .dataTables_filter input {
    border-radius: 8px; border: 1.5px solid rgba(15,23,42,.12);
    padding: 5px 10px; font-size: 13px; background: #f9fafb;
    margin-left: 6px;
  }
  .dataTables_wrapper .dataTables_filter input:focus {
    outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.12);
  }
  /* Frozen header row styles */
  .dataTables_scrollHead thead th {
    background: #f8f9fa !important;
    border-bottom: 2px solid #dee2e6 !important;
    font-weight: 700;
    white-space: nowrap;
  }

  /* Select-all loading overlay */
  .pick-loading-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15,23,42,.45);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    gap: 16px;
  }
  .pick-loading-overlay.active { display: flex; }
  .pick-loading-box {
    background: #fff;
    border-radius: 16px;
    padding: 28px 40px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 14px;
    box-shadow: 0 20px 60px rgba(15,23,42,.2);
    min-width: 220px;
  }
  .pick-loading-spinner {
    width: 44px; height: 44px;
    border: 4px solid #e2e8f0;
    border-top-color: #2563eb;
    border-radius: 50%;
    animation: pickSpin .7s linear infinite;
  }
  @keyframes pickSpin { to { transform: rotate(360deg); } }
  .pick-loading-text { font-size: 14px; font-weight: 600; color: #374151; }
  .pick-loading-count { font-size: 13px; color: #6b7280; }
</style>

<div class="container mt-4">
  <h4 class="mb-3">Create Invoice from Price List</h4>

  <form method="post" id="invoiceForm">
    <div class="card shadow-sm border-0 mb-3">
      <div class="card-body row g-3 align-items-end">
        <div class="col-md-6">
          <label class="form-label">Clinic</label>
          <select name="clinic_id" class="form-select" required>
            <option value="">-- Select clinic --</option>
            <?php foreach ($clinics as $c): $cid=(int)$c['id']; ?>
              <option value="<?= $cid ?>" <?= ($pre_clinic_id === $cid ? 'selected' : '') ?>>
                <?= htmlspecialchars($c['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6 text-md-end">
          <button type="button" class="btn btn-outline-secondary" id="btnAddCustom">+ Add Custom Test</button>
        </div>
      </div>
    </div>

    <div class="card shadow-sm border-0">
      <div class="card-body">

        <!-- Selected tests summary -->
        <div id="selectedPanel" class="card shadow-sm border-0 mb-3" style="display:none">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <h6 class="m-0">
                Selected tests
                <span class="badge bg-primary" id="selCount">0</span>
              </h6>
              <div>
                <strong>Total:</strong> <span id="selTotal">0.00</span> ฿
                <button type="button" class="btn btn-sm btn-outline-secondary ms-3" id="btnExpandSel">Show</button>
                <button type="button" class="btn btn-sm btn-outline-danger ms-2" id="btnClearSel">Clear</button>
              </div>
            </div>

            <div id="selListWrap" style="display:none;max-height:220px;overflow:auto">
              <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th style="width:40px">#</th>
                    <th style="width:90px">Code</th>
                    <th>Test name</th>
                    <th style="width:120px" class="text-end">Price</th>
                    <th style="width:120px" class="text-end">Discount %</th>
                    <th style="width:60px"></th>
                  </tr>
                </thead>
                <tbody id="selList"></tbody>
              </table>
            </div>
          </div>
        </div>

        <h6 class="mb-2">Select tests (you can edit price/discount before creating invoice)</h6>

        <div>
          <table id="pickTable" class="table table-bordered align-middle">
            <thead class="table-light">
              <tr>
                <th style="width:40px"><input type="checkbox" id="checkAll"></th>
                <th style="width:90px">Code</th>
                <th>Test Name</th>
                <th>Specimen</th>
                <th>Method</th>
                <th>TAT</th>
                <th style="width:140px">Price (Baht)</th>
                <th style="width:140px">Discount %</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($prices as $p): ?>
                <tr data-id="<?= (int)$p['id'] ?>">
                  <td><input type="checkbox" class="pick" /></td>
                  <td class="tcode"><?= htmlspecialchars($p['test_code']) ?></td>
                  <td class="tname"><?= htmlspecialchars($p['test_name']) ?></td>
                  <td><?= htmlspecialchars($p['specimen']) ?></td>
                  <td><?= htmlspecialchars($p['method']) ?></td>
                  <td><?= htmlspecialchars($p['tat_day']) ?></td>
                  <td><input type="number" step="0.01" class="form-control form-control-sm tprice" value="<?= number_format((float)$p['price'],2,'.','') ?>"></td>
                  <td><input type="number" step="0.01" class="form-control form-control-sm tdisc" value="0"></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Custom tests -->
        <div id="customWrap" class="mt-4" style="display:none">
          <h6 class="mb-2">Custom Tests</h6>
          <div class="table-responsive">
            <table class="table table-bordered align-middle" id="customTable">
              <thead class="table-light">
                <tr>
                  <th style="width:40px">#</th>
                  <th>Test Name</th>
                  <th style="width:140px">Price (Baht)</th>
                  <th style="width:140px">Discount %</th>
                  <th style="width:60px">—</th>
                </tr>
              </thead>
              <tbody></tbody>
            </table>
          </div>
          <div class="form-text">
            Custom test codes will be generated automatically when you create invoice.
          </div>
        </div>

        <div class="text-end mt-3">
          <input type="hidden" name="create_invoice" value="1">
          <button type="button" id="btnCreate" class="btn btn-primary">Create Invoice</button>
        </div>
      </div>
    </div>
  </form>
</div>

<script>
$(function () {

  // ---------- Storage helpers (per clinic) ----------
  function clinicId() {
    const v = document.querySelector('select[name="clinic_id"]')?.value || '';
    return v || '0';
  }
  function storageKey() { return `createInvoiceSelections:clinic:${clinicId()}`; }

  // ---------- DataTable + picked map ----------
  const picked = new Map(); // key: rowId -> { id, code, name, price, discount }

  const dt = $('#pickTable').DataTable({
    pageLength: -1,          // show all so user can see everything
    lengthMenu: [[25,50,100,-1],[25,50,100,'All']],
    order: [[2,'asc']],      // Test Name index is 2
    columnDefs: [{ orderable:false, targets:[0,6,7] }],
    scrollY: '520px',
    scrollCollapse: true,
    drawCallback: function () {
      // Restore checked state + values after redraw based on the picked map
      $('#pickTable tbody tr').each(function () {
        const $tr = $(this);
        const id  = String($tr.data('id'));
        const rec = picked.get(id);

        if (rec) {
          $tr.find('.pick').prop('checked', true);
          $tr.find('.tprice').val(rec.price);
          $tr.find('.tdisc').val(rec.discount);
        } else {
          $tr.find('.pick').prop('checked', false);
        }
      });

      // Keep the header "select all" in sync for current page
      const totalOnPage = $('#pickTable tbody .pick').length;
      const checkedOnPage = $('#pickTable tbody .pick:checked').length;
      $('#checkAll').prop('checked', totalOnPage > 0 && totalOnPage === checkedOnPage);
    }
  });

  function readRow($tr) {
    const id   = String($tr.data('id'));
    const code = ($tr.find('.tcode').text() || '').trim();
    const name = $tr.find('.tname').text().trim().replace(/\s+/g,' ');
    const price = parseFloat($tr.find('.tprice').val() || 0);
    const discount = parseFloat($tr.find('.tdisc').val() || 0);
    return { id, code, name, price, discount };
  }

  // ---------- Selected panel render ----------
  function renderPanel() {
    const $panel = $('#selectedPanel');
    const $list  = $('#selList');
    const arr = Array.from(picked.values());

    if (!arr.length) {
      $panel.hide();
      $('#selCount').text('0');
      $('#selTotal').text('0.00');
      $list.empty();
      return;
    }

    // totals (price minus % discount)
    let total = 0;
    arr.forEach(r => {
      const disc = isFinite(r.discount) ? r.discount : 0;
      const price = isFinite(r.price) ? r.price : 0;
      total += price * (1 - disc/100);
    });

    $('#selCount').text(arr.length);
    $('#selTotal').text(total.toFixed(2));
    $list.empty();

    arr.forEach((r, idx) => {
      $list.append(`
        <tr data-id="${r.id}">
          <td>${idx+1}</td>
          <td>${$('<div>').text(r.code || '').html()}</td>
          <td>${$('<div>').text(r.name).html()}</td>
          <td class="text-end">${Number(r.price || 0).toFixed(2)}</td>
          <td class="text-end">${Number(r.discount || 0).toFixed(2)}</td>
          <td class="text-end">
            <button type="button" class="btn btn-sm btn-outline-danger selRemove">&times;</button>
          </td>
        </tr>
      `);
    });

    $panel.show();
  }

  // Expand/Collapse list
  $('#btnExpandSel').on('click', function(){
    const wrap = $('#selListWrap');
    const now = wrap.is(':visible');
    wrap.toggle(!now);
    $(this).text(now ? 'Show' : 'Hide');
  });

  // Remove from summary -> uncheck in table too
  $(document).on('click', '.selRemove', function(){
    const id = String($(this).closest('tr').data('id'));
    picked.delete(id);

    $('#pickTable tbody tr').each(function(){
      const $tr = $(this);
      if (String($tr.data('id')) === id) {
        $tr.find('.pick').prop('checked', false);
      }
    });

    saveState();
    renderPanel();
  });

  // Clear all
  $('#btnClearSel').on('click', function(){
    if (!confirm('Clear all selected tests?')) return;
    picked.clear();
    $('#pickTable .pick').prop('checked', false);
    saveState();
    renderPanel();
  });

  // ---------- Persist (localStorage) ----------
  function saveState() {
    const state = {
      picked: Array.from(picked.values()),
      custom: $('#customTable tbody tr').map(function () {
        const $tr = $(this);
        return {
          name: $tr.find('input[name="custom[name][]"]').val() || '',
          price: parseFloat($tr.find('input[name="custom[price][]"]').val() || 0),
          discount: parseFloat($tr.find('input[name="custom[discount][]"]').val() || 0),
        };
      }).get(),
      table: {
        page: dt.page(),
        search: dt.search()
      }
    };
    try { localStorage.setItem(storageKey(), JSON.stringify(state)); } catch (e) {}
  }

  function loadState() {
    let raw = null;
    try { raw = localStorage.getItem(storageKey()); } catch (e) {}
    if (!raw) { renderPanel(); return; }

    let state = null;
    try { state = JSON.parse(raw); } catch (e) { renderPanel(); return; }
    if (!state || !Array.isArray(state.picked)) { renderPanel(); return; }

    picked.clear();
    for (const rec of state.picked) {
      if (!rec || !rec.id) continue;
      picked.set(String(rec.id), {
        id: String(rec.id),
        code: String(rec.code || ''),
        name: String(rec.name || '').trim().replace(/\s+/g,' '),
        price: parseFloat(rec.price || 0),
        discount: parseFloat(rec.discount || 0)
      });
    }

    // Restore custom tests
    if (Array.isArray(state.custom) && state.custom.length) {
      $('#customWrap').show();
      $('#customTable tbody').empty();
      state.custom.forEach((c, i) => {
        $('#customTable tbody').append(`
          <tr>
            <td>${i+1}</td>
            <td><input type="text" name="custom[name][]" class="form-control form-control-sm" value="${$('<div>').text(c.name || '').html()}"></td>
            <td><input type="number" step="0.01" name="custom[price][]" class="form-control form-control-sm" value="${Number(c.price || 0)}"></td>
            <td><input type="number" step="0.01" name="custom[discount][]" class="form-control form-control-sm" value="${Number(c.discount || 0)}"></td>
            <td><button type="button" class="btn btn-sm btn-outline-danger delRow">&times;</button></td>
          </tr>
        `);
      });
    }

    // Restore table UI (search + page)
    if (state.table) {
      if (typeof state.table.search === 'string') dt.search(state.table.search);
      dt.draw(false);
      if (Number.isInteger(state.table.page)) {
        dt.one('draw', function(){ dt.page(state.table.page).draw(false); });
      }
    } else {
      dt.draw(false);
    }

    renderPanel();
  }

  // ---------- Events that update state ----------
  $(document).on('change', '.pick', function () {
    const $tr = $(this).closest('tr');
    const rec = readRow($tr);

    if (this.checked) {
      picked.set(rec.id, rec);
    } else {
      picked.delete(rec.id);
    }

    saveState();
    renderPanel();
  });

  $(document).on('input', '.tprice, .tdisc', function () {
    const $tr = $(this).closest('tr');
    const id = String($tr.data('id'));
    if (!picked.has(id)) return;

    picked.set(id, readRow($tr));
    saveState();
    renderPanel();
  });

  // Select all with loading overlay
  $('#checkAll').on('change', function () {
    const checked = this.checked;
    const rows = $('#pickTable tbody tr');
    const total = rows.length;
    if (total < 10) {
      // small list — no overlay needed
      rows.each(function () { $(this).find('.pick').prop('checked', checked).trigger('change'); });
      return;
    }

    const overlay = document.getElementById('pickLoadingOverlay');
    const txtEl   = document.getElementById('pickLoadingText');
    const cntEl   = document.getElementById('pickLoadingCount');
    txtEl.textContent = checked ? 'Selecting all tests…' : 'Deselecting all tests…';
    cntEl.textContent = '';
    overlay.classList.add('active');

    // Process in chunks so the browser can paint the overlay first
    let i = 0;
    const CHUNK = 30;
    function processChunk() {
      const end = Math.min(i + CHUNK, total);
      rows.slice(i, end).each(function () {
        $(this).find('.pick').prop('checked', checked).trigger('change');
      });
      cntEl.textContent = end + ' / ' + total;
      i = end;
      if (i < total) {
        setTimeout(processChunk, 0);
      } else {
        overlay.classList.remove('active');
      }
    }
    // Let browser render overlay before heavy work
    setTimeout(processChunk, 50);
  });

  dt.on('search.dt page.dt order.dt length.dt', function(){ saveState(); });

  // Custom tests add/remove
  $('#btnAddCustom').on('click', function(){
    $('#customWrap').show();
    addCustomRow();
    saveState();
  });

  function addCustomRow(){
    const idx = $('#customTable tbody tr').length + 1;
    $('#customTable tbody').append(`
      <tr>
        <td>${idx}</td>
        <td><input type="text" name="custom[name][]" class="form-control form-control-sm" placeholder="Test name" required></td>
        <td><input type="number" step="0.01" name="custom[price][]" class="form-control form-control-sm" value="0" required></td>
        <td><input type="number" step="0.01" name="custom[discount][]" class="form-control form-control-sm" value="0" required></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger delRow">&times;</button></td>
      </tr>
    `);
  }

  $(document).on('click', '.delRow', function(){
    $(this).closest('tr').remove();
    $('#customTable tbody tr').each(function(i){ $(this).find('td:first').text(i+1); });
    if (!$('#customTable tbody tr').length) $('#customWrap').hide();
    saveState();
  });

  $(document).on('input', '#customTable input', function(){ saveState(); });

  // Clinic change -> clear selection for new clinic namespace
  $('select[name="clinic_id"]').on('change', function(){
    try { localStorage.removeItem(storageKey()); } catch (e) {}
    picked.clear();
    $('#pickTable .pick').prop('checked', false);
    $('#customTable tbody').empty();
    $('#customWrap').hide();
    renderPanel();
  });

  // Submit: build hidden inputs + clear state
  $('#btnCreate').on('click', function () {
    const $form = $('#invoiceForm');
    $form.find('.dyn').remove();

    let count = 0;
    for (const rec of picked.values()) {
      $form.append(`
        <input class="dyn" type="hidden" name="tests[${count}][code]" value="${$('<div>').text(rec.code || '').html()}">
        <input class="dyn" type="hidden" name="tests[${count}][name]" value="${$('<div>').text(rec.name).html()}">
        <input class="dyn" type="hidden" name="tests[${count}][price]" value="${rec.price}">
        <input class="dyn" type="hidden" name="tests[${count}][discount]" value="${rec.discount}">
      `);
      count++;
    }

    if (count === 0 && !$('#customTable tbody tr').length) {
      alert('Please select at least one test or add a custom test.');
      return;
    }

    try { localStorage.removeItem(storageKey()); } catch (e) {}
    $form.trigger('submit');
  });

  // Preselect clinic from URL if provided
  const p = new URLSearchParams(location.search).get('clinic_id');
  const sel = document.querySelector('select[name="clinic_id"]');
  if (p && sel && !sel.value) sel.value = p;

  // Initial restore
  loadState();

});
</script>

<!-- Select-all loading overlay -->
<div class="pick-loading-overlay" id="pickLoadingOverlay">
  <div class="pick-loading-box">
    <div class="pick-loading-spinner"></div>
    <div class="pick-loading-text" id="pickLoadingText">Selecting all tests…</div>
    <div class="pick-loading-count" id="pickLoadingCount"></div>
  </div>
</div>

<?php include 'includes/footer.php'; ?>