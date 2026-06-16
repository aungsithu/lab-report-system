<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
  header("Location: login.php");
  exit;
}

$user_role   = $_SESSION['user_role'] ?? '';
$username    = $_SESSION['username'] ?? '';
$clinic_name = $_SESSION['clinic_name'] ?? '';

function activeClass(string $file): string {
  $cur = basename($_SERVER['PHP_SELF'] ?? '');
  return ($cur === $file) ? ' is-active' : '';
}

// Avatar initials
$display_name = '';
if ($user_role === 'superadmin') $display_name = 'Superadmin';
elseif ($user_role === 'clinic_user') $display_name = $clinic_name ?: 'Clinic User';
else $display_name = $username ?: 'User';
$initials = strtoupper(substr(strip_tags($display_name), 0, 2));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>Celtac Lab System</title>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
  <link rel="icon" type="image/png" href="https://onlinereport.celtaclab.com/assets/celtaclogo.png">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

  <style>
    /* ══════════════════════════════════════════
       GLOBAL TOKENS
    ══════════════════════════════════════════ */
    :root {
      --sb-w:        256px;
      --sb-bg:       #0d1526;
      --sb-accent:   #3b82f6;
      --sb-text:     rgba(255,255,255,.80);
      --sb-muted:    rgba(255,255,255,.38);
      --sb-hover:    rgba(255,255,255,.06);
      --sb-active-bg:rgba(59,130,246,.18);
      --sb-active-bd:rgba(59,130,246,.40);

      --page-bg:     #f0f2f7;
      --topbar-bg:   rgba(240,242,247,.92);
      --border:      rgba(15,23,42,.08);
      --shadow-sm:   0 2px 12px rgba(15,23,42,.07);
      --shadow-md:   0 8px 30px rgba(15,23,42,.10);
      --r:           14px;
    }

    *, *::before, *::after { box-sizing: border-box; }

    html, body {
      font-family: 'Plus Jakarta Sans', sans-serif;
      background: var(--page-bg);
      overflow-x: hidden;
      font-size: 14.5px;
      color: #1e293b;
    }

    h4, h5 { font-weight: 700; }
    label   { font-weight: 600; margin-bottom: 5px; }

    .form-control, .form-select {
      border-radius: 10px;
      border: 1.5px solid rgba(15,23,42,.11);
      font-size: 14px;
      background: #f9fafb;
      transition: border-color .15s, box-shadow .15s;
    }
    .form-control:focus, .form-select:focus {
      border-color: var(--sb-accent);
      box-shadow: 0 0 0 3px rgba(59,130,246,.15);
      background: #fff;
    }

    .card {
      border: 1px solid var(--border);
      border-radius: var(--r);
      box-shadow: var(--shadow-sm);
    }

    /* ══════════════════════════════════════════
       APP SHELL
    ══════════════════════════════════════════ */
    .app { min-height: 100vh; display: flex; }

    /* ══════════════════════════════════════════
       SIDEBAR
    ══════════════════════════════════════════ */
    .sidebar {
      width: var(--sb-w);
      position: fixed;
      left: 0; top: 0; bottom: 0;
      background: var(--sb-bg);
      color: var(--sb-text);
      display: flex;
      flex-direction: column;
      z-index: 1030;
      overflow: hidden;
      border-right: 1px solid rgba(255,255,255,.05);

      /* subtle noise texture via SVG */
      background-image:
        radial-gradient(ellipse 700px 400px at 30% -10%, rgba(59,130,246,.20) 0%, transparent 70%),
        radial-gradient(ellipse 400px 300px at 90% 100%, rgba(99,102,241,.12) 0%, transparent 60%);
    }

    /* ── Logo area ── */
    .sb-logo {
      padding: 20px 18px 16px;
      display: flex;
      align-items: center;
      gap: 12px;
      border-bottom: 1px solid rgba(255,255,255,.06);
      flex-shrink: 0;
    }
    .sb-logo-icon {
      width: 42px; height: 42px;
      border-radius: 12px;
      background: rgba(59,130,246,.20);
      border: 1px solid rgba(59,130,246,.35);
      display: grid; place-items: center;
      flex-shrink: 0;
    }
    .sb-logo-text .l1 {
      font-size: 15px; font-weight: 800;
      letter-spacing: -.2px; line-height: 1.1;
      color: #fff;
    }
    .sb-logo-text .l2 {
      font-size: 11px; color: var(--sb-muted);
      letter-spacing: .02em; margin-top: 1px;
    }
    .sb-close-btn {
      margin-left: auto;
      background: rgba(255,255,255,.06);
      border: 1px solid rgba(255,255,255,.10);
      color: var(--sb-text);
      border-radius: 9px;
      width: 30px; height: 30px;
      display: none; place-items: center;
      font-size: 14px; cursor: pointer;
    }

    /* ── Nav scroll area ── */
    .sb-nav-area {
      flex: 1;
      overflow-y: auto;
      padding: 10px 12px 20px;
      scrollbar-width: none;
    }
    .sb-nav-area::-webkit-scrollbar { display: none; }

    /* ── Section label ── */
    .sb-section {
      font-size: 10px;
      font-weight: 800;
      letter-spacing: .12em;
      color: var(--sb-muted);
      padding: 14px 8px 6px;
      text-transform: uppercase;
    }

    /* ── Nav items ── */
    .sb-item {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 9px 10px;
      border-radius: 10px;
      color: var(--sb-text);
      text-decoration: none;
      font-size: 13.5px;
      font-weight: 500;
      transition: all .15s ease;
      margin-bottom: 2px;
      border: 1px solid transparent;
      position: relative;
    }
    .sb-item:hover {
      background: var(--sb-hover);
      color: #fff;
      border-color: rgba(255,255,255,.06);
    }
    .sb-item.is-active {
      background: var(--sb-active-bg);
      border-color: var(--sb-active-bd);
      color: #fff;
      font-weight: 600;
    }
    .sb-item.is-active .sb-ico {
      background: rgba(59,130,246,.30);
      border-color: rgba(59,130,246,.45);
      color: #93c5fd;
    }

    /* ── Icon box ── */
    .sb-ico {
      width: 30px; height: 30px;
      border-radius: 9px;
      background: rgba(255,255,255,.06);
      border: 1px solid rgba(255,255,255,.08);
      display: grid; place-items: center;
      flex-shrink: 0;
      font-size: 14px;
      transition: background .15s, border-color .15s;
    }
    .sb-item:hover .sb-ico {
      background: rgba(255,255,255,.10);
      border-color: rgba(255,255,255,.14);
    }

    /* ── Active indicator bar ── */
    .sb-item.is-active::before {
      content: '';
      position: absolute;
      left: 0; top: 20%; bottom: 20%;
      width: 3px;
      background: var(--sb-accent);
      border-radius: 0 3px 3px 0;
    }

    /* ── Logout special ── */
    .sb-item.sb-logout:hover {
      background: rgba(239,68,68,.12);
      border-color: rgba(239,68,68,.20);
      color: #fca5a5;
    }
    .sb-item.sb-logout:hover .sb-ico {
      background: rgba(239,68,68,.15);
      border-color: rgba(239,68,68,.25);
    }

    /* ── User card at bottom ── */
    .sb-user {
      flex-shrink: 0;
      padding: 12px 14px;
      border-top: 1px solid rgba(255,255,255,.06);
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .sb-avatar {
      width: 36px; height: 36px;
      border-radius: 10px;
      background: linear-gradient(135deg, #3b82f6, #6366f1);
      display: grid; place-items: center;
      font-size: 13px; font-weight: 800;
      color: #fff;
      flex-shrink: 0;
      letter-spacing: .03em;
    }
    .sb-user-name {
      font-size: 13px; font-weight: 700; color: #fff;
      line-height: 1.2; white-space: nowrap;
      overflow: hidden; text-overflow: ellipsis;
    }
    .sb-user-role {
      font-size: 11px; color: var(--sb-muted);
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .sb-live-dot {
      width: 8px; height: 8px; border-radius: 50%;
      background: #22c55e;
      box-shadow: 0 0 0 3px rgba(34,197,94,.20);
      flex-shrink: 0;
      margin-left: auto;
    }

    /* ══════════════════════════════════════════
       CONTENT AREA
    ══════════════════════════════════════════ */
    .content {
      margin-left: var(--sb-w);
      width: calc(100% - var(--sb-w));
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }

    /* ══════════════════════════════════════════
       TOPBAR
    ══════════════════════════════════════════ */
    .topbar {
      position: sticky;
      top: 0;
      z-index: 1020;
      background: var(--topbar-bg);
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
      border-bottom: 1px solid var(--border);
      flex-shrink: 0;
    }
    .topbar-inner {
      padding: 12px 20px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }
    .topbar-left { display: flex; align-items: center; gap: 12px; }

    /* hamburger */
    .btn-sidebar-toggle {
      display: none;
      align-items: center; gap: 7px;
      background: #fff;
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 7px 12px;
      font-size: 13.5px;
      font-weight: 600;
      color: #374151;
      cursor: pointer;
      transition: background .12s;
    }
    .btn-sidebar-toggle:hover { background: #f1f5f9; }

    /* breadcrumb-style title */
    .topbar-title-wrap {}
    .topbar-title {
      font-size: 15px;
      font-weight: 800;
      color: #0f172a;
      letter-spacing: -.2px;
      margin: 0;
      line-height: 1.2;
    }
    .topbar-sub {
      font-size: 11.5px;
      color: #94a3b8;
      margin: 0;
      font-weight: 500;
    }

    /* right cluster */
    .topbar-right { display: flex; align-items: center; gap: 10px; }

    /* current page chip */
    .topbar-page-chip {
      background: #eff6ff;
      border: 1px solid #bfdbfe;
      border-radius: 8px;
      padding: 5px 12px;
      font-size: 12px;
      font-weight: 700;
      color: #1d4ed8;
      white-space: nowrap;
    }

    /* user pill */
    .topbar-user {
      background: #fff;
      border: 1px solid var(--border);
      border-radius: 999px;
      padding: 6px 14px 6px 8px;
      display: flex;
      align-items: center;
      gap: 9px;
      box-shadow: var(--shadow-sm);
    }
    .topbar-avatar {
      width: 30px; height: 30px;
      border-radius: 8px;
      background: linear-gradient(135deg, #3b82f6, #6366f1);
      display: grid; place-items: center;
      font-size: 11px; font-weight: 800;
      color: #fff;
      flex-shrink: 0;
    }
    .topbar-user-name {
      font-size: 13px; font-weight: 700;
      color: #0f172a; line-height: 1.1;
    }
    .topbar-user-role {
      font-size: 11px; color: #94a3b8; font-weight: 500;
    }
    .topbar-dot {
      width: 8px; height: 8px; border-radius: 50%;
      background: #22c55e;
      box-shadow: 0 0 0 3px rgba(34,197,94,.18);
      flex-shrink: 0;
    }

    /* ══════════════════════════════════════════
       PAGE WRAPPER
    ══════════════════════════════════════════ */
    .page-wrap {
      flex: 1;
      padding: 22px 24px;
    }
    .container-narrow { max-width: 1300px; margin: 0 auto; }

    /* ══════════════════════════════════════════
       TABLE GLOBAL DEFAULTS
    ══════════════════════════════════════════ */
    table { font-size: 14px; }
    table td, table th { padding: 10px 13px !important; }
    .table-group-row td {
      background: linear-gradient(90deg, #eff6ff, #f8fafc) !important;
      font-weight: 700;
      border-top: 2px solid #bfdbfe !important;
    }
    .sticky-header thead th {
      position: sticky; top: 0; z-index: 10; background: #f8fafc;
    }

    /* ══════════════════════════════════════════
       SCROLLBAR
    ══════════════════════════════════════════ */
    * { scrollbar-width: thin; scrollbar-color: transparent transparent; }
    *::-webkit-scrollbar { width: 7px; height: 7px; }
    *::-webkit-scrollbar-track { background: transparent; }
    *::-webkit-scrollbar-thumb { background: transparent; border-radius: 999px; }
    *:hover::-webkit-scrollbar-thumb { background: rgba(15,23,42,.18); }
    .sidebar:hover::-webkit-scrollbar-thumb { background: rgba(255,255,255,.18); }

    /* ══════════════════════════════════════════
       MOBILE
    ══════════════════════════════════════════ */
    .sidebar-backdrop {
      display: none;
      position: fixed; inset: 0;
      background: rgba(0,0,0,.50);
      z-index: 1029;
      backdrop-filter: blur(2px);
    }

    @media (max-width: 992px) {
      .btn-sidebar-toggle { display: inline-flex; }
      .sb-close-btn       { display: grid; }
      .topbar-page-chip   { display: none; }
      .content            { margin-left: 0; width: 100%; }
      .sidebar {
        transform: translateX(-110%);
        transition: transform .22s cubic-bezier(.4,0,.2,1);
      }
      body.sidebar-open .sidebar     { transform: translateX(0); }
      body.sidebar-open .sidebar-backdrop { display: block; }
    }

    @media (max-width: 480px) {
      .topbar-user-role { display: none; }
      .page-wrap { padding: 14px 14px; }
    }
  </style>
</head>

<body>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<div class="app">

  <!-- ══════════ SIDEBAR ══════════ -->
  <aside class="sidebar">

    <!-- Logo -->
    <div class="sb-logo">
      <div class="sb-logo-icon">
        <img src="assets/celtaclogo.png" alt="Celtac" style="width:22px;height:22px;object-fit:contain;">
      </div>
      <div class="sb-logo-text">
        <div class="l1">Celtac Lab</div>
        <div class="l2">Lab Information System</div>
      </div>
      <button class="sb-close-btn" id="btnCloseSidebar" aria-label="Close sidebar">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>

    <!-- Nav -->
    <div class="sb-nav-area">

      <div class="sb-section">Main</div>

      <a href="dashboard.php" class="sb-item<?= activeClass('dashboard.php') ?>">
        <span class="sb-ico"><i class="bi bi-speedometer2"></i></span>
        Dashboard
      </a>

      <a href="search_patient.php" class="sb-item<?= activeClass('search_patient.php') ?>">
        <span class="sb-ico"><i class="bi bi-person-lines-fill"></i></span>
        Search Patients
      </a>

      <?php if ($user_role === 'superadmin' || $user_role === 'admin'): ?>

        <div class="sb-section">Management</div>

        <a href="add_patient.php" class="sb-item<?= activeClass('add_patient.php') ?>">
          <span class="sb-ico"><i class="bi bi-person-plus"></i></span>
          Add Patient
        </a>

        <a href="manage_users.php" class="sb-item<?= activeClass('manage_users.php') ?>">
          <span class="sb-ico"><i class="bi bi-people"></i></span>
          Manage Users
        </a>

        <a href="manage_clinics.php" class="sb-item<?= activeClass('manage_clinics.php') ?>">
          <span class="sb-ico"><i class="bi bi-building-check"></i></span>
          Manage Clinics
        </a>

        <a href="import_prices.php" class="sb-item<?= activeClass('import_prices.php') ?>">
          <span class="sb-ico"><i class="bi bi-tags"></i></span>
          Price List
        </a>

        <a href="invoice_list.php" class="sb-item<?= activeClass('invoice_list.php') ?>">
          <span class="sb-ico"><i class="bi bi-receipt-cutoff"></i></span>
          Invoice List
        </a>

        <a href="send_email_to_clinic.php" class="sb-item<?= activeClass('send_email_to_clinic.php') ?>">
          <span class="sb-ico"><i class="bi bi-envelope-arrow-up"></i></span>
          Send Email
        </a>

      <?php endif; ?>

      <div class="sb-section">Account</div>

      <a href="change_password.php" class="sb-item<?= activeClass('change_password.php') ?>">
        <span class="sb-ico"><i class="bi bi-shield-lock"></i></span>
        Change Password
      </a>

      <a href="logout.php" class="sb-item sb-logout" style="margin-top:6px;">
        <span class="sb-ico"><i class="bi bi-box-arrow-right"></i></span>
        Logout
      </a>

    </div><!-- /sb-nav-area -->

    <!-- User card at bottom of sidebar -->
    <div class="sb-user">
      <div class="sb-avatar"><?= htmlspecialchars($initials) ?></div>
      <div style="min-width:0;">
        <div class="sb-user-name"><?= htmlspecialchars($display_name) ?></div>
        <div class="sb-user-role"><?= htmlspecialchars($user_role ?: 'role') ?></div>
      </div>
      <div class="sb-live-dot" title="Online"></div>
    </div>

  </aside>

  <!-- ══════════ CONTENT ══════════ -->
  <main class="content">

    <!-- Topbar -->
    <div class="topbar">
      <div class="topbar-inner">

        <div class="topbar-left">
          <button class="btn-sidebar-toggle" id="btnOpenSidebar" type="button" aria-label="Toggle sidebar">
            <i class="bi bi-list fs-5"></i>
            <span>Menu</span>
          </button>
          <div class="topbar-title-wrap">
            <p class="topbar-title">Celtac Lab System</p>
            <p class="topbar-sub">Reports &amp; Patient Management</p>
          </div>
        </div>

        <div class="topbar-right">
          <?php
            $page_labels = [
              'dashboard.php'           => 'Dashboard',
              'search_patient.php'      => 'Search Patients',
              'add_patient.php'         => 'Add Patient',
              'manage_users.php'        => 'Manage Users',
              'manage_clinics.php'      => 'Manage Clinics',
              'import_prices.php'       => 'Price List',
              'invoice_list.php'        => 'Invoice List',
              'send_email_to_clinic.php'=> 'Send Email',
              'change_password.php'     => 'Change Password',
              'view_patient.php'        => 'Patient Report',
              'upload_pdf.php'          => 'Upload PDF',
            ];
            $cur_page = basename($_SERVER['PHP_SELF'] ?? '');
            if (isset($page_labels[$cur_page])):
          ?>
            <span class="topbar-page-chip">
              <?= htmlspecialchars($page_labels[$cur_page]) ?>
            </span>
          <?php endif; ?>

          <div class="topbar-user">
            <div class="topbar-avatar"><?= htmlspecialchars($initials) ?></div>
            <div>
              <div class="topbar-user-name"><?= htmlspecialchars($display_name) ?></div>
              <div class="topbar-user-role"><?= htmlspecialchars($user_role ?: 'role') ?></div>
            </div>
            <div class="topbar-dot"></div>
          </div>
        </div>

      </div>
    </div>

    <!-- Page content wrapper -->
    <div class="page-wrap">
      <div class="container-narrow">

        <script>
          (function () {
            const openBtn  = document.getElementById('btnOpenSidebar');
            const closeBtn = document.getElementById('btnCloseSidebar');
            const backdrop = document.getElementById('sidebarBackdrop');

            const open  = () => document.body.classList.add('sidebar-open');
            const close = () => document.body.classList.remove('sidebar-open');

            openBtn?.addEventListener('click', open);
            closeBtn?.addEventListener('click', close);
            backdrop?.addEventListener('click', close);
            document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
          })();
        </script>