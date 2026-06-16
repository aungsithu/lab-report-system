<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require 'includes/db.php';
include 'includes/header.php';
require 'includes/session_check.php';

// Get all clinics
$clinics = $conn->query("SELECT id, name FROM clinics ORDER BY name ASC");
$selected_clinic_id = $_GET['clinic_id'] ?? null;

// Fetch test names for autocomplete
$test_names = [];
$res = $conn->query("SELECT DISTINCT name FROM lab_tests ORDER BY name ASC");
while ($r = $res->fetch_assoc()) {
    $test_names[] = $r['name'];
}

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clinic_id'], $_POST['tests'])) {
    $clinic_id = intval($_POST['clinic_id']);
    $create_new_invoice = isset($_POST['create_new_invoice']);

    // Invoice logic
    $today = date('Y-m-d');
    $prefix = 'CELTAC-' . date('ymd');
    $invoice_id = null;

    if ($create_new_invoice) {
        $stmt_count = $conn->prepare("SELECT COUNT(*) AS total FROM invoices WHERE DATE(created_at) = ?");
        $stmt_count->bind_param("s", $today);
        $stmt_count->execute();
        $count_result = $stmt_count->get_result()->fetch_assoc();
        $seq = str_pad($count_result['total'] + 1, 3, '0', STR_PAD_LEFT);
        $invoice_number = $prefix . '-' . $seq;

        $stmt_save = $conn->prepare("INSERT INTO invoices (invoice_number, clinic_id, created_at) VALUES (?, ?, NOW())");
        $stmt_save->bind_param("si", $invoice_number, $clinic_id);
        $stmt_save->execute();
        $invoice_id = $stmt_save->insert_id;
    } else {
        $stmt_check = $conn->prepare("SELECT id FROM invoices WHERE clinic_id = ? AND DATE(created_at) = ? ORDER BY id DESC LIMIT 1");
        $stmt_check->bind_param("is", $clinic_id, $today);
        $stmt_check->execute();
        $stmt_check->bind_result($invoice_id);
        $stmt_check->fetch();
        $stmt_check->close();

        // ❌ Still no invoice even after checking → return silently (failsafe)
        if (!$invoice_id) {
            echo "<div class='alert alert-danger'>❌ No invoice found for today and 'Create New Invoice' not checked.</div>";
            return;
        }
    }


    // Save test prices with invoice_id
    $seen_tests = []; // ← prevent duplicate test names within one submission

    foreach ($_POST['tests'] as $row) {
        $test_name = trim($row['test_name']);
        $price = floatval($row['price']);
        $discount = floatval($row['discount']);

        if ($test_name === '') continue;

        // Avoid duplicate insert attempt
        $key = $clinic_id . '-' . $invoice_id . '-' . strtolower($test_name);
        if (isset($seen_tests[$key])) continue;
        $seen_tests[$key] = true;

        // Check if test already exists
        $check = $conn->prepare("SELECT id FROM test_prices WHERE clinic_id = ? AND invoice_id = ? AND test_name = ?");
        $check->bind_param("iis", $clinic_id, $invoice_id, $test_name);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            // Exists → update it
            $update = $conn->prepare("UPDATE test_prices SET price = ?, discount_percent = ? WHERE clinic_id = ? AND invoice_id = ? AND test_name = ?");
            $update->bind_param("ddiis", $price, $discount, $clinic_id, $invoice_id, $test_name);
            $update->execute();
        } else {
            // Doesn't exist → insert
            $insert = $conn->prepare("INSERT INTO test_prices (clinic_id, invoice_id, test_name, price, discount_percent) VALUES (?, ?, ?, ?, ?)");
            $insert->bind_param("iisdd", $clinic_id, $invoice_id, $test_name, $price, $discount);
            $insert->execute();
        }
    }


    echo "<div class='alert alert-success'>✅ Prices saved successfully.</div>";
}
?>

