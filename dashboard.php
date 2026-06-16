<?php
require 'includes/db.php';
require 'includes/session_check.php';
include 'includes/header.php';

$role      = $_SESSION['role'] ?? '';
$clinic_id = $_SESSION['clinic_id'] ?? 0;

// Total counts
$total_clinics  = $conn->query("SELECT COUNT(*) AS count FROM clinics")->fetch_assoc()['count'];
$total_patients = $role === 'clinic_user'
    ? $conn->query("SELECT COUNT(*) AS count FROM patients WHERE clinic_id = $clinic_id")->fetch_assoc()['count']
    : $conn->query("SELECT COUNT(*) AS count FROM patients")->fetch_assoc()['count'];

// ── Patient registrations per day (last 30 days) ──
$chart_sql = $role === 'clinic_user'
    ? "SELECT DATE(register_date) AS day, COUNT(*) AS cnt FROM patients WHERE clinic_id = $clinic_id AND register_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY DATE(register_date) ORDER BY day ASC"
    : "SELECT DATE(register_date) AS day, COUNT(*) AS cnt FROM patients WHERE register_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY DATE(register_date) ORDER BY day ASC";
$chart_res  = $conn->query($chart_sql);
$chart_days = [];
$chart_vals = [];
if ($chart_res) {
    while ($cr = $chart_res->fetch_assoc()) {
        $chart_days[] = date('d M', strtotime($cr['day']));
        $chart_vals[] = (int)$cr['cnt'];
    }
}
$chart_days_json = json_encode($chart_days);
$chart_vals_json = json_encode($chart_vals);
$chart_total_30  = array_sum($chart_vals);

