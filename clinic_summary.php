<?php
// clinic_summary.php
session_start();
require 'includes/db.php';
include 'includes/header.php';
require 'includes/session_check.php';
date_default_timezone_set('Asia/Bangkok');

// ---- ROLE CHECK: only superadmin + admin ----
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['superadmin', 'admin'])) {
    die('Access denied.');
}

// ---- Load clinics for dropdown ----
$clinics = [];
$resClinics = $conn->query("SELECT id, name FROM clinics ORDER BY name");
while ($row = $resClinics->fetch_assoc()) {
    $clinics[] = $row;
}

// ---- Filters ----
$selectedClinic = isset($_GET['clinic_id']) ? (int)$_GET['clinic_id'] : 0;
$mode           = isset($_GET['mode']) ? $_GET['mode'] : 'month'; // month | year
$selectedMonth  = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selectedYear   = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Support Thai year (e.g. 2567) -> convert to Gregorian
$queryYear = $selectedYear > 2500 ? $selectedYear - 543 : $selectedYear;

if ($mode === 'year') {
    $startDate = sprintf('%04d-01-01', $queryYear);
    $endDate   = sprintf('%04d-12-31', $queryYear);
} else {
    $startDate = sprintf('%04d-%02d-01', $queryYear, $selectedMonth);
    $endDate   = date('Y-m-t', strtotime($startDate));
}

$rows       = [];
$grandTotal = 0;

if ($selectedClinic > 0) {
    /**
     * THIS QUERY JOINS PATIENTS + LAB_RESULTS + IMPORTED_PRICES
     *
     * patients       p  -> clinic_id, register_date, hn, name, ln
     * lab_results    lr -> patient_id, test_name, excel_order
     * imported_prices ip -> test_name, price
     */
    $sql = "
        SELECT
            p.id              AS patient_id,
            p.hn              AS lab_no,
            p.name            AS first_name,
            p.ln              AS last_name,
            DATE(p.register_date) AS service_date,
            lr.test_name      AS test_name,
            COALESCE(ip.price, 0) AS price
        FROM patients p
        INNER JOIN lab_results lr 
            ON lr.patient_id = p.id
        LEFT JOIN imported_prices ip 
            ON ip.test_name = lr.test_name
        WHERE p.clinic_id = ?
          AND DATE(p.register_date) BETWEEN ? AND ?
        ORDER BY p.register_date ASC,
                 p.id ASC,
                 lr.excel_order ASC,
                 lr.test_name ASC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iss', $selectedClinic, $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($r = $result->fetch_assoc()) {
        $rows[]      = $r;
        $grandTotal += (float)$r['price'];
    }
    $stmt->close();
}
?>

<div class="container py-4">
    <h3 class="mb-4">Clinic Summary Report</h3>

    <!-- Filter Form -->
    <form class="row g-3 mb-4" method="get" action="">
        <div class="col-md-3">
            <label class="form-label">Clinic</label>
            <select name="clinic_id" class="form-select" required>
                <option value="">-- Select clinic --</option>
                <?php foreach ($clinics as $c): ?>
                    <option value="<?php echo $c['id']; ?>"
                        <?php echo ($c['id'] == $selectedClinic) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($c['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-3">
            <label class="form-label">Mode</label>
            <select name="mode" class="form-select" onchange="this.form.submit()">
                <option value="month" <?php echo $mode === 'month' ? 'selected' : ''; ?>>Monthly</option>
                <option value="year"  <?php echo $mode === 'year'  ? 'selected' : ''; ?>>Yearly</option>
            </select>
        </div>

        <?php if ($mode === 'month'): ?>
            <div class="col-md-2">
                <label class="form-label">Month</label>
                <select name="month" class="form-select">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>"
                            <?php echo ($m == $selectedMonth) ? 'selected' : ''; ?>>
                            <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
        <?php endif; ?>

        <div class="col-md-2">
            <label class="form-label">Year (e.g. 2024 or 2567)</label>
            <input type="number" name="year" class="form-control"
                   value="<?php echo htmlspecialchars($selectedYear); ?>">
        </div>

        <div class="col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100">Filter</button>
        </div>
    </form>

    <?php if ($selectedClinic > 0): ?>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <strong>Period:</strong>
                <?php echo htmlspecialchars($startDate); ?> – <?php echo htmlspecialchars($endDate); ?><br>
                <strong>Total tests:</strong> <?php echo count($rows); ?>
            </div>
            <div>
                <?php if (!empty($rows)): ?>
                    <a class="btn btn-success"
                       href="export_clinic_summary_csv.php?clinic_id=<?php echo $selectedClinic; ?>
                       &mode=<?php echo urlencode($mode); ?>
                       &month=<?php echo $selectedMonth; ?>
                       &year=<?php echo $selectedYear; ?>">
                        Export CSV
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($rows)): ?>
            <div class="alert alert-info">No data for this period.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-bordered bg-white">
                    <thead class="table-light">
                        <tr>
                            <th style="width:60px;">No.</th>
                            <th>Lab No.</th>
                            <th>Name</th>
                            <th>Surname</th>
                            <th>Test</th>
                            <th class="text-end">Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $patientIndex = 0;
                        $lastPatientId = null;

                        foreach ($rows as $r):
                            if ($r['patient_id'] != $lastPatientId) {
                                $patientIndex++;
                                $showPatient  = true;
                                $lastPatientId = $r['patient_id'];
                            } else {
                                $showPatient  = false;
                            }
                        ?>
                            <tr>
                                <td><?php echo $showPatient ? $patientIndex : ''; ?></td>
                                <td><?php echo $showPatient ? htmlspecialchars($r['lab_no']) : ''; ?></td>
                                <td><?php echo $showPatient ? htmlspecialchars($r['first_name']) : ''; ?></td>
                                <td><?php echo $showPatient ? htmlspecialchars($r['last_name']) : ''; ?></td>
                                <td><?php echo htmlspecialchars($r['test_name']); ?></td>
                                <td class="text-end"><?php echo number_format($r['price'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="table-secondary fw-bold">
                            <td colspan="5" class="text-end">Grand Total</td>
                            <td class="text-end"><?php echo number_format($grandTotal, 2); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>

</div>
<?php include 'includes/footer.php'; ?>
