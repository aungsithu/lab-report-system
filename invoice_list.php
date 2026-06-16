<?php
// invoice_list.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'includes/db.php';
require 'includes/session_check.php';

$clinic_id  = isset($_GET['clinic_id'])  ? trim($_GET['clinic_id'])  : '';
$start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$end_date   = isset($_GET['end_date'])   ? trim($_GET['end_date'])   : '';

/* ---------------- Pagination ---------------- */
$per_page = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $per_page;

/* ---------------- Delete ---------------- */
if (isset($_GET['delete_id'])) {
    $delete_id = (int) $_GET['delete_id'];
    if ($delete_id > 0) {
        $stmt = $conn->prepare("DELETE FROM test_prices WHERE invoice_id = ?");
        $stmt->bind_param("i", $delete_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM invoices WHERE id = ?");
        $stmt->bind_param("i", $delete_id);
        $stmt->execute();
        $stmt->close();
    }

    $qs = $_GET;
    unset($qs['delete_id']);
    $redirect = 'invoice_list.php' . (!empty($qs) ? ('?' . http_build_query($qs)) : '');
    header("Location: " . $redirect);
    exit;
}

/* ---------------- Fetch clinic options ---------------- */
$clinic_options = $conn->query("SELECT id, name FROM clinics ORDER BY name ASC");

/* ---------------- Build filter query ---------------- */
$filter_sql = "WHERE 1 ";
$params = [];
$types  = '';

if ($clinic_id !== '') {
    $filter_sql .= "AND i.clinic_id = ? ";
    $params[] = (int)$clinic_id;
    $types   .= 'i';
}
if ($start_date !== '' && $end_date !== '') {
    $filter_sql .= "AND DATE(i.created_at) BETWEEN ? AND ? ";
    $params[] = $start_date;
    $params[] = $end_date;
    $types   .= 'ss';
} elseif ($start_date !== '') {
    $filter_sql .= "AND DATE(i.created_at) >= ? ";
    $params[] = $start_date;
    $types   .= 's';
} elseif ($end_date !== '') {
    $filter_sql .= "AND DATE(i.created_at) <= ? ";
    $params[] = $end_date;
    $types   .= 's';
}

/* ---------------- Count total rows ---------------- */
$count_sql = "
  SELECT COUNT(*) AS total
  FROM invoices i
  JOIN clinics  c ON c.id = i.clinic_id
  $filter_sql
";
$count_stmt = $conn->prepare($count_sql);
if ($types !== '') {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$count_res = $count_stmt->get_result();
$total_rows = (int)($count_res->fetch_assoc()['total'] ?? 0);
$count_stmt->close();

$total_pages = ($total_rows > 0) ? (int)ceil($total_rows / $per_page) : 1;
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $per_page;

/* ---------------- Fetch invoices ---------------- */
$sql = "
  SELECT
    i.id            AS invoice_id,
    i.invoice_number,
    i.clinic_id,
    c.name          AS clinic_name,
    i.created_at
  FROM invoices i
  JOIN clinics  c ON c.id = i.clinic_id
  $filter_sql
  ORDER BY i.created_at DESC, i.id DESC
  LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);
$params2 = $params;
$types2  = $types . 'ii';
$params2[] = $per_page;
$params2[] = $offset;
$stmt->bind_param($types2, ...$params2);
$stmt->execute();
$results = $stmt->get_result();

include 'includes/header.php';

function build_page_link($new_page) {
    $qs = $_GET;
    $qs['page'] = $new_page;
    return 'invoice_list.php?' . http_build_query($qs);
}
?>

<style>
/* ── Tokens ── */
.il-page {
  --il-blue:   #2563eb;
  --il-green:  #16a34a;
  --il-red:    #dc2626;
  --il-border: rgba(15,23,42,.08);
  --il-shadow: 0 4px 24px rgba(15,23,42,.07);
  --il-r:      14px;
}

/* ── Hero ── */
.il-hero {
  background: linear-gradient(120deg, #0f2444 0%, #1d4ed8 55%, #3b82f6 100%);
  border-radius: var(--il-r);
  padding: 22px 28px;
  color: #fff;
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; flex-wrap: wrap; overflow: hidden;
  position: relative; margin-bottom: 20px;
}
.il-hero::before {
  content:''; position:absolute; right:-40px; top:-40px;
  width:200px; height:200px; border-radius:50%;
  background:rgba(255,255,255,.05);
}
.il-hero::after {
  content:''; position:absolute; right:80px; bottom:-60px;
  width:150px; height:150px; border-radius:50%;
  background:rgba(255,255,255,.04);
}
.il-hero h4 { font-size:20px; font-weight:750; margin:0 0 3px; letter-spacing:-.2px; position:relative;z-index:1; }
.il-hero p  { margin:0; font-size:13px; opacity:.78; position:relative;z-index:1; }

.btn-reset {
  background: rgba(255,255,255,.15);
  border: 1px solid rgba(255,255,255,.25);
  color: #fff; border-radius: 10px;
  padding: 8px 18px; font-size: 13.5px; font-weight: 600;
  display: inline-flex; align-items: center; gap: 7px;
  text-decoration: none; transition: background .15s;
  position: relative; z-index: 1; white-space: nowrap;
}
.btn-reset:hover { background: rgba(255,255,255,.25); color: #fff; }

/* ── Filter card ── */
.il-filter-card {
  background: #fff;
  border: 1px solid var(--il-border);
  border-radius: var(--il-r);
  box-shadow: var(--il-shadow);
  overflow: hidden;
  margin-bottom: 18px;
}
.il-filter-head {
  background: #f8fafc;
  border-bottom: 1px solid var(--il-border);
  padding: 12px 20px;
  font-size: 13.5px; font-weight: 700; color: #111827;
  display: flex; align-items: center; gap: 8px;
}
.il-filter-body { padding: 18px 20px; }

/* ── Field ── */
.il-field { display: flex; flex-direction: column; gap: 5px; }
.il-field label {
  font-size: 11px; font-weight: 700;
  text-transform: uppercase; letter-spacing: .06em; color: #6b7280; margin: 0;
}
.il-field .form-control,
.il-field .form-select {
  border-radius: 10px; border: 1.5px solid rgba(15,23,42,.11);
  font-size: 14px; padding: 8px 12px; background: #f9fafb; color: #111827;
  transition: border-color .15s, box-shadow .15s;
}
.il-field .form-control:focus,
.il-field .form-select:focus {
  border-color: #2563eb;
  box-shadow: 0 0 0 3px rgba(37,99,235,.13);
  background: #fff;
}

.btn-filter {
  background: linear-gradient(135deg, #2563eb, #4f46e5);
  color: #fff; border: none; border-radius: 10px;
  padding: 9px 22px; font-size: 14px; font-weight: 600;
  display: inline-flex; align-items: center; gap: 7px;
  transition: opacity .15s; cursor: pointer; width: 100%;
  justify-content: center;
}
.btn-filter:hover { opacity: .88; }

/* ── Results bar ── */
.il-results-bar {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 10px; flex-wrap: wrap; gap: 8px;
}
.il-results-bar .label {
  font-size: 14.5px; font-weight: 800; color: #111827;
  display: flex; align-items: center; gap: 8px;
}
.il-count-badge {
  background: #eff6ff; color: #1d4ed8;
  border: 1px solid #bfdbfe; border-radius: 999px;
  padding: 2px 10px; font-size: 12px; font-weight: 700;
}
.il-range-text { font-size: 13px; color: #6b7280; }

/* ── Table card ── */
.il-table-card {
  background: #fff;
  border: 1px solid var(--il-border);
  border-radius: var(--il-r);
  box-shadow: var(--il-shadow);
  overflow: hidden;
  margin-bottom: 16px;
}

/* ── Table ── */
#invoiceTable thead th {
  background: #f8fafc;
  font-size: 11px; font-weight: 800;
  text-transform: uppercase; letter-spacing: .07em; color: #64748b;
  padding: 13px 16px !important;
  border-bottom: 2px solid rgba(15,23,42,.07);
  white-space: nowrap; position: sticky; top: 0; z-index: 5;
}
#invoiceTable tbody td {
  padding: 13px 16px !important;
  font-size: 14px; color: #111827;
  vertical-align: middle;
  border-color: rgba(15,23,42,.05);
}
#invoiceTable tbody tr { transition: background .1s; }
#invoiceTable tbody tr:hover { background: #f0f7ff; }

/* ── Row number ── */
.row-num { font-size: 13px; font-weight: 700; color: #374151; font-variant-numeric: tabular-nums; }

/* ── Invoice number badge ── */
.inv-number {
  display: inline-flex; align-items: center; gap: 6px;
  background: #eff6ff; color: #1d4ed8;
  border: 1px solid #bfdbfe; border-radius: 8px;
  padding: 4px 12px; font-size: 13px; font-weight: 700;
  font-family: monospace; letter-spacing: .03em;
}

/* ── Clinic tag ── */
.inv-clinic {
  display: inline-flex; align-items: center; gap: 6px;
  background: #f0f9ff; color: #0369a1;
  border: 1px solid #bae6fd; border-radius: 7px;
  padding: 4px 11px; font-size: 13.5px; font-weight: 600;
}

/* ── Date cell ── */
.inv-date { font-size: 13.5px; color: #374151; font-weight: 500; white-space: nowrap; }
.inv-date-icon { opacity: .45; font-size: 12px; }

/* ── Action buttons ── */
.btn-view-prices {
  background: #eff6ff; color: #1d4ed8;
  border: 1px solid #bfdbfe; border-radius: 8px;
  padding: 6px 13px; font-size: 13px; font-weight: 600;
  display: inline-flex; align-items: center; gap: 5px;
  text-decoration: none; transition: background .12s; white-space: nowrap;
}
.btn-view-prices:hover { background: #dbeafe; color: #1e40af; }

.btn-del-invoice {
  background: #fef2f2; color: #dc2626;
  border: 1px solid #fecaca; border-radius: 8px;
  padding: 6px 13px; font-size: 13px; font-weight: 600;
  display: inline-flex; align-items: center; gap: 5px;
  text-decoration: none; transition: background .12s; white-space: nowrap;
}
.btn-del-invoice:hover { background: #fee2e2; color: #b91c1c; }

/* ── Empty state ── */
.il-empty { text-align: center; padding: 60px 20px; color: #9ca3af; }
.il-empty i { font-size: 40px; display: block; margin-bottom: 12px; opacity: .35; }
.il-empty p { margin: 0; font-size: 14px; }

/* ── Pagination ── */
.il-pagination .page-link {
  border-radius: 9px !important; margin: 0 2px;
  font-size: 13px; font-weight: 600;
  border-color: rgba(15,23,42,.10); color: #374151;
  padding: 7px 14px;
}
.il-pagination .page-item.active .page-link {
  background: #2563eb; border-color: #2563eb;
  box-shadow: 0 3px 10px rgba(37,99,235,.3);
}
.il-pagination .page-item.disabled .page-link { color: #cbd5e1; }

/* ── Active filter chips ── */
.il-active-filters {
  display: flex; align-items: center; gap: 7px; flex-wrap: wrap;
  margin-bottom: 10px;
}
.il-filter-chip {
  background: #eff6ff; color: #1d4ed8;
  border: 1px solid #bfdbfe; border-radius: 999px;
  padding: 3px 10px; font-size: 12px; font-weight: 600;
  display: inline-flex; align-items: center; gap: 5px;
}
</style>

<div class="il-page">

  <!-- Hero -->
  <div class="il-hero">
    <div>
      <h4><i class="bi bi-receipt-cutoff me-2" style="opacity:.85;"></i>Invoice List</h4>
      <p>Invoices generated from test prices — filter by clinic or date range</p>
    </div>
    <a href="invoice_list.php" class="btn-reset">
      <i class="bi bi-arrow-counterclockwise"></i> Reset Filters
    </a>
  </div>

  <!-- Active filter chips -->
  <?php if ($clinic_id !== '' || $start_date !== '' || $end_date !== ''): ?>
    <div class="il-active-filters">
      <span style="font-size:12px;font-weight:700;color:#6b7280;">Active filters:</span>
      <?php if ($clinic_id !== ''): ?>
        <span class="il-filter-chip"><i class="bi bi-building" style="font-size:11px;"></i> Clinic filtered</span>
      <?php endif; ?>
      <?php if ($start_date !== ''): ?>
        <span class="il-filter-chip"><i class="bi bi-calendar-event" style="font-size:11px;"></i> From: <?= htmlspecialchars($start_date) ?></span>
      <?php endif; ?>
      <?php if ($end_date !== ''): ?>
        <span class="il-filter-chip"><i class="bi bi-calendar-event" style="font-size:11px;"></i> To: <?= htmlspecialchars($end_date) ?></span>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- Filter card -->
  <div class="il-filter-card">
    <div class="il-filter-head">
      <i class="bi bi-funnel-fill text-primary"></i> Search Filters
    </div>
    <div class="il-filter-body">
      <form method="GET" class="row g-3 align-items-end">
        <div class="col-md-4">
          <div class="il-field">
            <label>Clinic</label>
            <select name="clinic_id" class="form-select">
              <option value="">— All Clinics —</option>
              <?php while ($c = $clinic_options->fetch_assoc()): ?>
                <option value="<?= (int)$c['id'] ?>" <?= ($clinic_id == $c['id'] ? 'selected' : '') ?>>
                  <?= htmlspecialchars($c['name'] ?? '') ?>
                </option>
              <?php endwhile; ?>
            </select>
          </div>
        </div>

        <div class="col-md-3">
          <div class="il-field">
            <label>From Date</label>
            <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" class="form-control">
          </div>
        </div>

        <div class="col-md-3">
          <div class="il-field">
            <label>To Date</label>
            <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" class="form-control">
          </div>
        </div>

        <div class="col-md-2">
          <input type="hidden" name="page" value="1">
          <button type="submit" class="btn-filter">
            <i class="bi bi-search"></i> Filter
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Results bar -->
  <div class="il-results-bar">
    <div class="label">
      <i class="bi bi-receipt text-primary"></i>
      Invoices
      <span class="il-count-badge"><?= number_format($total_rows) ?></span>
    </div>
    <?php if ($total_rows > 0): ?>
      <div class="il-range-text">
        Showing <strong><?= min($offset + 1, $total_rows) ?></strong>–<strong><?= min($offset + $per_page, $total_rows) ?></strong>
        of <strong><?= number_format($total_rows) ?></strong>
      </div>
    <?php endif; ?>
  </div>

  <!-- Table -->
  <div class="il-table-card">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="invoiceTable">
        <thead>
          <tr>
            <th style="width:56px;" class="text-center">#</th>
            <th>Invoice No.</th>
            <th>Clinic</th>
            <th>Created At</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($results->num_rows > 0): ?>
            <?php
              $i = $offset + 1;
              while ($row = $results->fetch_assoc()):
            ?>
              <tr>
                <td class="text-center"><span class="row-num"><?= $i++ ?></span></td>

                <td>
                  <span class="inv-number">
                    <i class="bi bi-hash" style="font-size:12px;"></i>
                    <?= htmlspecialchars($row['invoice_number'] ?? '') ?>
                  </span>
                </td>

                <td>
                  <span class="inv-clinic">
                    <i class="bi bi-building" style="font-size:12px;"></i>
                    <?= htmlspecialchars($row['clinic_name'] ?? '') ?>
                  </span>
                </td>

                <td>
                  <?php
                    if (!empty($row['created_at'])) {
                        $utc = new DateTime($row['created_at'], new DateTimeZone('UTC'));
                        $utc->setTimezone(new DateTimeZone('Asia/Bangkok'));
                        $formatted = $utc->format('Y-m-d H:i:s');
                    } else {
                        $formatted = '—';
                    }
                  ?>
                  <span class="inv-date">
                    <i class="bi bi-clock inv-date-icon"></i>
                    <?= htmlspecialchars($formatted) ?>
                  </span>
                </td>

                <td>
                  <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <a class="btn-view-prices"
                       href="test_prices_by_clinic.php?clinic_id=<?= (int)$row['clinic_id'] ?>&invoice_id=<?= (int)$row['invoice_id'] ?>">
                      <i class="bi bi-eye"></i> View Prices
                    </a>

                    <?php
                      $qs = $_GET;
                      $qs['delete_id'] = (int)$row['invoice_id'];
                      $delete_url = 'invoice_list.php?' . http_build_query($qs);
                    ?>
                    <a class="btn-del-invoice"
                       href="<?= htmlspecialchars($delete_url) ?>"
                       onclick="return confirm('Delete this invoice and all its test items?');">
                      <i class="bi bi-trash3"></i> Delete
                    </a>
                  </div>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr>
              <td colspan="5">
                <div class="il-empty">
                  <i class="bi bi-receipt-x"></i>
                  <p>No invoices found<?= ($clinic_id !== '' || $start_date !== '' || $end_date !== '') ? ' matching your filters' : '' ?>.</p>
                </div>
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Pagination -->
  <?php if ($total_rows > 0 && $total_pages > 1): ?>
    <nav>
      <ul class="pagination justify-content-center il-pagination">

        <li class="page-item <?= ($page <= 1 ? 'disabled' : '') ?>">
          <a class="page-link" href="<?= ($page <= 1 ? '#' : htmlspecialchars(build_page_link($page - 1))) ?>">
            <i class="bi bi-chevron-left" style="font-size:11px;"></i> Prev
          </a>
        </li>

        <?php
          $window = 2;
          $start = max(1, $page - $window);
          $end   = min($total_pages, $page + $window);

          if ($start > 1) {
              echo '<li class="page-item"><a class="page-link" href="'.htmlspecialchars(build_page_link(1)).'">1</a></li>';
              if ($start > 2) echo '<li class="page-item disabled"><span class="page-link" style="border:0;background:transparent;">…</span></li>';
          }

          for ($p = $start; $p <= $end; $p++) {
              $active = ($p == $page) ? 'active' : '';
              echo '<li class="page-item '.$active.'"><a class="page-link" href="'.htmlspecialchars(build_page_link($p)).'">'.$p.'</a></li>';
          }

          if ($end < $total_pages) {
              if ($end < $total_pages - 1) echo '<li class="page-item disabled"><span class="page-link" style="border:0;background:transparent;">…</span></li>';
              echo '<li class="page-item"><a class="page-link" href="'.htmlspecialchars(build_page_link($total_pages)).'">'.$total_pages.'</a></li>';
          }
        ?>

        <li class="page-item <?= ($page >= $total_pages ? 'disabled' : '') ?>">
          <a class="page-link" href="<?= ($page >= $total_pages ? '#' : htmlspecialchars(build_page_link($page + 1))) ?>">
            Next <i class="bi bi-chevron-right" style="font-size:11px;"></i>
          </a>
        </li>

      </ul>
    </nav>
  <?php endif; ?>

</div><!-- /.il-page -->

<?php include 'includes/footer.php'; ?>