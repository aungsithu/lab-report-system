<?php
require 'includes/db.php';
include 'includes/header.php';
require 'includes/session_check.php';

$role = $_SESSION['role'] ?? $_SESSION['user_role'] ?? '';
if (!in_array($role, ['admin', 'superadmin'])) {
    echo "<div class='alert alert-danger m-4'>❌ Access Denied. Only admin or superadmin can access this page.</div>";
    include 'includes/footer.php';
    exit;
}

$message = '';

// Handle Add / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id        = $_POST['user_id'] ?? '';
    $username  = trim($_POST['username']);
    $password  = $_POST['password'] ?? '';
    $rolePost  = $_POST['role'];
    $email     = trim($_POST['email']);
    $clinic_id = ($rolePost === 'admin') ? null : ($_POST['clinic_id'] ?? null);

    if ($username) {
        if ($id) {
            if ($password) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET username=?, password=?, email=?, role=?, clinic_id=? WHERE id=?");
                $stmt->bind_param("ssssii", $username, $hash, $email, $rolePost, $clinic_id, $id);
            } else {
                $stmt = $conn->prepare("UPDATE users SET username=?, email=?, role=?, clinic_id=? WHERE id=?");
                $stmt->bind_param("sssii", $username, $email, $rolePost, $clinic_id, $id);
            }
            $message = "User updated successfully.";
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, email, password, role, clinic_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssi", $username, $email, $hash, $rolePost, $clinic_id);
            $message = "User added successfully.";
        }
        $stmt->execute();
        $stmt->close();
    }
}

// Delete (superadmin only)
if (isset($_GET['delete']) && ($_SESSION['user_role'] ?? '') === 'superadmin') {
    $delete_id = intval($_GET['delete']);
    $conn->query("DELETE FROM users WHERE id = $delete_id");
    header("Location: manage_users.php?msg=deleted");
    exit;
}

$user_role = $_SESSION['user_role'] ?? '';

$users = ($user_role === 'superadmin')
    ? $conn->query("SELECT u.*, c.name as clinic_name FROM users u LEFT JOIN clinics c ON u.clinic_id = c.id ORDER BY u.id DESC")
    : $conn->query("SELECT u.*, c.name as clinic_name FROM users u LEFT JOIN clinics c ON u.clinic_id = c.id WHERE u.role != 'superadmin' ORDER BY u.id DESC");

$clinics = $conn->query("SELECT id, name FROM clinics ORDER BY name");

if (isset($_GET['msg']) && $_GET['msg'] === 'deleted') {
    $message = "User deleted.";
}
?>

<style>
/* ── Tokens ── */
.mu-page {
  --mu-blue:   #2563eb;
  --mu-green:  #16a34a;
  --mu-red:    #dc2626;
  --mu-purple: #7c3aed;
  --mu-amber:  #d97706;
  --mu-border: rgba(15,23,42,.08);
  --mu-shadow: 0 4px 24px rgba(15,23,42,.07);
  --mu-r:      14px;
}