// Login table
$logins = [];
if ($role === 'superadmin') {
    $logins = $conn->query("SELECT u.username, u.role, c.name AS clinic_name, u.last_login
                            FROM users u
                            LEFT JOIN clinics c ON u.clinic_id = c.id
                            ORDER BY u.last_login DESC");
} elseif ($role === 'admin') {
    $logins = $conn->query("SELECT u.username, c.name AS clinic_name, u.last_login
                            FROM users u
                            JOIN clinics c ON u.clinic_id = c.id
                            WHERE u.role = 'clinic_user'
                            ORDER BY u.last_login DESC");
}
?>

<style>
.db-page {
  --db-blue:   #2563eb;
  --db-green:  #16a34a;
  --db-amber:  #d97706;
  --db-red:    #dc2626;
  --db-border: rgba(15,23,42,.08);
  --db-shadow: 0 4px 24px rgba(15,23,42,.07);
  --db-r:      14px;
}

/* ── Hero ── */
.db-hero {
  background: linear-gradient(120deg, #0f2444 0%, #1d4ed8 55%, #3b82f6 100%);
  border-radius: var(--db-r);
  padding: 22px 28px; color: #fff;
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; flex-wrap: wrap;
  position: relative; overflow: hidden; margin-bottom: 22px;
}
.db-hero::before { content:''; position:absolute; right:-40px; top:-40px; width:200px; height:200px; border-radius:50%; background:rgba(255,255,255,.05); }
.db-hero::after  { content:''; position:absolute; right:80px; bottom:-60px; width:150px; height:150px; border-radius:50%; background:rgba(255,255,255,.04); }
.db-hero h4 { font-size:20px; font-weight:750; margin:0 0 3px; letter-spacing:-.2px; }
.db-hero p  { margin:0; font-size:13px; opacity:.78; }
.db-role-pill {
  background: rgba(255,255,255,.15); border: 1px solid rgba(255,255,255,.22);
  border-radius: 999px; padding: 5px 15px;
  font-size: 13px; font-weight: 700;
  display: flex; align-items: center; gap: 7px;
  backdrop-filter: blur(4px); white-space: nowrap;
  position: relative; z-index: 1;
}

/* ── Stat cards ── */
.db-stat {
  background: #fff; border: 1px solid var(--db-border);
  border-radius: var(--db-r); box-shadow: var(--db-shadow);
  overflow: hidden; transition: transform .15s, box-shadow .15s;
}
.db-stat:hover { transform: translateY(-2px); box-shadow: 0 12px 36px rgba(15,23,42,.11); }
.db-stat-top { padding: 18px 20px 14px; display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.db-stat-label { font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.09em; color:#6b7280; margin:0 0 6px; }
.db-stat-val   { font-size:34px; font-weight:800; color:#0f172a; margin:0; line-height:1; letter-spacing:-1px; font-variant-numeric:tabular-nums; }
.db-stat-ico   { width:48px; height:48px; border-radius:13px; display:grid; place-items:center; font-size:22px; flex-shrink:0; }
.db-stat-ico-blue  { background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; }
.db-stat-ico-green { background:#f0fdf4; color:#16a34a; border:1px solid #86efac; }
.db-stat-ico-amber { background:#fffbeb; color:#d97706; border:1px solid #fcd34d; }
.db-stat-footer { padding:10px 20px 14px; font-size:12.5px; color:#6b7280; border-top:1px solid rgba(15,23,42,.05); display:flex; align-items:center; gap:6px; }

/* ── Chart card ── */
.db-chart-card {
  background: #fff; border: 1px solid var(--db-border);
  border-radius: var(--db-r); box-shadow: var(--db-shadow);
  overflow: hidden; height: 100%;
}
.db-chart-head {
  padding: 14px 18px 12px;
  border-bottom: 1px solid rgba(15,23,42,.06);
  display: flex; align-items: center; justify-content: space-between;
}
.db-chart-head-left .label { font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.09em; color:#6b7280; margin:0 0 3px; }
.db-chart-head-left .val   { font-size:26px; font-weight:800; color:#0f172a; margin:0; line-height:1; letter-spacing:-0.5px; }
.db-chart-head-left .sub   { font-size:12px; color:#9ca3af; margin:3px 0 0; }
.db-chart-body { padding: 12px 14px 14px; position: relative; height: 130px; }

/* ── Cards ── */
.db-card {
  background: #fff; border: 1px solid var(--db-border);
  border-radius: var(--db-r); box-shadow: var(--db-shadow);
  margin-bottom: 20px;
}
.db-card-head {
  background: #f8fafc; border-bottom: 1px solid var(--db-border);
  padding: 13px 20px;
  display: flex; align-items: center; justify-content: space-between; gap: 10px;
}
.db-card-head .title { font-size:14px; font-weight:700; color:#111827; display:flex; align-items:center; gap:8px; }
.db-card-head .hint  { font-size:12px; color:#9ca3af; }

/* ── Tables ── */
.db-table-wrap   { border-radius: 0 0 var(--db-r) var(--db-r); overflow: hidden; }
.db-table-scroll {
  max-height: 55vh; overflow-y: scroll; overflow-x: auto;
  scrollbar-width: thin; scrollbar-color: rgba(15,23,42,.20) rgba(15,23,42,.05);
}
.db-table-scroll::-webkit-scrollbar { width:7px; height:7px; }
.db-table-scroll::-webkit-scrollbar-track { background:rgba(15,23,42,.04); border-radius:999px; }
.db-table-scroll::-webkit-scrollbar-thumb { background:rgba(15,23,42,.18); border-radius:999px; }
.db-table-scroll::-webkit-scrollbar-thumb:hover { background:rgba(15,23,42,.32); }

.db-table thead th {
  position:sticky; top:0; z-index:5; background:#f8fafc;
  font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.08em; color:#475569;
  padding:12px 16px !important; border-bottom:2px solid rgba(15,23,42,.07); white-space:nowrap;
}
.db-table tbody td { padding:12px 16px !important; font-size:14px; color:#111827; vertical-align:middle; border-color:rgba(15,23,42,.05); }
.db-table tbody tr { transition:background .1s; }
.db-table tbody tr:hover { background:#f0f7ff !important; cursor:pointer; }

/* ── User cells ── */
.db-username { font-weight:700; color:#0f172a; font-size:14px; display:flex; align-items:center; gap:8px; }
.db-user-avatar {
  width:30px; height:30px; border-radius:8px;
  background:linear-gradient(135deg,#3b82f6,#6366f1);
  display:grid; place-items:center;
  font-size:11px; font-weight:800; color:#fff; flex-shrink:0;
}
.db-role-badge { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:700; white-space:nowrap; }
.db-role-superadmin { background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; }
.db-role-admin      { background:#fdf4ff; color:#7e22ce; border:1px solid #e9d5ff; }
.db-role-clinic     { background:#f0fdf4; color:#15803d; border:1px solid #86efac; }
.db-role-default    { background:#f1f5f9; color:#334155; border:1px solid #cbd5e1; }
.db-clinic-tag { display:inline-block; background:#f0f9ff; color:#0369a1; border:1px solid #bae6fd; border-radius:6px; padding:3px 10px; font-size:13px; font-weight:600; }
.db-login-time  { font-size:13.5px; color:#374151; font-weight:500; }
.db-login-never { font-size:13px; color:#9ca3af; font-style:italic; }

/* ── Pills ── */
.db-pill { display:inline-flex; align-items:center; gap:5px; padding:4px 11px; border-radius:999px; font-size:12.5px; font-weight:700; white-space:nowrap; }
.db-pill-done { background:#f0fdf4; color:#15803d; border:1px solid #86efac; }
.db-pill-proc { background:#fffbeb; color:#b45309; border:1px solid #fcd34d; }
.db-pill-dot  { width:7px; height:7px; border-radius:50%; display:inline-block; }
.db-pill-done .db-pill-dot { background:#16a34a; }
.db-pill-proc .db-pill-dot { background:#d97706; }

.db-num { font-size:15px; font-weight:800; color:#0f172a; font-variant-numeric:tabular-nums; }

/* ── DataTables ── */
.dataTables_wrapper .dataTables_filter input { border-radius:10px !important; border:1.5px solid rgba(15,23,42,.11) !important; padding:7px 12px !important; font-size:14px !important; background:#f9fafb; }
.dataTables_wrapper .dataTables_filter input:focus { border-color:#2563eb !important; outline:none; box-shadow:0 0 0 3px rgba(37,99,235,.12) !important; }
.dataTables_wrapper .dataTables_length select { border-radius:9px !important; border:1.5px solid rgba(15,23,42,.11) !important; padding:6px 10px !important; font-size:13.5px !important; }
.dataTables_wrapper .dataTables_info { font-size:13px; color:#6b7280; }
div.dataTables_wrapper div.dataTables_paginate ul.pagination .page-link { border-radius:8px !important; font-size:13px; border-color:rgba(15,23,42,.10); color:#374151; }
div.dataTables_wrapper div.dataTables_paginate ul.pagination .page-item.active .page-link { background:#2563eb; border-color:#2563eb; }

/* ── Patient table (clinic_user) ── */
.db-pt-name { font-weight:700; color:#0f172a; font-size:14px; }
.db-pt-code { font-family:monospace; font-size:13px; color:#374151; font-weight:600; }
.db-pt-date { font-size:13.5px; color:#374151; }
.db-gender  { font-size:13.5px; color:#374151; font-weight:500; }
.db-age     { font-size:14px; font-weight:600; color:#0f172a; }
</style>

<div class="db-page">

  <!-- Hero -->
  <div class="db-hero">
    <div style="position:relative;z-index:1;">
      <h4><i class="bi bi-graph-up-arrow me-2" style="opacity:.85;"></i>Dashboard Overview</h4>
      <p>Quick stats and latest system activity</p>
    </div>
    <div class="db-role-pill">
      <i class="bi bi-shield-check"></i>
      <?= htmlspecialchars($role ?: 'unknown') ?>
    </div>
  </div>

  <!-- ── Stat Cards + Chart ── -->
  <div class="row g-3 mb-4">

    <?php if ($role !== 'clinic_user'): ?>
    <div class="col-sm-6 col-lg-3">
      <div class="db-stat">
        <div class="db-stat-top">
          <div>
            <p class="db-stat-label">Total Clinics</p>
            <p class="db-stat-val"><?= number_format((int)$total_clinics) ?></p>
          </div>
          <div class="db-stat-ico db-stat-ico-blue"><i class="bi bi-building-check"></i></div>
        </div>
        <div class="db-stat-footer"><i class="bi bi-info-circle" style="font-size:12px;"></i> Clinics registered in system</div>
      </div>
    </div>
    <?php endif; ?>

    <div class="col-sm-6 col-lg-3">
      <div class="db-stat">
        <div class="db-stat-top">
          <div>
            <p class="db-stat-label">Total Patients</p>
            <p class="db-stat-val"><?= number_format((int)$total_patients) ?></p>
          </div>
          <div class="db-stat-ico db-stat-ico-green"><i class="bi bi-people-fill"></i></div>
        </div>
        <div class="db-stat-footer">
          <i class="bi bi-info-circle" style="font-size:12px;"></i>
          <?= $role === 'clinic_user' ? 'Patients in your clinic' : 'Total patients in system' ?>
        </div>
      </div>
    </div>

    <!-- Chart card — spans remaining columns -->
    <div class="col-12 <?= $role !== 'clinic_user' ? 'col-lg-6' : 'col-lg-9' ?>">
      <div class="db-chart-card">
        <div class="db-chart-head">
          <div class="db-chart-head-left">
            <p class="label">New Patients</p>
            <p class="val"><?= number_format($chart_total_30) ?></p>
            <p class="sub">Registered in last 30 days</p>
          </div>
          <div class="db-stat-ico db-stat-ico-amber"><i class="bi bi-graph-up-arrow"></i></div>
        </div>
        <div class="db-chart-body">
          <canvas id="patientChart"></canvas>
        </div>
      </div>
    </div>

  </div><!-- /.row stat cards -->

  <?php if ($role !== 'clinic_user'): ?>

    <!-- ── User Logins ── -->
    <div class="db-card">
      <div class="db-card-head">
        <span class="title">
          <i class="bi bi-person-badge text-primary"></i>
          <?= $role === 'superadmin' ? 'All User Logins' : 'Clinic Users & Last Login' ?>
        </span>
        <span class="hint">Latest login first</span>
      </div>
      <div class="db-table-wrap">
        <div class="db-table-scroll">
          <table class="table table-hover align-middle mb-0 db-table">
            <thead>
              <tr>
                <th>User</th>
                <?php if ($role === 'superadmin'): ?><th>Role</th><?php endif; ?>
                <th>Clinic</th>
                <th>Last Login</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($logins && $logins->num_rows > 0): ?>
                <?php while ($u = $logins->fetch_assoc()):
                  $init     = strtoupper(substr($u['username'], 0, 2));
                  $r        = strtolower($u['role'] ?? '');
                  $role_cls = match($r) {
                    'superadmin'  => 'db-role-superadmin',
                    'admin'       => 'db-role-admin',
                    'clinic_user' => 'db-role-clinic',
                    default       => 'db-role-default'
                  };
                ?>
                <tr>
                  <td>
                    <div class="db-username">
                      <div class="db-user-avatar"><?= htmlspecialchars($init) ?></div>
                      <?= htmlspecialchars($u['username']) ?>
                    </div>
                  </td>
                  <?php if ($role === 'superadmin'): ?>
                  <td>
                    <span class="db-role-badge <?= $role_cls ?>">
                      <i class="bi bi-shield-lock" style="font-size:11px;"></i>
                      <?= htmlspecialchars(ucfirst($u['role'])) ?>
                    </span>
                  </td>
                  <?php endif; ?>
                  <td>
                    <?php if (!empty($u['clinic_name'])): ?>
                      <span class="db-clinic-tag"><?= htmlspecialchars($u['clinic_name']) ?></span>
                    <?php else: ?>
                      <span style="color:#9ca3af;">—</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (!empty($u['last_login'])): ?>
                      <span class="db-login-time">
                        <i class="bi bi-clock me-1" style="opacity:.45;font-size:12px;"></i>
                        <?= htmlspecialchars($u['last_login']) ?>
                      </span>
                    <?php else: ?>
                      <span class="db-login-never">Never logged in</span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endwhile; ?>
              <?php else: ?>
                <tr>
                  <td colspan="<?= $role === 'superadmin' ? 4 : 3 ?>" class="text-center py-5" style="color:#9ca3af;">
                    <i class="bi bi-people" style="font-size:28px;display:block;margin-bottom:8px;opacity:.4;"></i>
                    No users found.
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ── Patient Summary by Clinic ── -->
    <?php
    $result = $conn->query("SELECT c.name AS clinic_name,
                     COUNT(p.id) AS total,
                     SUM(CASE WHEN p.status = 'processing' THEN 1 ELSE 0 END) AS processing_count,
                     SUM(CASE WHEN p.status = 'completed'  THEN 1 ELSE 0 END) AS completed_count
              FROM clinics c
              JOIN patients p ON c.id = p.clinic_id
              GROUP BY c.id");
    ?>
    <div class="db-card">
      <div class="db-card-head">
        <span class="title"><i class="bi bi-clipboard-data text-primary"></i> Patient Summary by Clinic</span>
        <span class="hint">Processing vs Completed</span>
      </div>
      <div class="db-table-wrap">
        <div class="db-table-scroll">
          <table class="table table-hover align-middle mb-0 db-table">
            <thead>
              <tr>
                <th>Clinic</th>
                <th>Total Patients</th>
                <th>Processing</th>
                <th>Completed</th>
                <th>Progress</th>
              </tr>
            </thead>
            <tbody>
              <?php while ($row = $result->fetch_assoc()):
                $total = (int)$row['total'];
                $done  = (int)$row['completed_count'];
                $pct   = $total > 0 ? round($done / $total * 100) : 0;
              ?>
              <tr>
                <td><span style="font-weight:700;color:#0f172a;font-size:14px;"><?= htmlspecialchars($row['clinic_name']) ?></span></td>
                <td><span class="db-num"><?= number_format($total) ?></span></td>
                <td><span class="db-pill db-pill-proc"><span class="db-pill-dot"></span><?= number_format((int)$row['processing_count']) ?></span></td>
                <td><span class="db-pill db-pill-done"><span class="db-pill-dot"></span><?= number_format($done) ?></span></td>
                <td style="min-width:130px;">
                  <div style="display:flex;align-items:center;gap:8px;">
                    <div style="flex:1;height:6px;background:#e2e8f0;border-radius:999px;overflow:hidden;">
                      <div style="height:100%;width:<?= $pct ?>%;background:linear-gradient(90deg,#16a34a,#22c55e);border-radius:999px;"></div>
                    </div>
                    <span style="font-size:12px;font-weight:700;color:#374151;white-space:nowrap;"><?= $pct ?>%</span>
                  </div>
                </td>
              </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  <?php endif; ?>

  <?php if ($role === 'clinic_user'): ?>

    <!-- ── Clinic user patient list ── -->
    <div class="db-card">
      <div class="db-card-head">
        <span class="title"><i class="bi bi-people text-primary"></i> Patient List</span>
        <span class="hint">Click a row to open patient details</span>
      </div>
      <div class="p-3">
        <?php
        $stmt = $conn->prepare("SELECT p.id, p.name, p.hn, p.ln, IFNULL(c.name,'-') AS clinic_name, p.doctor, p.age, p.sex, p.status, p.register_date
                                FROM patients p LEFT JOIN clinics c ON p.clinic_id = c.id
                                WHERE p.clinic_id = ? ORDER BY p.id DESC");
        $stmt->bind_param("i", $clinic_id);
        $stmt->execute();
        $patients = $stmt->get_result();
        ?>
        <table id="clinicPatientTable" class="table table-hover align-middle mb-0 db-table" style="border-radius:10px;overflow:hidden;">
          <thead>
            <tr>
              <th style="width:44px;">#</th>
              <th>Patient</th>
              <th>HN / LN</th>
              <th>Age</th>
              <th>Sex</th>
              <th>Status</th>
              <th>Registered</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($patients && $patients->num_rows > 0): ?>
              <?php while ($p = $patients->fetch_assoc()): ?>
              <tr class="clickable-row" data-id="<?= (int)$p['id'] ?>">
                <td></td>
                <td><div class="db-pt-name"><?= htmlspecialchars($p['name']) ?></div></td>
                <td><span class="db-pt-code"><?= htmlspecialchars($p['hn']) ?> / <?= htmlspecialchars($p['ln']) ?></span></td>
                <td><span class="db-age"><?= (int)$p['age'] ?></span></td>
                <td><span class="db-gender"><?= htmlspecialchars($p['sex']) ?></span></td>
                <td>
                  <?php $st = strtolower(trim((string)$p['status'])); ?>
                  <?php if ($st === 'completed'): ?>
                    <span class="db-pill db-pill-done"><span class="db-pill-dot"></span> Completed</span>
                  <?php else: ?>
                    <span class="db-pill db-pill-proc"><span class="db-pill-dot"></span> <?= htmlspecialchars(ucfirst($p['status'])) ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="db-pt-date">
                    <i class="bi bi-calendar3 me-1" style="opacity:.4;font-size:11px;"></i>
                    <?= htmlspecialchars($p['register_date']) ?>
                  </span>
                </td>
              </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr>
                <td colspan="7" class="text-center py-5" style="color:#9ca3af;">
                  <i class="bi bi-person-x" style="font-size:30px;display:block;margin-bottom:8px;opacity:.4;"></i>
                  No patients found for your clinic.
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script>
    $(document).ready(function () {
      const table = $('#clinicPatientTable').DataTable({
        pageLength: 20, order: [],
        columnDefs: [{ targets: 0, searchable: false, orderable: false }]
      });
      table.on('order.dt search.dt draw.dt', function () {
        let i = 1;
        table.cells(null, 0, { search:'applied', order:'applied' }).every(function () { this.data(i++); });
      }).draw();
      $('#clinicPatientTable tbody').on('click', 'tr', function () {
        const id = $(this).data('id');
        if (id) window.location.href = 'view_patient.php?id=' + id;
      });
    });
    </script>

  <?php endif; ?>

</div><!-- /.db-page -->

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
  const canvas = document.getElementById('patientChart');
  if (!canvas) return;

  const days = <?= $chart_days_json ?>;
  const vals = <?= $chart_vals_json ?>;

  if (vals.length === 0) {
    canvas.style.display = 'none';
    canvas.insertAdjacentHTML('afterend',
      '<div style="height:100%;display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:13px;font-style:italic;">No registrations in the last 30 days</div>'
    );
    return;
  }

  new Chart(canvas, {
    type: 'line',
    data: {
      labels: days,
      datasets: [{
        data: vals,
        borderColor: '#2563eb',
        backgroundColor: 'rgba(37,99,235,.10)',
        borderWidth: 2.5,
        pointRadius: vals.length > 15 ? 0 : 4,
        pointHoverRadius: 6,
        pointBackgroundColor: '#fff',
        pointBorderColor: '#2563eb',
        pointBorderWidth: 2,
        fill: true,
        tension: 0.4,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            title: ctx => ctx[0].label,
            label: ctx => '  ' + ctx.parsed.y + ' patient' + (ctx.parsed.y !== 1 ? 's' : '')
          },
          backgroundColor: '#0f172a',
          titleColor: '#94a3b8',
          bodyColor: '#fff',
          padding: 10,
          cornerRadius: 8,
          displayColors: false,
        }
      },
      scales: {
        x: {
          grid: { display: false },
          ticks: { font:{ size:10 }, color:'#9ca3af', maxTicksLimit:8, maxRotation:0 },
          border: { display: false }
        },
        y: {
          grid: { color:'rgba(15,23,42,.05)' },
          ticks: { font:{ size:10 }, color:'#9ca3af', stepSize:1, maxTicksLimit:5, precision:0 },
          border: { display: false },
          beginAtZero: true,
        }
      }
    }
  });
})();
</script>

<?php include 'includes/footer.php'; ?>