<div class="container mt-4">
  <h4>💰 Manage Test Prices</h4>
  <p class="text-muted">กรุณาเลือกคลินิกจากด้านล่างเพื่อจัดการราคาการตรวจ เมื่อต้นบันทึกแล้ว ระบบจะสร้างใบแจ้งหนี้โดยอัตโนมัติ และสามารถถูได้ในรายการใบแจ้งหนี้</p>

  <form method="GET" class="mb-3">
    <label>Select Clinic:</label>
    <select name="clinic_id" onchange="this.form.submit()" class="form-control" required>
      <option value="">-- Choose Clinic --</option>
      <?php while ($row = $clinics->fetch_assoc()): ?>
        <option value="<?= $row['id'] ?>" <?= ($selected_clinic_id == $row['id']) ? 'selected' : '' ?>>
          <?= htmlspecialchars($row['name']) ?>
        </option>
      <?php endwhile; ?>
    </select>
  </form>

  <?php if ($selected_clinic_id): ?>
  <form method="POST" onsubmit="return confirmInvoice(event);">
    <input type="hidden" name="clinic_id" value="<?= $selected_clinic_id ?>">

    <div class="mb-3">
      <input type="text" id="test_search" class="form-control" placeholder="Search test name...">
      <div class="mt-2">
        <button type="button" class="btn btn-success btn-sm" onclick="addTestRow()">+ Add Test</button>
        <label class="ms-3"><input type="checkbox" name="create_new_invoice" id="create_new_invoice"> Create New Invoice</label>
      </div>
    </div>

    <table class="table table-bordered" id="testTable">
      <thead class="table-light">
        <tr><th>Test Name</th><th>Price (฿)</th><th>Discount (%)</th><th>Action</th></tr>
      </thead>
      <tbody></tbody>
    </table>

    <button type="submit" class="btn btn-primary">📂 Save Invoice</button>
    <a href="invoice_list.php?clinic_id=<?= $selected_clinic_id ?>" class="btn btn-secondary">📄 See Invoice List</a>
  </form>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const testNames = <?= json_encode($test_names) ?>;

function addTestRow() {
  const val = document.getElementById('test_search').value.trim();
  if (!val) return;

  const normalized = val.toLowerCase();
  const exists = [...document.querySelectorAll('input[name^="tests"]')]
    .some(el => el.value.trim().toLowerCase() === normalized);
  if (exists) return;

  const tbody = document.querySelector('#testTable tbody');
  const index = tbody.querySelectorAll('tr').length;

  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td><input type="text" name="tests[${index}][test_name]" class="form-control" value="${val}" readonly></td>
    <td><input type="number" step="0.01" name="tests[${index}][price]" class="form-control" required></td>
    <td><input type="number" step="0.01" name="tests[${index}][discount]" class="form-control"></td>
    <td><button type="button" class="btn btn-danger btn-sm" onclick="this.closest('tr').remove()">Delete</button></td>
  `;
  tbody.appendChild(tr);
  document.getElementById('test_search').value = '';
}

// ✅ JS validation before submit
async function confirmInvoice(event) {
  event.preventDefault();

  const isNew = document.getElementById('create_new_invoice').checked;
  const clinicId = document.querySelector('input[name="clinic_id"]').value;

  if (isNew) {
    event.target.submit(); return false;
  }

  const res = await fetch(`check_invoice.php?clinic_id=${clinicId}`);
  const data = await res.json();

  if (data.exists) {
    event.target.submit(); return false;
  } else {
    await Swal.fire({
      icon: 'error',
      title: 'No Previous Invoice Found!',
      text: 'You didn\'t check Create New Invoice, and no existing invoice was found for today.'
    });
    return false;
  }
}

const input = document.getElementById('test_search');
input.addEventListener('input', () => {
  const list = testNames.filter(t => t.toLowerCase().includes(input.value.toLowerCase()));
  input.setAttribute('list', 'autocomplete');
  const datalist = document.getElementById('autocomplete') || document.createElement('datalist');
  datalist.id = 'autocomplete';
  datalist.innerHTML = list.map(v => `<option value="${v}">`).join('');
  document.body.appendChild(datalist);
});
</script>

<?php include 'includes/footer.php'; ?>