/* ── Hero ── */
.mu-hero {
  background: linear-gradient(120deg, #0f2444 0%, #1d4ed8 55%, #3b82f6 100%);
  border-radius: var(--mu-r);
  padding: 22px 28px; color: #fff;
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; flex-wrap: wrap; overflow: hidden;
  position: relative; margin-bottom: 20px;
}
.mu-hero::before { content:''; position:absolute; right:-40px; top:-40px; width:200px; height:200px; border-radius:50%; background:rgba(255,255,255,.05); }
.mu-hero::after  { content:''; position:absolute; right:80px; bottom:-60px; width:150px; height:150px; border-radius:50%; background:rgba(255,255,255,.04); }
.mu-hero h4 { font-size:20px; font-weight:750; margin:0 0 3px; letter-spacing:-.2px; position:relative; z-index:1; }
.mu-hero p  { margin:0; font-size:13px; opacity:.78; position:relative; z-index:1; }
.mu-hero-right {
  background: rgba(255,255,255,.15); border: 1px solid rgba(255,255,255,.22);
  border-radius: 999px; padding: 5px 15px; font-size: 13px; font-weight: 700;
  display: flex; align-items: center; gap: 7px;
  backdrop-filter: blur(4px); white-space: nowrap; position: relative; z-index: 1;
}

/* ── Alert ── */
.mu-alert-success { background:#f0fdf4; border:1px solid #86efac; border-radius:10px; padding:12px 18px; color:#15803d; font-weight:600; font-size:14px; display:flex; align-items:center; gap:8px; margin-bottom:18px; }
.mu-alert-danger  { background:#fef2f2; border:1px solid #fecaca; border-radius:10px; padding:12px 18px; color:#dc2626; font-weight:600; font-size:14px; display:flex; align-items:center; gap:8px; margin-bottom:18px; }

/* ── Form card ── */
.mu-form-card {
  background: #fff; border: 1px solid var(--mu-border);
  border-radius: var(--mu-r); box-shadow: var(--mu-shadow);
  overflow: clip; margin-bottom: 22px;
}
.mu-form-head {
  background: #f8fafc; border-bottom: 1px solid var(--mu-border);
  padding: 13px 20px;
  display: flex; align-items: center; justify-content: space-between; gap: 10px;
}
.mu-form-head .title { font-size: 14px; font-weight: 700; color: #111827; display: flex; align-items: center; gap: 8px; }
.mu-form-body { padding: 22px 24px; }

/* ── Edit banner ── */
.mu-edit-banner {
  background: #fffbeb; border: 1px solid #fcd34d; border-radius: 10px;
  padding: 10px 16px; font-size: 13.5px; font-weight: 600; color: #92400e;
  display: flex; align-items: center; gap: 8px; margin-bottom: 16px;
}

/* ── Field ── */
.mu-field { display: flex; flex-direction: column; gap: 5px; }
.mu-field label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #6b7280; margin: 0; }
.mu-field label .req { color: #dc2626; }
.mu-field .form-control,
.mu-field .form-select {
  border-radius: 10px; border: 1.5px solid rgba(15,23,42,.11);
  font-size: 14px; padding: 8px 12px; background: #f9fafb; color: #111827;
  transition: border-color .15s, box-shadow .15s;
}
.mu-field .form-control:focus,
.mu-field .form-select:focus {
  border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.13); background: #fff;
}
.mu-field .form-control::placeholder { color: #9ca3af; }
.mu-field .form-control:disabled,
.mu-field .form-select:disabled { background: #f1f5f9; color: #94a3b8; cursor: not-allowed; }
.mu-field-hint { font-size: 11.5px; color: #9ca3af; }

/* ── Buttons ── */
.btn-mu-save {
  background: linear-gradient(135deg, #2563eb, #4f46e5); color: #fff; border: none;
  border-radius: 10px; padding: 9px 24px; font-size: 14px; font-weight: 600;
  display: inline-flex; align-items: center; gap: 7px; cursor: pointer; transition: opacity .15s;
}
.btn-mu-save:hover { opacity: .88; }
.btn-mu-reset {
  background: #f1f5f9; color: #374151; border: 1.5px solid rgba(15,23,42,.11);
  border-radius: 10px; padding: 9px 18px; font-size: 14px; font-weight: 600;
  display: inline-flex; align-items: center; gap: 7px; cursor: pointer; transition: background .12s;
}
.btn-mu-reset:hover { background: #e2e8f0; }

/* ── Results bar ── */
.mu-results-bar {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 10px; flex-wrap: wrap; gap: 8px;
}
.mu-results-bar .label { font-size: 14.5px; font-weight: 800; color: #111827; display: flex; align-items: center; gap: 8px; }
.mu-count-badge { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; border-radius: 999px; padding: 2px 10px; font-size: 12px; font-weight: 700; }

/* ── Table card ── */
.mu-table-card { background: #fff; border: 1px solid var(--mu-border); border-radius: var(--mu-r); box-shadow: var(--mu-shadow); /* overflow:hidden removed */ }
.mu-table-scroll {
  overflow-y: scroll;
  overflow-x: auto;
  max-height: 72vh;
  scrollbar-width: thin;
  scrollbar-color: rgba(15,23,42,.20) rgba(15,23,42,.05);
  border-radius: 0 0 var(--mu-r) var(--mu-r);
}
.mu-table-scroll::-webkit-scrollbar { width: 7px; height: 7px; }
.mu-table-scroll::-webkit-scrollbar-track { background: rgba(15,23,42,.04); border-radius: 999px; }
.mu-table-scroll::-webkit-scrollbar-thumb { background: rgba(15,23,42,.18); border-radius: 999px; }
.mu-table-scroll::-webkit-scrollbar-thumb:hover { background: rgba(15,23,42,.32); }

/* ── Table ── */
#usersTable thead th {
  background: #f8fafc; font-size: 11px; font-weight: 800;
  text-transform: uppercase; letter-spacing: .07em; color: #64748b;
  padding: 12px 16px !important; border-bottom: 2px solid rgba(15,23,42,.07);
  white-space: nowrap; position: sticky; top: 0; z-index: 5;
}
#usersTable tbody td {
  padding: 12px 16px !important; font-size: 14px; color: #111827;
  vertical-align: middle; border-color: rgba(15,23,42,.05);
}
#usersTable tbody tr { transition: background .1s; }
#usersTable tbody tr:hover { background: #f0f7ff; }

/* ── ID badge ── */
.id-badge { display: inline-block; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; border-radius: 6px; padding: 2px 8px; font-size: 12px; font-weight: 700; font-family: monospace; }

/* ── User name cell ── */
.mu-user-cell { display: flex; align-items: center; gap: 10px; }
.mu-avatar { width: 34px; height: 34px; border-radius: 10px; background: linear-gradient(135deg, #3b82f6, #6366f1); display: grid; place-items: center; font-size: 12px; font-weight: 800; color: #fff; flex-shrink: 0; letter-spacing: .03em; }
.mu-avatar.sa { background: linear-gradient(135deg, #dc2626, #f97316); }
.mu-avatar.ad { background: linear-gradient(135deg, #2563eb, #4f46e5); }
.mu-avatar.cu { background: linear-gradient(135deg, #16a34a, #22c55e); }
.mu-username { font-size: 14px; font-weight: 700; color: #0f172a; line-height: 1.2; }
.mu-email-small { font-size: 12px; color: #9ca3af; }

/* ── Email cell ── */
.mu-email-cell { font-size: 13.5px; color: #374151; }

/* ── Role badge ── */
.mu-role-badge {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 4px 11px; border-radius: 999px; font-size: 12px; font-weight: 700; white-space: nowrap;
}
.mu-role-sa { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
.mu-role-ad { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
.mu-role-cu { background: #f0fdf4; color: #15803d; border: 1px solid #86efac; }

/* ── Clinic tag ── */
.mu-clinic-tag { display: inline-flex; align-items: center; gap: 5px; background: #f0f9ff; color: #0369a1; border: 1px solid #bae6fd; border-radius: 7px; padding: 3px 10px; font-size: 13px; font-weight: 600; }
.mu-no-clinic { color: #d1d5db; font-size: 13px; }

/* ── Action buttons ── */
.btn-tbl-edit { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; border-radius: 8px; padding: 6px 13px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; cursor: pointer; transition: background .12s; }
.btn-tbl-edit:hover { background: #dbeafe; }
.btn-tbl-del  { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; border-radius: 8px; padding: 6px 13px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; text-decoration: none; transition: background .12s; }
.btn-tbl-del:hover { background: #fee2e2; color: #b91c1c; }

/* ── Empty state ── */
.mu-empty { text-align: center; padding: 52px 20px; color: #9ca3af; }
.mu-empty i { font-size: 36px; display: block; margin-bottom: 10px; opacity: .35; }
.mu-empty p { margin: 0; font-size: 14px; }
</style>

<div class="mu-page">

  <!-- Hero -->
  <div class="mu-hero">
    <div>
      <h4><i class="bi bi-people-fill me-2" style="opacity:.85;"></i>Manage Users</h4>
      <p>Create, update, and assign clinics to system users</p>
    </div>
    <div class="mu-hero-right">
      <i class="bi bi-shield-lock"></i>
      <?= htmlspecialchars($role) ?>
    </div>
  </div>

  <!-- Alert -->
  <?php if (!empty($message)): ?>
    <?php $is_del = str_contains($message, 'deleted') || str_contains($message, 'deleted'); ?>
    <div class="<?= $is_del ? 'mu-alert-danger' : 'mu-alert-success' ?>">
      <i class="bi bi-<?= $is_del ? 'trash3' : 'check-circle-fill' ?>"></i>
      <?= htmlspecialchars($message) ?>
    </div>
  <?php endif; ?>

  <!-- ── Form Card ── -->
  <div class="mu-form-card">
    <div class="mu-form-head">
      <span class="title" id="formHeadTitle">
        <i class="bi bi-person-plus text-primary"></i>
        Add New User
      </span>
      <button type="button" class="btn-mu-reset" onclick="resetForm()">
        <i class="bi bi-arrow-counterclockwise"></i> Reset
      </button>
    </div>
    <div class="mu-form-body">

      <!-- Edit banner -->
      <div class="mu-edit-banner d-none" id="editBanner">
        <i class="bi bi-pencil-square"></i>
        <span>Editing user: <strong id="editingUsername">—</strong></span>
      </div>

      <form method="POST" class="row g-3">
        <input type="hidden" name="user_id" id="user_id">

        <div class="col-md-3">
          <div class="mu-field">
            <label>Username <span class="req">*</span></label>
            <input type="text" name="username" id="username" class="form-control"
                   placeholder="e.g. clinic_admin" required>
          </div>
        </div>

        <div class="col-md-3">
          <div class="mu-field">
            <label>Email <span class="req">*</span></label>
            <input type="email" name="email" id="email" class="form-control"
                   placeholder="e.g. user@clinic.com" required>
          </div>
        </div>

        <div class="col-md-2">
          <div class="mu-field">
            <label>Password</label>
            <input type="password" name="password" id="password" class="form-control"
                   placeholder="Leave blank to keep">
            <div class="mu-field-hint">Only fill when changing</div>
          </div>
        </div>

        <div class="col-md-2">
          <div class="mu-field">
            <label>Role <span class="req">*</span></label>
            <select name="role" id="role" class="form-select" required onchange="toggleClinicDropdown()">
              <option value="admin">Admin</option>
              <option value="clinic_user">Clinic User</option>
            </select>
          </div>
        </div>

        <div class="col-md-2">
          <div class="mu-field">
            <label>Clinic</label>
            <select name="clinic_id" id="clinic_id" class="form-select">
              <option value="">— No Clinic (Admin) —</option>
              <?php while ($c = $clinics->fetch_assoc()): ?>
                <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
              <?php endwhile; ?>
            </select>
            <div class="mu-field-hint" id="clinicHint">Required for Clinic User</div>
          </div>
        </div>

        <div class="col-12">
          <div style="display:flex; justify-content:flex-end; padding-top:4px;">
            <button class="btn-mu-save" type="submit">
              <i class="bi bi-floppy"></i> Save User
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- ── User List ── -->
  <?php
    // count for badge
    $user_count = $users->num_rows;
  ?>
  <div class="mu-results-bar">
    <div class="label">
      <i class="bi bi-people text-primary"></i>
      User List
      <span class="mu-count-badge"><?= $user_count ?></span>
    </div>
    <div style="font-size:12.5px;color:#9ca3af;">Click <i class="bi bi-pencil" style="font-size:11px;"></i> to edit a user</div>
  </div>

  <div class="mu-table-card">
    <div class="mu-table-scroll">
      <table class="table table-hover align-middle mb-0" id="usersTable">
        <thead>
          <tr>
            <th style="width:60px;">ID</th>
            <th>User</th>
            <th>Email</th>
            <th style="width:140px;">Role</th>
            <th>Clinic</th>
            <th class="text-center" style="width:140px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($user_count === 0): ?>
            <tr>
              <td colspan="6">
                <div class="mu-empty">
                  <i class="bi bi-people-x"></i>
                  <p>No users found.</p>
                </div>
              </td>
            </tr>
          <?php else: ?>
            <?php while ($row = $users->fetch_assoc()):
              $init     = strtoupper(substr($row['username'], 0, 2));
              $r        = strtolower($row['role'] ?? '');
              $avClass  = match($r) { 'superadmin' => 'sa', 'admin' => 'ad', default => 'cu' };
              $roleCls  = match($r) { 'superadmin' => 'mu-role-sa', 'admin' => 'mu-role-ad', default => 'mu-role-cu' };
              $roleLabel = match($r) { 'superadmin' => 'Superadmin', 'admin' => 'Admin', default => 'Clinic User' };
              $cid = $row['clinic_id'] ?? '';
            ?>
              <tr>
                <td><span class="id-badge"><?= (int)$row['id'] ?></span></td>

                <td>
                  <div class="mu-user-cell">
                    <div class="mu-avatar <?= $avClass ?>"><?= htmlspecialchars($init) ?></div>
                    <div>
                      <div class="mu-username"><?= htmlspecialchars($row['username']) ?></div>
                    </div>
                  </div>
                </td>

                <td>
                  <span class="mu-email-cell">
                    <i class="bi bi-envelope me-1" style="font-size:12px;opacity:.45;"></i>
                    <?= htmlspecialchars($row['email'] ?? '') ?>
                  </span>
                </td>

                <td>
                  <span class="mu-role-badge <?= $roleCls ?>">
                    <i class="bi bi-shield-lock" style="font-size:11px;"></i>
                    <?= $roleLabel ?>
                  </span>
                </td>

                <td>
                  <?php if (!empty($row['clinic_name'])): ?>
                    <span class="mu-clinic-tag">
                      <i class="bi bi-building" style="font-size:12px;"></i>
                      <?= htmlspecialchars($row['clinic_name']) ?>
                    </span>
                  <?php else: ?>
                    <span class="mu-no-clinic">—</span>
                  <?php endif; ?>
                </td>

                <td class="text-center">
                  <div style="display:flex;justify-content:center;gap:6px;flex-wrap:wrap;">
                    <button type="button"
                            class="btn-tbl-edit"
                            data-id="<?= (int)$row['id'] ?>"
                            data-username="<?= htmlspecialchars($row['username'], ENT_QUOTES) ?>"
                            data-email="<?= htmlspecialchars($row['email'], ENT_QUOTES) ?>"
                            data-role="<?= htmlspecialchars($row['role'], ENT_QUOTES) ?>"
                            data-clinic="<?= htmlspecialchars((string)$cid, ENT_QUOTES) ?>"
                            onclick="editUserFromBtn(this)"
                            title="Edit user">
                      <i class="bi bi-pencil"></i> Edit
                    </button>

                    <?php if (($_SESSION['user_role'] ?? '') === 'superadmin'): ?>
                      <a href="?delete=<?= (int)$row['id'] ?>"
                         class="btn-tbl-del"
                         onclick="return confirm('Delete user <?= htmlspecialchars($row['username'], ENT_QUOTES) ?>? This cannot be undone.');"
                         title="Delete user">
                        <i class="bi bi-trash3"></i> Del
                      </a>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div><!-- /.mu-page -->

<script>
function editUserFromBtn(btn) {
  document.getElementById('user_id').value   = btn.dataset.id       || '';
  document.getElementById('username').value  = btn.dataset.username || '';
  document.getElementById('email').value     = btn.dataset.email    || '';
  document.getElementById('role').value      = btn.dataset.role     || 'admin';
  document.getElementById('clinic_id').value = btn.dataset.clinic   || '';
  document.getElementById('password').value  = '';

  toggleClinicDropdown();

  // Update form heading
  document.getElementById('formHeadTitle').innerHTML =
    '<i class="bi bi-pencil-square" style="color:#d97706;"></i> Edit User';

  // Show edit banner
  document.getElementById('editBanner').classList.remove('d-none');
  document.getElementById('editingUsername').textContent = btn.dataset.username || '—';

  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function toggleClinicDropdown() {
  const role        = document.getElementById('role').value;
  const clinicSelect = document.getElementById('clinic_id');
  const clinicHint  = document.getElementById('clinicHint');
  clinicSelect.disabled = (role === 'admin');
  if (role === 'admin') {
    clinicSelect.value = '';
    if (clinicHint) clinicHint.textContent = 'Not applicable for Admin';
  } else {
    if (clinicHint) clinicHint.textContent = 'Required for Clinic User';
  }
}
toggleClinicDropdown();

function resetForm() {
  document.getElementById('user_id').value   = '';
  document.getElementById('username').value  = '';
  document.getElementById('email').value     = '';
  document.getElementById('password').value  = '';
  document.getElementById('role').value      = 'admin';
  document.getElementById('clinic_id').value = '';

  document.getElementById('formHeadTitle').innerHTML =
    '<i class="bi bi-person-plus" style="color:#2563eb;"></i> Add New User';
  document.getElementById('editBanner').classList.add('d-none');

  toggleClinicDropdown();
}
</script>

<?php include 'includes/footer.php'; ?>