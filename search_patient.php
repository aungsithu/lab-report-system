<?php
ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'includes/db.php';
include 'includes/header.php';
require 'includes/session_check.php';

// Role & clinic info
$role = $_SESSION['role'] ?? $_SESSION['user_role'] ?? '';
$clinic_id = $_SESSION['clinic_id'] ?? 0;
$is_clinic_user = $role === 'clinic_user';

// Filters
$name_filter = $_GET['name'] ?? '';
$clinic_filter = $_GET['clinic'] ?? '';
$from_date = $_GET['from'] ?? '';
$to_date = $_GET['to'] ?? '';
$status_filter = $_GET['status'] ?? '';

// SQL query
$query = "SELECT p.*, c.name AS clinic_name
          FROM patients p
          JOIN clinics c ON p.clinic_id = c.id
          WHERE 1";

// 🔐 Restrict to own clinic if clinic user
if ($is_clinic_user && $clinic_id > 0) {
    $query .= " AND p.clinic_id = " . intval($clinic_id);
} elseif (!empty($clinic_filter)) {
    $query .= " AND p.clinic_id = " . intval($clinic_filter);
}

// ✅ Delete patient (admin only)
if (isset($_GET['delete_patient']) && $_SESSION['user_role'] !== 'clinic_user') {
    $delete_id = (int)$_GET['delete_patient'];
    $conn->query("DELETE FROM lab_results WHERE patient_id = $delete_id");
    $conn->query("DELETE FROM patients WHERE id = $delete_id");
    header("Location: search_patient.php?deleted=1");
    exit;
}

// Name filter
if (!empty($name_filter)) {
    $safe_name = $conn->real_escape_string($name_filter);
    $tokens = preg_split('/[\s|\/]+/', $safe_name);
    foreach ($tokens as $token) {
        $token = trim($token);
        if ($token !== '') {
            $query .= " AND (
                p.name LIKE '%$token%' OR
                p.hn LIKE '%$token%' OR
                p.ln LIKE '%$token%'
            )";
        }
    }
}

if (!empty($status_filter)) {
    $query .= " AND p.status = '" . $conn->real_escape_string($status_filter) . "'";
}

// Date filters
if (!empty($from_date)) {
    $query .= " AND p.register_date >= '$from_date'";
}
if (!empty($to_date)) {
    $query .= " AND p.register_date <= '$to_date'";
}

// 🔁 Pagination Setup
$per_page = 20;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $per_page;

// Clone base filter conditions for count
$count_query = "SELECT COUNT(*) AS total FROM patients p
                JOIN clinics c ON p.clinic_id = c.id
                WHERE 1";

// Apply same filters
if ($is_clinic_user && $clinic_id > 0) {
    $count_query .= " AND p.clinic_id = " . intval($clinic_id);
} elseif (!empty($clinic_filter)) {
    $count_query .= " AND p.clinic_id = " . intval($clinic_filter);
}
if (!empty($name_filter)) {
    $safe_name = $conn->real_escape_string($name_filter);
    $tokens = preg_split('/[\s|\/]+/', $safe_name);
    foreach ($tokens as $token) {
        $token = trim($token);
        if ($token !== '') {
            $count_query .= " AND (
                p.name LIKE '%$token%' OR
                p.hn LIKE '%$token%' OR
                p.ln LIKE '%$token%'
            )";
        }
    }
}
if (!empty($from_date)) {
    $count_query .= " AND p.register_date >= '$from_date'";
}
if (!empty($to_date)) {
    $count_query .= " AND p.register_date <= '$to_date'";
}
if (!empty($status_filter)) {
    $count_query .= " AND p.status = '" . $conn->real_escape_string($status_filter) . "'";
}

$count_result = $conn->query($count_query);
$total_records = $count_result->fetch_assoc()['total'] ?? 0;

$total_pages   = max(1, (int)ceil($total_records / $per_page));
$current_page  = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$current_page  = max(1, min($current_page, $total_pages));
$offset        = ($current_page - 1) * $per_page;

// Apply limit to final query
$query .= " ORDER BY p.id DESC LIMIT $offset, $per_page";

$result = $conn->query($query);

// Get clinic list for admin dropdown
$clinics = $conn->query("SELECT id, name FROM clinics ORDER BY name");
?>

