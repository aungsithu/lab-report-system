<?php
include 'includes/header.php';
require 'includes/db.php';
require 'includes/session_check.php';

if (($_SESSION['role'] ?? $_SESSION['user_role'] ?? '') === 'clinic_user') {
    echo "<div class='alert alert-danger m-4'>Access denied.</div>";
    include 'includes/footer.php';
    exit;
}

$message     = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name          = $_POST['name']      ?? '';
    $hn            = $_POST['hn']        ?? '';
    $ln            = $_POST['ln']        ?? '';
    $sex           = $_POST['sex']       ?? '';
    $age           = $_POST['age']       ?? '';
    $dob           = $_POST['dob']       ?? '';
    $doctor        = $_POST['doctor']    ?? '';
    $clinic_id     = $_POST['clinic_id'] ?? '';
    $register_date = date('Y-m-d');

    $stmt = $conn->prepare("INSERT INTO patients (name, hn, ln, sex, age, dob, doctor, clinic_id, register_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssssis", $name, $hn, $ln, $sex, $age, $dob, $doctor, $clinic_id, $register_date);
    $stmt->execute();
    $patient_id = $conn->insert_id;
    $stmt->close();

    if (!empty($_POST['test_names']) && is_array($_POST['test_names'])) {
        foreach ($_POST['test_names'] as $test) {
            if (empty($clinic_id)) {
                $message     = "Please select a clinic.";
                $messageType = "danger";
                break;
            }
            $stmt = $conn->prepare("INSERT INTO lab_results (patient_id, test_name) VALUES (?, ?)");
            $stmt->bind_param("is", $patient_id, $test);
            $stmt->execute();
            $stmt->close();
        }
    }

    if ($message === '') {
        $message     = "Patient added successfully!";
        $messageType = "success";
    }
}
?>

<style>
/* ── Tokens ── */
.ap-page {
  --ap-blue:   #2563eb;
  --ap-green:  #16a34a;
  --ap-red:    #dc2626;
  --ap-border: rgba(15,23,42,.08);
  --ap-shadow: 0 4px 24px rgba(15,23,42,.07);
  --ap-r:      14px;
}

