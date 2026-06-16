<?php
ob_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'includes/session_check.php';
require 'includes/db.php';
include 'includes/header.php';

$user_role = $_SESSION['user_role'] ?? '';
$role = $_SESSION['role'] ?? $_SESSION['user_role'] ?? '';

if (!in_array($role, ['superadmin', 'admin'])) {
    echo "<div class='alert alert-danger m-4'>❌ Access Denied. Only admin or superadmin can access this page.</div>";
    include 'includes/footer.php';
    exit;
}

/* ------------ Helpers ------------ */
function clean($s) { return trim((string)$s); }
function nullif($s) { $s = clean($s); return $s === '' ? null : $s; }

/* ------------ Add ------------ */
if (isset($_POST['add_clinic'])) {
    $name         = clean($_POST['name'] ?? '');
    $company_name = nullif($_POST['company_name'] ?? '');
    $tax_id       = nullif($_POST['tax_id'] ?? '');
    $phone        = nullif($_POST['phone'] ?? '');
    $email        = nullif($_POST['email'] ?? '');
    $address      = nullif($_POST['address'] ?? '');

    if ($name !== '') {
        $stmt = $conn->prepare("
            INSERT INTO clinics (name, company_name, tax_id, phone, email, address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("ssssss", $name, $company_name, $tax_id, $phone, $email, $address);
        $stmt->execute();
        header("Location: manage_clinics.php");
        exit;
    } else {
        echo "<div class='alert alert-warning m-3'>Please provide at least the Clinic Name.</div>";
    }
}

/* ------------ Edit ------------ */
if (isset($_POST['edit_clinic'])) {
    $id           = (int)($_POST['clinic_id'] ?? 0);
    $name         = clean($_POST['name'] ?? '');
    $company_name = nullif($_POST['company_name'] ?? '');
    $tax_id       = nullif($_POST['tax_id'] ?? '');
    $phone        = nullif($_POST['phone'] ?? '');
    $email        = nullif($_POST['email'] ?? '');
    $address      = nullif($_POST['address'] ?? '');

    if ($id > 0 && $name !== '') {
        $stmt = $conn->prepare("
            UPDATE clinics
               SET name = ?, company_name = ?, tax_id = ?, phone = ?, email = ?, address = ?
             WHERE id = ?
        ");
        $stmt->bind_param("ssssssi", $name, $company_name, $tax_id, $phone, $email, $address, $id);
        $stmt->execute();
        header("Location: manage_clinics.php");
        exit;
    } else {
        echo "<div class='alert alert-warning m-3'>Invalid request.</div>";
    }
}

/* ------------ Delete ------------ */
if (isset($_GET['delete_clinic']) && $role === 'superadmin') {
    $delete_id = (int)$_GET['delete_clinic'];
    $conn->query("DELETE FROM clinics WHERE id = {$delete_id}");
    header("Location: manage_clinics.php");
    exit;
}

/* ------------ Fetch ------------ */
$clinics = $conn->query("SELECT * FROM clinics ORDER BY id DESC");
?>

<style>
/* ── Tokens ── */
.mc-page {
  --mc-blue:   #2563eb;
  --mc-green:  #16a34a;
  --mc-red:    #dc2626;
  --mc-amber:  #d97706;
  --mc-border: rgba(15,23,42,.08);
  --mc-shadow: 0 4px 24px rgba(15,23,42,.07);
  --mc-r:      14px;
}

/* ── Hero ── */
.mc-hero {
  background: linear-gradient(120deg, #0f2444 0%, #1d4ed8 55%, #3b82f6 100%);
  border-radius: var(--mc-r);
  padding: 22px 28px;
  color: #fff;
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; flex-wrap: wrap; overflow: hidden;
  position: relative; margin-bottom: 22px;
}
.mc-hero::before {
  content:''; position:absolute; right:-40px; top:-40px;
  width:200px; height:200px; border-radius:50%;
  background:rgba(255,255,255,.05);
}
.mc-hero::after {
  content:''; position:absolute; right:80px; bottom:-60px;
  width:150px; height:150px; border-radius:50%;
  background:rgba(255,255,255,.04);
}
.mc-hero h4 { font-size:20px; font-weight:750; margin:0 0 3px; letter-spacing:-.2px; }
.mc-hero p  { margin:0; font-size:13px; opacity:.78; }
.mc-hero-right {
  background: rgba(255,255,255,.15);
  border: 1px solid rgba(255,255,255,.22);
  border-radius: 999px; padding: 5px 15px;
  font-size: 13px; font-weight: 700;
  display: flex; align-items: center; gap: 7px;
  backdrop-filter: blur(4px); white-space: nowrap;
  position: relative; z-index: 1;
}

/* ── Form card ── */
.mc-form-card {
  background: #fff;
  border: 1px solid var(--mc-border);
  border-radius: var(--mc-r);
  box-shadow: var(--mc-shadow);
  overflow: clip;
  margin-bottom: 22px;
}
.mc-form-head {
  background: #f8fafc;
  border-bottom: 1px solid var(--mc-border);
  padding: 13px 20px;
  display: flex; align-items: center; justify-content: space-between; gap: 10px;
}
.mc-form-head .title {
  font-size: 14px; font-weight: 700; color: #111827;
  display: flex; align-items: center; gap: 8px;
}
.mc-form-head .hint { font-size: 12px; color: #9ca3af; }
.mc-form-body { padding: 22px 24px; }

/* ── Field style ── */
.mc-field { display: flex; flex-direction: column; gap: 5px; }
.mc-field label {
  font-size: 11px; font-weight: 700;
  text-transform: uppercase; letter-spacing: .06em;
  color: #6b7280; margin: 0;
}
.mc-field label .req { color: #dc2626; }
.mc-field .form-control {
  border-radius: 10px;
  border: 1.5px solid rgba(15,23,42,.11);
  font-size: 14px; padding: 9px 12px;
  background: #f9fafb; color: #111827;
  transition: border-color .15s, box-shadow .15s;
}
.mc-field .form-control:focus {
  border-color: var(--mc-blue);
  box-shadow: 0 0 0 3px rgba(37,99,235,.13);
  background: #fff;
}
.mc-field .form-control::placeholder { color: #9ca3af; }
.mc-field-hint { font-size: 11.5px; color: #9ca3af; margin-top: 3px; }

/* ── Form action buttons ── */
.mc-form-actions {
  display: flex; justify-content: flex-end; gap: 10px;
  padding-top: 6px; flex-wrap: wrap;
}
.btn-mc-add {
  background: linear-gradient(135deg, #2563eb, #4f46e5);
  color: #fff; border: none; border-radius: 10px;
  padding: 9px 22px; font-size: 14px; font-weight: 600;
  display: inline-flex; align-items: center; gap: 7px;
  transition: opacity .15s, transform .1s; cursor: pointer;
}
.btn-mc-add:hover { opacity: .88; transform: translateY(-1px); }
.btn-mc-update {
  background: linear-gradient(135deg, #16a34a, #22c55e);
  color: #fff; border: none; border-radius: 10px;
  padding: 9px 22px; font-size: 14px; font-weight: 600;
  display: inline-flex; align-items: center; gap: 7px;
  transition: opacity .15s, transform .1s; cursor: pointer;
}
.btn-mc-update:hover { opacity: .88; transform: translateY(-1px); }
.btn-mc-cancel {
  background: #f1f5f9; color: #374151;
  border: 1.5px solid rgba(15,23,42,.11); border-radius: 10px;
  padding: 9px 18px; font-size: 14px; font-weight: 600;
  display: inline-flex; align-items: center; gap: 7px;
  transition: background .12s; cursor: pointer;
}
.btn-mc-cancel:hover { background: #e2e8f0; }

/* ── Edit mode banner ── */
.mc-edit-banner {
  background: #fffbeb; border: 1px solid #fcd34d;
  border-radius: 10px; padding: 10px 16px;
  font-size: 13.5px; font-weight: 600; color: #92400e;
  display: flex; align-items: center; gap: 8px;
  margin-bottom: 16px;
}
.mc-edit-banner.d-none { display: none !important; }

/* ── Results bar ── */
.mc-results-bar {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 10px; flex-wrap: wrap; gap: 8px;
}
.mc-results-bar .label {
  font-size: 14.5px; font-weight: 800; color: #111827;
  display: flex; align-items: center; gap: 8px;
}
.mc-count-badge {
  background: #eff6ff; color: #1d4ed8;
  border: 1px solid #bfdbfe; border-radius: 999px;
  padding: 2px 10px; font-size: 12px; font-weight: 700;
}
.mc-results-bar .hint { font-size: 12.5px; color: #9ca3af; }

/* ── Table card ── */
.mc-table-card {
  background: #fff;
  border: 1px solid var(--mc-border);
  border-radius: var(--mc-r);
  box-shadow: var(--mc-shadow);
  /* overflow:hidden removed — was killing inner table scroll */
}

/* ── Table scroll container ── */
.mc-table-scroll {
  overflow-y: scroll;
  overflow-x: auto;
  max-height: 72vh;
  scrollbar-width: thin;
  scrollbar-color: rgba(15,23,42,.20) rgba(15,23,42,.05);
  border-radius: 0 0 var(--mc-r) var(--mc-r);
}
.mc-table-scroll::-webkit-scrollbar { width: 7px; height: 7px; }
.mc-table-scroll::-webkit-scrollbar-track { background: rgba(15,23,42,.04); border-radius: 999px; }
.mc-table-scroll::-webkit-scrollbar-thumb { background: rgba(15,23,42,.18); border-radius: 999px; }
.mc-table-scroll::-webkit-scrollbar-thumb:hover { background: rgba(15,23,42,.32); }

/* ── Table ── */
#clinicsTable thead th {
  background: #f8fafc;
  font-size: 11px; font-weight: 800;
  text-transform: uppercase; letter-spacing: .07em;
  color: #64748b; padding: 12px 14px !important;
  border-bottom: 2px solid rgba(15,23,42,.07);
  white-space: nowrap; position: sticky; top: 0; z-index: 5;
}
#clinicsTable tbody td {
  padding: 12px 14px !important;
  font-size: 14px; color: #111827;
  vertical-align: middle;
  border-color: rgba(15,23,42,.05);
}
#clinicsTable tbody tr { transition: background .1s; }
#clinicsTable tbody tr:hover { background: #f0f7ff; }

/* ── ID badge ── */
.id-badge {
  display: inline-block;
  background: #f1f5f9; color: #475569;
  border: 1px solid #cbd5e1; border-radius: 6px;
  padding: 2px 8px; font-size: 12px; font-weight: 700;
  font-family: monospace;
}

/* ── Clinic name ── */
.clinic-name-cell { font-weight: 700; color: #0f172a; font-size: 14px; }
.company-cell { font-size: 13.5px; color: #374151; }
.tax-cell { font-size: 13px; font-family: monospace; color: #374151; font-weight: 600; }

/* ── Contact cells ── */
.phone-cell { font-size: 13.5px; color: #374151; display: flex; align-items: center; gap: 5px; }
.email-cell { font-size: 13.5px; color: #2563eb; font-weight: 500; }
.address-cell { font-size: 13px; color: #374151; max-width: 220px; }

/* ── Empty cell ── */
.empty-val { color: #d1d5db; font-size: 13px; }

/* ── Action buttons ── */
.btn-tbl-edit {
  background: #eff6ff; color: #1d4ed8;
  border: 1px solid #bfdbfe; border-radius: 8px;
  padding: 6px 12px; font-size: 12.5px; font-weight: 600;
  display: inline-flex; align-items: center; gap: 5px;
  transition: background .12s; cursor: pointer; text-decoration: none;
}
.btn-tbl-edit:hover { background: #dbeafe; color: #1e40af; }
.btn-tbl-del {
  background: #fef2f2; color: #dc2626;
  border: 1px solid #fecaca; border-radius: 8px;
  padding: 6px 12px; font-size: 12.5px; font-weight: 600;
  display: inline-flex; align-items: center; gap: 5px;
  transition: background .12s; cursor: pointer; text-decoration: none;
}
.btn-tbl-del:hover { background: #fee2e2; color: #b91c1c; }

/* ── Empty state ── */
.mc-empty { text-align: center; padding: 52px 20px; color: #9ca3af; }
.mc-empty i { font-size: 38px; display: block; margin-bottom: 10px; opacity: .35; }
.mc-empty p { margin: 0; font-size: 14px; }
</style>

<div class="mc-page">

  <!-- Hero -->
  <div class="mc-hero">
    <div style="position:relative;z-index:1;">
      <h4><i class="bi bi-building-check me-2" style="opacity:.85;"></i>Manage Clinics</h4>
      <p>Add, update, and manage clinic billing information</p>
    </div>
    <div class="mc-hero-right">
      <i class="bi bi-shield-lock"></i>
      <?= htmlspecialchars($role) ?>
    </div>
  </div>

  <!-- ── Add / Edit Form ── -->
  <div class="mc-form-card">
    <div class="mc-form-head">
      <span class="title" id="formHeadTitle">
        <i class="bi bi-plus-circle text-primary"></i>
        Add New Clinic
      </span>
      <span class="hint">Fields marked <span style="color:#dc2626;font-weight:800;">*</span> are required</span>
    </div>
    <div class="mc-form-body">

      <!-- Edit mode indicator -->
      <div class="mc-edit-banner d-none" id="editBanner">
        <i class="bi bi-pencil-square"></i>
        <span>Editing clinic: <strong id="editingName">—</strong></span>
      </div>

      <form method="POST" class="row g-3">
        <input type="hidden" name="clinic_id" id="clinic_id">

        <div class="col-md-4">
          <div class="mc-field">
            <label>Clinic Name <span class="req">*</span></label>
            <input type="text" name="name" id="clinic_name" class="form-control"
                   placeholder="e.g. Born IVF Clinic" required>
          </div>
        </div>

        <div class="col-md-4">
          <div class="mc-field">
            <label>Company Name</label>
            <input type="text" name="company_name" id="company_name" class="form-control"
                   placeholder="Legal company name">
            <div class="mc-field-hint">Optional — used on invoices</div>
          </div>
        </div>

        <div class="col-md-4">
          <div class="mc-field">
            <label>Tax ID</label>
            <input type="text" name="tax_id" id="tax_id" class="form-control"
                   placeholder="e.g. 0105565012345">
            <div class="mc-field-hint">Optional — 13-digit Thai tax ID</div>
          </div>
        </div>

        <div class="col-md-3">
          <div class="mc-field">
            <label>Mobile Number</label>
            <input type="text" name="phone" id="clinic_phone" class="form-control"
                   placeholder="e.g. 089-123-4567">
          </div>
        </div>

        <div class="col-md-3">
          <div class="mc-field">
            <label>Email</label>
            <input type="email" name="email" id="clinic_email" class="form-control"
                   placeholder="clinic@example.com">
          </div>
        </div>

        <div class="col-md-6">
          <div class="mc-field">
            <label>Address</label>
            <input type="text" name="address" id="clinic_address" class="form-control"
                   placeholder="Full address">
          </div>
        </div>

        <div class="col-12">
          <div class="mc-form-actions">
            <button class="btn-mc-add" name="add_clinic" id="addBtn" type="submit">
              <i class="bi bi-plus-circle"></i> Add Clinic
            </button>
            <button class="btn-mc-update d-none" name="edit_clinic" id="updateBtn" type="submit">
              <i class="bi bi-floppy"></i> Save Changes
            </button>
            <button class="btn-mc-cancel d-none" type="button" id="cancelBtn" onclick="resetForm()">
              <i class="bi bi-x-circle"></i> Cancel
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- ── Clinic List ── -->
  <?php
    // count total clinics for badge
    $clinics_arr = [];
    while ($r = $clinics->fetch_assoc()) $clinics_arr[] = $r;
  ?>

  <div class="mc-results-bar">
    <div class="label">
      <i class="bi bi-building text-primary"></i>
      Clinic List
      <span class="mc-count-badge"><?= count($clinics_arr) ?></span>
    </div>
    <div class="hint">Click <i class="bi bi-pencil-square" style="font-size:11px;"></i> to edit a clinic</div>
  </div>

  <div class="mc-table-card">
    <div class="mc-table-scroll">
      <table class="table table-hover align-middle mb-0" id="clinicsTable">
        <thead>
          <tr>
            <th style="width:60px;">ID</th>
            <th>Clinic Name</th>
            <th>Company Name</th>
            <th>Tax ID</th>
            <th>Mobile</th>
            <th>Email</th>
            <th>Address</th>
            <th class="text-center" style="width:130px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($clinics_arr)): ?>
            <tr>
              <td colspan="8">
                <div class="mc-empty">
                  <i class="bi bi-building-x"></i>
                  <p>No clinics registered yet. Add your first clinic above.</p>
                </div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($clinics_arr as $row): ?>
              <tr>
                <td><span class="id-badge"><?= (int)$row['id'] ?></span></td>

                <td>
                  <span class="clinic-name-cell">
                    <i class="bi bi-building me-1" style="color:#93c5fd;font-size:13px;"></i>
                    <?= htmlspecialchars($row['name'] ?? '') ?>
                  </span>
                </td>

                <td>
                  <?php if (!empty($row['company_name'])): ?>
                    <span class="company-cell"><?= htmlspecialchars($row['company_name']) ?></span>
                  <?php else: ?>
                    <span class="empty-val">—</span>
                  <?php endif; ?>
                </td>

                <td>
                  <?php if (!empty($row['tax_id'])): ?>
                    <span class="tax-cell"><?= htmlspecialchars($row['tax_id']) ?></span>
                  <?php else: ?>
                    <span class="empty-val">—</span>
                  <?php endif; ?>
                </td>

                <td>
                  <?php if (!empty($row['phone'])): ?>
                    <span class="phone-cell">
                      <i class="bi bi-telephone" style="font-size:12px;color:#6b7280;"></i>
                      <?= htmlspecialchars($row['phone']) ?>
                    </span>
                  <?php else: ?>
                    <span class="empty-val">—</span>
                  <?php endif; ?>
                </td>

                <td>
                  <?php if (!empty($row['email'])): ?>
                    <span class="email-cell">
                      <i class="bi bi-envelope me-1" style="font-size:12px;"></i>
                      <?= htmlspecialchars($row['email']) ?>
                    </span>
                  <?php else: ?>
                    <span class="empty-val">—</span>
                  <?php endif; ?>
                </td>

                <td>
                  <?php if (!empty($row['address'])): ?>
                    <span class="address-cell"><?= htmlspecialchars($row['address']) ?></span>
                  <?php else: ?>
                    <span class="empty-val">—</span>
                  <?php endif; ?>
                </td>

                <td class="text-center">
                  <?php if ($user_role !== 'clinic_user'): ?>
                    <div style="display:flex;justify-content:center;gap:6px;flex-wrap:wrap;">
                      <button type="button"
                              class="btn-tbl-edit"
                              title="Edit clinic"
                              onclick='editClinic(
                                <?= (int)$row["id"] ?>,
                                <?= json_encode($row["name"] ?? "") ?>,
                                <?= json_encode($row["company_name"] ?? "") ?>,
                                <?= json_encode($row["tax_id"] ?? "") ?>,
                                <?= json_encode($row["phone"] ?? "") ?>,
                                <?= json_encode($row["email"] ?? "") ?>,
                                <?= json_encode($row["address"] ?? "") ?>
                              )'>
                        <i class="bi bi-pencil"></i> Edit
                      </button>

                      <?php if ($role === 'superadmin'): ?>
                        <a href="manage_clinics.php?delete_clinic=<?= (int)$row['id'] ?>"
                           class="btn-tbl-del"
                           title="Delete clinic"
                           onclick="return confirm('Are you sure you want to delete this clinic?');">
                          <i class="bi bi-trash3"></i> Del
                        </a>
                      <?php endif; ?>
                    </div>
                  <?php else: ?>
                    <span style="color:#d1d5db;font-size:12px;">No access</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div><!-- /.mc-page -->

<script>
function editClinic(id, name, company_name, tax_id, phone, email, address) {
  document.getElementById('clinic_id').value        = id;
  document.getElementById('clinic_name').value      = name || '';
  document.getElementById('company_name').value     = company_name || '';
  document.getElementById('tax_id').value           = tax_id || '';
  document.getElementById('clinic_phone').value     = phone || '';
  document.getElementById('clinic_email').value     = email || '';
  document.getElementById('clinic_address').value   = address || '';

  // Toggle buttons
  document.getElementById('addBtn').classList.add('d-none');
  document.getElementById('updateBtn').classList.remove('d-none');
  document.getElementById('cancelBtn').classList.remove('d-none');

  // Update form heading
  document.getElementById('formHeadTitle').innerHTML =
    '<i class="bi bi-pencil-square" style="color:#d97706;"></i> Edit Clinic';

  // Show edit banner
  document.getElementById('editBanner').classList.remove('d-none');
  document.getElementById('editingName').textContent = name || '—';

  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function resetForm() {
  document.getElementById('clinic_id').value        = '';
  document.getElementById('clinic_name').value      = '';
  document.getElementById('company_name').value     = '';
  document.getElementById('tax_id').value           = '';
  document.getElementById('clinic_phone').value     = '';
  document.getElementById('clinic_email').value     = '';
  document.getElementById('clinic_address').value   = '';

  document.getElementById('addBtn').classList.remove('d-none');
  document.getElementById('updateBtn').classList.add('d-none');
  document.getElementById('cancelBtn').classList.add('d-none');

  document.getElementById('formHeadTitle').innerHTML =
    '<i class="bi bi-plus-circle" style="color:#2563eb;"></i> Add New Clinic';

  document.getElementById('editBanner').classList.add('d-none');
}
</script>

<?php
ob_end_flush();
include 'includes/footer.php';
?>