<style>
  /* ── Design tokens ── */
  .sp-page {
    --sp-blue:   #2563eb;
    --sp-green:  #16a34a;
    --sp-amber:  #d97706;
    --sp-red:    #dc2626;
    --sp-border: rgba(15,23,42,.08);
    --sp-shadow: 0 4px 24px rgba(15,23,42,.07);
    --sp-r:      14px;
  }

  /* ── Hero strip ── */
  .sp-hero {
    background: linear-gradient(120deg, #0f2444 0%, #1d4ed8 60%, #3b82f6 100%);
    border-radius: var(--sp-r);
    padding: 22px 28px;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    position: relative;
    overflow: hidden;
    margin-bottom: 20px;
  }
  .sp-hero::before {
    content:''; position:absolute;
    right:-50px; top:-50px;
    width:220px; height:220px; border-radius:50%;
    background:rgba(255,255,255,.05);
  }
  .sp-hero::after {
    content:''; position:absolute;
    right:80px; bottom:-70px;
    width:160px; height:160px; border-radius:50%;
    background:rgba(255,255,255,.04);
  }
  .sp-hero h4 {
    font-size: 20px; font-weight: 750;
    margin: 0 0 3px; letter-spacing: -.2px;
  }
  .sp-hero p { margin:0; font-size:13px; opacity:.75; }
  .sp-role-pill {
    background: rgba(255,255,255,.15);
    border: 1px solid rgba(255,255,255,.2);
    border-radius: 999px;
    padding: 5px 14px;
    font-size: 12.5px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
    backdrop-filter: blur(4px);
  }

  /* ── Filter card ── */
  .sp-filter-card {
    background: #fff;
    border: 1px solid var(--sp-border);
    border-radius: var(--sp-r);
    box-shadow: var(--sp-shadow);
    margin-bottom: 18px;
    overflow: clip; /* clip corners without breaking any child behaviour */
  }
  .sp-filter-header {
    background: #f8fafc;
    border-bottom: 1px solid var(--sp-border);
    padding: 13px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 13.5px;
    font-weight: 700;
    color: #111827;
  }
  .sp-filter-header .hint {
    font-size: 12px;
    font-weight: 400;
    color: #9ca3af;
  }
  .sp-filter-body { padding: 18px 20px; }

  /* ── Field labels ── */
  .sp-field { display:flex; flex-direction:column; gap:5px; }
  .sp-field label {
    font-size: 11px;
    font-weight: 700;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: .06em;
    margin: 0;
  }
  .sp-field .form-control,
  .sp-field .form-select {
    border-radius: 10px;
    border: 1.5px solid rgba(15,23,42,.11);
    font-size: 14px;
    padding: 8px 12px;
    background: #f9fafb;
    transition: border-color .15s, box-shadow .15s;
  }
  .sp-field .form-control:focus,
  .sp-field .form-select:focus {
    border-color: var(--sp-blue);
    box-shadow: 0 0 0 3px rgba(37,99,235,.13);
    background: #fff;
  }

  .btn-sp-search {
    background: linear-gradient(135deg, #2563eb, #4f46e5);
    color: #fff; border: none;
    border-radius: 10px;
    padding: 9px 20px;
    font-size: 14px; font-weight: 600;
    transition: opacity .15s, transform .1s;
    white-space: nowrap;
  }
  .btn-sp-search:hover { opacity:.88; transform:translateY(-1px); color:#fff; }

  .btn-sp-reset {
    background: #f1f5f9;
    color: #374151;
    border: 1.5px solid rgba(15,23,42,.10);
    border-radius: 10px;
    padding: 9px 18px;
    font-size: 14px; font-weight: 600;
    transition: background .12s;
    white-space: nowrap;
    text-decoration: none;
    display: inline-block;
  }
  .btn-sp-reset:hover { background: #e2e8f0; color: #111827; }

  /* ── Results bar ── */
  .sp-results-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 10px;
    flex-wrap: wrap;
  }
  .sp-results-bar .label {
    font-size: 14px;
    font-weight: 700;
    color: #111827;
    display: flex;
    align-items: center;
    gap: 7px;
  }
  .sp-results-bar .count-badge {
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
    border-radius: 999px;
    padding: 2px 10px;
    font-size: 12px;
    font-weight: 700;
  }
  .sp-results-bar .range-text {
    font-size: 13px;
    color: #6b7280;
  }

  /* ── Table wrapper ── */
  .sp-table-card {
    background: #fff;
    border: 1px solid var(--sp-border);
    border-radius: var(--sp-r);
    box-shadow: var(--sp-shadow);
    /* overflow:hidden removed — was killing inner table scroll */
  }

  /* ── Table ── */
  .sp-table-scroll {
    overflow-y: scroll; /* always show scrollbar */
    overflow-x: auto;
    max-height: 65vh;
    scrollbar-width: thin;
    scrollbar-color: rgba(15,23,42,.20) rgba(15,23,42,.05);
    border-radius: 0 0 var(--sp-r) var(--sp-r);
  }
  .sp-table-scroll::-webkit-scrollbar { width: 7px; height: 7px; }
  .sp-table-scroll::-webkit-scrollbar-track { background: rgba(15,23,42,.04); border-radius:999px; }
  .sp-table-scroll::-webkit-scrollbar-thumb { background: rgba(15,23,42,.18); border-radius:999px; }
  .sp-table-scroll::-webkit-scrollbar-thumb:hover { background: rgba(15,23,42,.32); }

  #countTable thead th {
    background: #f8fafc;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: #64748b;
    padding: 13px 16px !important;
    border-bottom: 2px solid rgba(15,23,42,.07);
    white-space: nowrap;
    position: sticky;
    top: 0;
    z-index: 5;
  }
  #countTable tbody td {
    padding: 13px 16px !important;
    font-size: 14px;
    vertical-align: middle;
    border-color: rgba(15,23,42,.05);
  }
  #countTable tbody tr {
    transition: background .1s;
    cursor: pointer;
  }
  #countTable tbody tr:hover {
    background: #f0f7ff;
  }

  /* ── Patient name cell ── */
  .pt-name {
    font-weight: 650;
    color: #111827;
    font-size: 14px;
  }
  .pt-id-codes {
    font-size: 12px;
    color: #6b7280;
    font-family: 'Courier New', monospace;
    margin-top: 2px;
  }

  /* ── Clinic pill ── */
  .clinic-tag {
    display: inline-block;
    background: #f0f9ff;
    color: #0369a1;
    border: 1px solid #bae6fd;
    border-radius: 6px;
    padding: 2px 9px;
    font-size: 12px;
    font-weight: 600;
  }

  /* ── Doctor cell ── */
  .doctor-name {
    font-size: 13px;
    color: #374151;
  }

  /* ── Date cell ── */
  .date-val {
    font-size: 13px;
    color: #374151;
    white-space: nowrap;
  }

  /* ── Status pills ── */
  .sp-status {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    border-radius: 999px;
    padding: 4px 11px;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
  }
  .sp-status-completed {
    background: #f0fdf4;
    color: #15803d;
    border: 1px solid #86efac;
  }
  .sp-status-processing {
    background: #fffbeb;
    color: #b45309;
    border: 1px solid #fcd34d;
  }
  .sp-status-dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    display: inline-block;
  }
  .sp-status-completed .sp-status-dot { background: #16a34a; }
  .sp-status-processing .sp-status-dot { background: #d97706; }

  /* ── Row number ── */
  .row-num {
    color: #374151;
    font-size: 13px;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
  }

  /* ── Delete button ── */
  .btn-del-row {
    width: 32px; height: 32px;
    border-radius: 8px;
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #dc2626;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: background .12s, transform .1s;
    text-decoration: none;
  }
  .btn-del-row:hover {
    background: #fee2e2;
    border-color: #fca5a5;
    color: #b91c1c;
    transform: translateY(-1px);
  }

  /* ── Empty state ── */
  .sp-empty {
    text-align: center;
    padding: 52px 20px;
    color: #9ca3af;
  }
  .sp-empty i { font-size: 40px; margin-bottom: 12px; display: block; opacity: .4; }
  .sp-empty p { margin: 0; font-size: 14px; }

  /* ── Alert ── */
  .sp-alert-success {
    background: #f0fdf4;
    border: 1px solid #86efac;
    color: #15803d;
    border-radius: 12px;
    padding: 12px 18px;
    font-size: 14px;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
  }

  /* ── Autocomplete ── */
  #autocomplete-results {
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 16px 40px rgba(15,23,42,.13);
    border: 1px solid rgba(15,23,42,.09);
    background: #fff;
  }
  #autocomplete-results .list-group-item {
    border: 0;
    padding: 10px 14px;
    font-size: 14px;
    transition: background .1s;
  }
  #autocomplete-results .list-group-item:hover {
    background: #f0f7ff;
    color: #1d4ed8;
  }

  /* ── Pagination ── */
  .sp-pagination .page-link {
    border-radius: 9px !important;
    margin: 0 2px;
    font-size: 13px;
    font-weight: 600;
    border-color: rgba(15,23,42,.10);
    color: #374151;
    padding: 6px 13px;
    transition: all .12s;
  }
  .sp-pagination .page-item.active .page-link {
    background: var(--sp-blue);
    border-color: var(--sp-blue);
    box-shadow: 0 3px 10px rgba(37,99,235,.3);
  }
  .sp-pagination .page-item.disabled .page-link {
    color: #cbd5e1;
  }

  /* ── Clear search X ── */
  #clearSearch {
    border-radius: 8px;
    font-size: 16px;
    line-height: 1;
    padding: 2px 8px;
    background: #f1f5f9;
    border: 1px solid rgba(15,23,42,.10);
    color: #6b7280;
    top: 36px !important;
  }
  #clearSearch:hover { background: #e2e8f0; color: #111827; }