/* ── Hero ── */
.ap-hero {
  background: linear-gradient(120deg, #0f2444 0%, #1d4ed8 55%, #3b82f6 100%);
  border-radius: var(--ap-r);
  padding: 22px 28px; color: #fff;
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; flex-wrap: wrap; overflow: hidden;
  position: relative; margin-bottom: 20px;
}
.ap-hero::before { content:''; position:absolute; right:-40px; top:-40px; width:200px; height:200px; border-radius:50%; background:rgba(255,255,255,.05); }
.ap-hero::after  { content:''; position:absolute; right:80px; bottom:-60px; width:150px; height:150px; border-radius:50%; background:rgba(255,255,255,.04); }
.ap-hero h4 { font-size:20px; font-weight:750; margin:0 0 3px; letter-spacing:-.2px; position:relative; z-index:1; }
.ap-hero p  { margin:0; font-size:13px; opacity:.78; position:relative; z-index:1; }
.ap-hero-date {
  background: rgba(255,255,255,.15); border: 1px solid rgba(255,255,255,.22);
  border-radius: 10px; padding: 7px 16px; font-size: 13px; font-weight: 600;
  display: flex; align-items: center; gap: 7px;
  backdrop-filter: blur(4px); white-space: nowrap; position: relative; z-index: 1;
}

/* ── Alert ── */
.ap-alert-success { background:#f0fdf4; border:1px solid #86efac; border-radius:10px; padding:13px 18px; color:#15803d; font-weight:600; font-size:14px; display:flex; align-items:center; gap:9px; margin-bottom:18px; }
.ap-alert-danger  { background:#fef2f2; border:1px solid #fecaca; border-radius:10px; padding:13px 18px; color:#dc2626; font-weight:600; font-size:14px; display:flex; align-items:center; gap:9px; margin-bottom:18px; }

/* ── Form card ── */
.ap-card {
  background: #fff; border: 1px solid var(--ap-border);
  border-radius: var(--ap-r); box-shadow: var(--ap-shadow); overflow: hidden;
}
.ap-card-head {
  background: #f8fafc; border-bottom: 1px solid var(--ap-border);
  padding: 14px 22px;
  display: flex; align-items: center; justify-content: space-between; gap: 10px;
}
.ap-card-head .title { font-size: 14.5px; font-weight: 700; color: #111827; display: flex; align-items: center; gap: 8px; }
.ap-card-head .hint  { font-size: 12.5px; color: #9ca3af; display: flex; align-items: center; gap: 5px; }
.ap-card-body { padding: 24px 26px; }

/* ── Section divider ── */
.ap-section {
  font-size: 11px; font-weight: 800; text-transform: uppercase;
  letter-spacing: .10em; color: #9ca3af;
  display: flex; align-items: center; gap: 10px;
  margin: 6px 0 16px;
}
.ap-section::after { content:''; flex:1; height:1px; background: rgba(15,23,42,.07); }

/* ── Field ── */
.ap-field { display: flex; flex-direction: column; gap: 5px; }
.ap-field label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #6b7280; margin: 0; }
.ap-field label .req { color: #dc2626; }
.ap-field .form-control,
.ap-field .form-select {
  border-radius: 10px; border: 1.5px solid rgba(15,23,42,.11);
  font-size: 14px; padding: 9px 13px; background: #f9fafb; color: #111827;
  transition: border-color .15s, box-shadow .15s;
}
.ap-field .form-control:focus,
.ap-field .form-select:focus {
  border-color: var(--ap-blue); box-shadow: 0 0 0 3px rgba(37,99,235,.13); background: #fff;
}
.ap-field .form-control::placeholder { color: #9ca3af; }
.ap-field select option[value=""][disabled] { color: #9ca3af; }
.ap-field-hint { font-size: 11.5px; color: #9ca3af; margin-top: 3px; }

/* ── Input with icon ── */
.ap-input-wrap { position: relative; }
.ap-input-wrap i { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: #9ca3af; font-size: 14px; pointer-events: none; }
.ap-input-wrap .form-control { padding-left: 34px; }

/* ── Required badge ── */
.ap-req-note {
  font-size: 12px; color: #9ca3af; display: flex; align-items: center; gap: 4px;
  margin-bottom: 18px;
}
.ap-req-note span { color: #dc2626; font-weight: 800; }

/* ── Submit button ── */
.btn-ap-save {
  background: linear-gradient(135deg, #2563eb, #4f46e5); color: #fff; border: none;
  border-radius: 10px; padding: 11px 28px; font-size: 15px; font-weight: 700;
  display: inline-flex; align-items: center; gap: 8px; cursor: pointer; transition: opacity .15s;
  box-shadow: 0 4px 14px rgba(37,99,235,.3);
}
.btn-ap-save:hover { opacity: .88; }
</style>

<div class="ap-page">

  <!-- Hero -->
  <div class="ap-hero">
    <div>
      <h4><i class="bi bi-person-plus-fill me-2" style="opacity:.85;"></i>Add New Patient</h4>
      <p>Register patient information and optionally attach lab tests</p>
    </div>
    <div class="ap-hero-date">
      <i class="bi bi-calendar3" style="font-size:13px;"></i>
      <?= date('Y-m-d') ?>
    </div>
  </div>

  <!-- Alert -->
  <?php if (!empty($message)): ?>
    <div class="<?= $messageType === 'success' ? 'ap-alert-success' : 'ap-alert-danger' ?>">
      <i class="bi bi-<?= $messageType === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>"></i>
      <?= htmlspecialchars($message) ?>
    </div>
  <?php endif; ?>

  <!-- Form -->
  <div class="ap-card">
    <div class="ap-card-head">
      <span class="title">
        <i class="bi bi-clipboard2-pulse text-primary"></i>
        Patient Details
      </span>
      <span class="hint">
        <span style="color:#dc2626;font-weight:800;">*</span> Required fields
      </span>
    </div>

    <div class="ap-card-body">
      <form method="POST" autocomplete="off">

        <!-- ── Identity ── -->
        <div class="ap-section">Identity</div>
        <div class="row g-3 mb-4">

          <div class="col-md-6">
            <div class="ap-field">
              <label>Patient Name <span class="req">*</span></label>
              <div class="ap-input-wrap">
                <i class="bi bi-person"></i>
                <input name="name" class="form-control" placeholder="e.g. Somchai Jaidee" required>
              </div>
            </div>
          </div>

          <div class="col-md-3">
            <div class="ap-field">
              <label>HN <span class="req">*</span></label>
              <div class="ap-input-wrap">
                <i class="bi bi-hash"></i>
                <input name="hn" class="form-control" placeholder="e.g. 26/65" required>
              </div>
            </div>
          </div>

          <div class="col-md-3">
            <div class="ap-field">
              <label>LN <span class="req">*</span></label>
              <div class="ap-input-wrap">
                <i class="bi bi-upc"></i>
                <input name="ln" class="form-control" placeholder="e.g. L05101" required>
              </div>
            </div>
          </div>

          <div class="col-md-3">
            <div class="ap-field">
              <label>Gender <span class="req">*</span></label>
              <select name="sex" class="form-select" required>
                <option value="" disabled selected>— Select Gender —</option>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
                <option value="Other">Other</option>
              </select>
            </div>
          </div>

          <div class="col-md-3">
            <div class="ap-field">
              <label>Age</label>
              <div class="ap-input-wrap">
                <i class="bi bi-123"></i>
                <input name="age" class="form-control" placeholder="e.g. 45">
              </div>
              <div class="ap-field-hint">Optional</div>
            </div>
          </div>

          <div class="col-md-6">
            <div class="ap-field">
              <label>Date of Birth</label>
              <div class="ap-input-wrap">
                <i class="bi bi-calendar-heart"></i>
                <input name="dob" class="form-control" type="date" style="padding-left:34px;">
              </div>
              <div class="ap-field-hint">Optional</div>
            </div>
          </div>

        </div>

        <!-- ── Clinical ── -->
        <div class="ap-section">Clinical Info</div>
        <div class="row g-3 mb-4">

          <div class="col-md-6">
            <div class="ap-field">
              <label>Doctor</label>
              <div class="ap-input-wrap">
                <i class="bi bi-person-badge"></i>
                <input name="doctor" class="form-control" placeholder="e.g. Dr. Nattaporn Wongdee">
              </div>
              <div class="ap-field-hint">Optional</div>
            </div>
          </div>

          <div class="col-md-6">
            <div class="ap-field">
              <label>Clinic <span class="req">*</span></label>
              <select name="clinic_id" id="clinic_id" class="form-select" required>
                <option value="" disabled selected>— Select Clinic —</option>
                <?php
                  $res = $conn->query("SELECT * FROM clinics ORDER BY name");
                  while ($c = $res->fetch_assoc()):
                ?>
                  <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endwhile; ?>
              </select>
            </div>
          </div>

        </div>

        <!-- hidden test input kept for compatibility -->
        <input type="hidden" name="test_names[]" id="testInputTemplate" disabled>

        <!-- Submit -->
        <div style="display:flex; justify-content:flex-end; padding-top:8px; border-top:1px solid rgba(15,23,42,.06); margin-top:4px;">
          <button class="btn-ap-save" type="submit">
            <i class="bi bi-floppy"></i> Save Patient
          </button>
        </div>

      </form>
    </div>
  </div>

</div><!-- /.ap-page -->

<script>
let testNames = [];

const testSearchEl = document.getElementById('testSearch');
if (testSearchEl) {
  testSearchEl.addEventListener('input', function () {
    let query = this.value.trim();
    if (query.length === 0) return document.getElementById('suggestions').innerHTML = '';
    fetch('fetch_tests_by_group.php?q=' + encodeURIComponent(query))
      .then(res => res.json())
      .then(data => {
        let suggestions = '';
        data.forEach(name => {
          suggestions += `<button type="button" class="list-group-item list-group-item-action" onclick="addTest(${JSON.stringify(name)})">${name}</button>`;
        });
        document.getElementById('suggestions').innerHTML = suggestions;
      });
  });

  window.addTest = function (name) {
    if (testNames.includes(name)) return;
    const tbody = document.querySelector('#selectedTests tbody');
    const row   = document.createElement('tr');
    row.innerHTML = `
      <td>
        <input type="hidden" name="test_names[]" value="${String(name).replace(/"/g,'&quot;')}">
        ${name}
      </td>
      <td class="text-center">
        <button type="button" class="btn btn-sm btn-outline-danger" style="border-radius:8px;"
          onclick="this.closest('tr').remove(); testNames = testNames.filter(t => t !== ${JSON.stringify(name)})">
          <i class="bi bi-x-lg"></i>
        </button>
      </td>
    `;
    tbody.appendChild(row);
    testNames.push(name);
    document.getElementById('testSearch').value      = '';
    document.getElementById('suggestions').innerHTML = '';
  };
}
</script>

<?php include 'includes/footer.php'; ?>