</style>

<div class="sp-page">

  <!-- Hero -->
  <div class="sp-hero">
    <div style="position:relative;z-index:1;">
      <h4><i class="bi bi-person-lines-fill me-2" style="opacity:.85;"></i>Patient Search</h4>
      <p>Filter by name, clinic, date range, and status — click any row to view details</p>
    </div>
    <div class="sp-role-pill" style="position:relative;z-index:1;">
      <i class="bi bi-shield-check"></i>
      <?= htmlspecialchars($role ?: 'unknown') ?>
    </div>
  </div>

  <?php if (isset($_GET['deleted'])): ?>
    <div class="sp-alert-success">
      <i class="bi bi-check-circle-fill fs-5"></i>
      Patient and all lab results deleted successfully.
    </div>
  <?php endif; ?>

  <!-- Filter Card -->
  <div class="sp-filter-card">
    <div class="sp-filter-header">
      <span><i class="bi bi-funnel-fill me-2 text-primary"></i>Search Filters</span>
      <span class="hint"><i class="bi bi-info-circle me-1"></i>Use multiple filters together</span>
    </div>
    <div class="sp-filter-body">
      <form class="row g-3" method="GET">

        <div class="col-md-3 position-relative">
          <div class="sp-field">
            <label>Name / HN / LN</label>
            <input type="text" name="name" id="search_name" class="form-control pe-5"
                   placeholder="Search patient…" value="<?= htmlspecialchars($name_filter) ?>">
          </div>
          <button type="button" id="clearSearch"
                  class="btn btn-sm btn-light position-absolute d-none"
                  style="right:14px; z-index:10;">
            &times;
          </button>
          <div id="autocomplete-results" class="list-group position-absolute z-3 w-100" style="top:74px;"></div>
        </div>

        <?php if (!$is_clinic_user): ?>
        <div class="col-md-2">
          <div class="sp-field">
            <label>Clinic</label>
            <select name="clinic" class="form-control form-select">
              <option value="">All Clinics</option>
              <?php while ($row = $clinics->fetch_assoc()): ?>
                <option value="<?= $row['id'] ?>" <?= $clinic_filter == $row['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($row['name']) ?>
                </option>
              <?php endwhile; ?>
            </select>
          </div>
        </div>
        <?php endif; ?>

        <div class="col-md-2">
          <div class="sp-field">
            <label>From Date</label>
            <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($from_date) ?>">
          </div>
        </div>

        <div class="col-md-2">
          <div class="sp-field">
            <label>To Date</label>
            <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($to_date) ?>">
          </div>
        </div>

        <div class="col-md-2">
          <div class="sp-field">
            <label>Status</label>
            <select name="status" class="form-control form-select">
              <option value="">All Status</option>
              <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
              <option value="processing" <?= $status_filter === 'processing' ? 'selected' : '' ?>>Processing</option>
            </select>
          </div>
        </div>

        <div class="col-md-1 d-flex align-items-end gap-2">
          <button type="submit" class="btn-sp-search">
            <i class="bi bi-search me-1"></i> Search
          </button>
        </div>

        <div class="col-auto d-flex align-items-end">
          <a href="search_patient.php" class="btn-sp-reset">
            <i class="bi bi-arrow-counterclockwise me-1"></i> Reset
          </a>
        </div>

      </form>
    </div>
  </div>

  <!-- Results bar -->
  <?php
    $from_r = $total_records ? ($offset + 1) : 0;
    $to_r   = min($offset + $per_page, $total_records);
  ?>
  <div class="sp-results-bar">
    <div class="label">
      <i class="bi bi-table text-primary"></i>
      Results
      <span class="count-badge"><?= number_format($total_records) ?></span>
    </div>
    <div class="range-text">
      Showing <strong><?= $from_r ?></strong>–<strong><?= $to_r ?></strong> of <strong><?= number_format($total_records) ?></strong> patients
    </div>
  </div>

  <!-- Table -->
  <div class="sp-table-card">
    <div class="sp-table-scroll">
      <table class="table table-hover align-middle mb-0" id="countTable">
        <thead>
          <tr>
            <th style="width:48px;">#</th>
            <th>Patient</th>
            <?php if ($_SESSION['user_role'] !== 'clinic_user'): ?>
              <th>Clinic</th>
              <th>Doctor</th>
            <?php endif; ?>
            <th>Registered</th>
            <th>Status</th>
            <?php if ($_SESSION['user_role'] !== 'clinic_user'): ?>
              <th style="width:64px;" class="text-center">Del</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody id="patientTable">
          <?php
          $i = ($current_page - 1) * $per_page + 1;
          $has_rows = false;
          while ($row = $result->fetch_assoc()):
            $has_rows = true;
          ?>
          <tr class="clickable-row" data-href="view_patient.php?id=<?= $row['id'] ?>">

            <td><span class="row-num"><?= $i++ ?></span></td>

            <td>
              <div class="pt-name"><?= htmlspecialchars($row['name']) ?></div>
              <div class="pt-id-codes">
                HN: <?= htmlspecialchars($row['hn']) ?>
                &nbsp;·&nbsp;
                LN: <?= htmlspecialchars($row['ln']) ?>
              </div>
            </td>

            <?php if ($_SESSION['user_role'] !== 'clinic_user'): ?>
              <td><span class="clinic-tag"><?= htmlspecialchars($row['clinic_name']) ?></span></td>
              <td><span class="doctor-name"><?= htmlspecialchars($row['doctor']) ?></span></td>
            <?php endif; ?>

            <td><span class="date-val"><i class="bi bi-calendar3 me-1" style="opacity:.45;font-size:11px;"></i><?= htmlspecialchars($row['register_date']) ?></span></td>

            <td>
              <?php
                $status = strtolower($row['status']);
                if ($status === 'completed'):
              ?>
                <span class="sp-status sp-status-completed">
                  <span class="sp-status-dot"></span> Completed
                </span>
              <?php else: ?>
                <span class="sp-status sp-status-processing">
                  <span class="sp-status-dot"></span> <?= htmlspecialchars(ucfirst($row['status'])) ?>
                </span>
              <?php endif; ?>
            </td>

            <?php if ($_SESSION['user_role'] !== 'clinic_user'): ?>
              <td class="text-center" onclick="event.stopPropagation();">
                <a href="search_patient.php?delete_patient=<?= $row['id'] ?>"
                   onclick="return confirm('⚠️ Are you sure you want to delete this patient and all their lab test records?');"
                   class="btn-del-row" title="Delete patient">
                  <i class="bi bi-trash3" style="font-size:13px;"></i>
                </a>
              </td>
            <?php endif; ?>

          </tr>
          <?php endwhile; ?>

          <?php if (!$has_rows): ?>
            <tr>
              <td colspan="8">
                <div class="sp-empty">
                  <i class="bi bi-person-x"></i>
                  <p>No patients found matching your filters.</p>
                </div>
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Pagination -->
  <?php if ($total_pages > 1): ?>
    <nav class="mt-3">
      <ul class="pagination justify-content-center sp-pagination">

        <?php
        function sp_page_url($p) {
          $params = $_GET;
          $params['page'] = (int)$p;
          return '?' . http_build_query($params);
        }
        ?>

        <li class="page-item <?= $current_page <= 1 ? 'disabled' : '' ?>">
          <a class="page-link" href="<?= $current_page > 1 ? sp_page_url($current_page - 1) : '#' ?>">
            <i class="bi bi-chevron-left" style="font-size:11px;"></i> Prev
          </a>
        </li>

        <?php
        $pages_to_show = [1, 2, $current_page - 1, $current_page, $current_page + 1, $total_pages - 1, $total_pages];
        $pages_to_show = array_values(array_unique(array_filter($pages_to_show, function($p) use ($total_pages){
          return $p >= 1 && $p <= $total_pages;
        })));
        sort($pages_to_show);

        $last_printed = 0;
        foreach ($pages_to_show as $p) {
          if ($last_printed && $p > $last_printed + 1) {
            echo '<li class="page-item disabled"><span class="page-link" style="background:transparent;border:0;">…</span></li>';
          }
          $active = ($p == $current_page) ? ' active' : '';
          echo '<li class="page-item' . $active . '">
                  <a class="page-link" href="' . sp_page_url($p) . '">' . $p . '</a>
                </li>';
          $last_printed = $p;
        }
        ?>

        <li class="page-item <?= $current_page >= $total_pages ? 'disabled' : '' ?>">
          <a class="page-link" href="<?= $current_page < $total_pages ? sp_page_url($current_page + 1) : '#' ?>">
            Next <i class="bi bi-chevron-right" style="font-size:11px;"></i>
          </a>
        </li>

      </ul>
    </nav>
  <?php endif; ?>

</div><!-- /.sp-page -->

<script>
$(document).ready(function () {

  // Autocomplete
  $('#search_name').keyup(function () {
    const query = $(this).val();
    if (query.length >= 1) {
      $.ajax({
        url: 'fetch_autocomplete.php',
        method: 'POST',
        data: { query: query },
        success: function (data) {
          $('#autocomplete-results').fadeIn().html(data);
        }
      });
    } else {
      $('#autocomplete-results').fadeOut();
    }
  });

  $(document).on('click', '.autocomplete-item', function () {
    const selected = $(this).text();
    $('#search_name').val(selected);
    $('#autocomplete-results').fadeOut();
    $('form').submit();
  });

  // Clickable rows
  $(".clickable-row").css("cursor", "pointer").click(function () {
    window.location = $(this).data("href");
  });

  // Clear search
  const $search = $('#search_name');
  const $clear = $('#clearSearch');

  $search.on('input', function () {
    if ($(this).val().trim() !== '') $clear.removeClass('d-none');
    else $clear.addClass('d-none');
  });

  $clear.click(function () {
    $search.val('');
    $clear.addClass('d-none');
    $('#autocomplete-results').fadeOut();
    $('form').submit();
  });

  $search.trigger('input');

  // Hide autocomplete on outside click
  $(document).on('click', function(e) {
    if (!$(e.target).closest('#search_name, #autocomplete-results').length) {
      $('#autocomplete-results').fadeOut();
    }
  });
});

function resetFilters() {
  document.querySelector('input[name="from"]').value = '';
  document.querySelector('input[name="to"]').value = '';
  document.querySelector('input[name="name"]').value = '';
  document.querySelector('select[name="clinic"]').selectedIndex = 0;
  document.querySelector('form').submit();
}

function openEditModal(id, name, hn, ln) {
  document.getElementById('edit_id').value = id;
  document.getElementById('edit_name').value = name;
  document.getElementById('edit_hn').value = hn;
  document.getElementById('edit_ln').value = ln;
  new bootstrap.Modal(document.getElementById('editPatientModal')).show();
}

document.addEventListener('DOMContentLoaded', function () {
  const totalRows = document.querySelectorAll('#countTable tbody tr').length;
  const el = document.getElementById('totalEntries');
  if (el) el.textContent = totalRows;
});
</script>

<script>
  if (window.location.search.includes('deleted=1')) {
    setTimeout(() => {
      const url = new URL(window.location);
      url.searchParams.delete('deleted');
      window.history.replaceState({}, document.title, url.pathname + url.search);
    }, 500);
  }
</script>

<?php include 'includes/footer.php'; ?>
<?php ob_end_flush(); ?>