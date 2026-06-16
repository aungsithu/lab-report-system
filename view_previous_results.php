<?php
require_once 'includes/db.php';
require_once 'includes/header.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);
$patient_id = $_GET['patient_id'] ?? '';

$hn = $_GET['hn'] ?? '';
$hn = urldecode($hn);
$hn_prefix = explode('/', $hn)[0];
$date_filter = $_GET['date'] ?? null;

// 🧠 Fetch all patients with this HN prefix
$stmt = $conn->prepare("SELECT id, name, hn FROM patients WHERE LEFT(hn, LOCATE('/', hn) - 1) = ?");
$stmt->bind_param("s", $hn_prefix);
$stmt->execute();
$result = $stmt->get_result();
$patients = $result->fetch_all(MYSQLI_ASSOC);

if (empty($patients)) {
    echo "<div class='alert alert-danger'>Patient not found for HN: $hn</div>";
    require_once 'includes/footer.php';
    exit;
}

$patient_names = array_column($patients, 'name');
$patient_ids = array_column($patients, 'id');
$display_name = $patient_names[0];
?>
<div class="container mt-4">
  <?php
  $patient_name = '';
  if ($patient_id) {
      $stmt = $conn->prepare("SELECT name FROM patients WHERE id = ?");
      $stmt->bind_param("i", $patient_id);
      $stmt->execute();
      $stmt->bind_result($patient_name);
      $stmt->fetch();
      $stmt->close();
  }
  ?>
  <h3>Previous Results for <?= htmlspecialchars($patient_name) ?> (HN: <?= htmlspecialchars($hn) ?>)</h3>


  <a href="view_patient.php?id=<?= $patient_id ?>" class="btn btn-secondary mb-3">&larr; Back to Patient</a>

  <?php if (!$date_filter): ?>
    <div class="bg-white rounded shadow p-3 mt-4">
      <table class="table table-bordered table-striped align-middle sticky-header">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>Date</th>
            <th>Total Tests</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $id_list = implode(',', $patient_ids);
          $stmt = $conn->prepare("
            SELECT 
                p.id,
                DATE(p.register_date) AS date,
                COUNT(lr.id) AS total_tests,
                SUM(CASE WHEN lr.status = 'completed' THEN 1 ELSE 0 END) AS completed_tests,
                MAX(p.status) AS patient_status
            FROM lab_results lr
            JOIN patients p ON lr.patient_id = p.id
            WHERE lr.patient_id IN ($id_list)
            GROUP BY p.id, DATE(p.register_date)
            ORDER BY date DESC
        ");

          $stmt->execute();
          $res = $stmt->get_result();

          if ($res->num_rows > 0):
            $i = 1;
            while ($row = $res->fetch_assoc()):
              $status = ($row['completed_tests'] == $row['total_tests']) ? 'Completed' : 'Processing';
              $patient_status = ucfirst($row['patient_status']);
          ?>
            <tr>
              <td><?= $i++ ?></td>
              <td><?= htmlspecialchars($row['date']) ?></td>
              <td><?= $row['total_tests'] ?></td>
              <td>
                <span class="badge text-white <?= strtolower(trim($patient_status)) === 'completed' ? 'bg-success' : 'bg-warning' ?>">
                  <?= htmlspecialchars($patient_status) ?>
                </span>
              </td>
              <td>
                <a href="view_previous_results.php?hn=<?= urlencode($hn) ?>&date=<?= $row['date'] ?>&patient_id=<?= $row['id'] ?>" class="btn btn-sm btn-primary">View</a>
              </td>
            </tr>
          <?php endwhile;
          else: ?>
            <tr><td colspan="5" class="text-center">No previous results found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

  <?php else: ?>
    <h5 class="mt-4">Lab Results for <?= htmlspecialchars($date_filter) ?></h5>
    <a href="view_previous_results.php?hn=<?= urlencode($hn) ?>" class="btn btn-outline-secondary mb-3">&larr; Back to All Dates</a>
    <div class="bg-white rounded shadow p-3 mt-4">
      <table class="table table-bordered table-striped align-middle sticky-header">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>Test</th>
            <th>Result</th>
            <th>Flag</th>
            <th>Unit</th>
            <th>Range</th>
            <th>Method</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $patient_id = $_GET['patient_id'] ?? null;
          $date_filter = $_GET['date'] ?? null;

          if ($patient_id && $date_filter) {
              $stmt = $conn->prepare("
                  SELECT l.test_group, l.test_name, l.result, l.flag, l.unit, l.normal_range, l.method, l.status
                  FROM lab_results l
                  JOIN patients p ON l.patient_id = p.id
                  WHERE l.patient_id = ? AND DATE(p.register_date) = ?
                  ORDER BY l.test_group, l.id
              ");
              $stmt->bind_param("is", $patient_id, $date_filter);
              $stmt->execute();
              $res = $stmt->get_result();

              $i = 1;
              $prev_group = null;
              if ($res->num_rows > 0):
                  while ($r = $res->fetch_assoc()):
                      $group = trim($r['test_group']);

                      if ($group !== $prev_group):
          ?>
          <tr class="table-active fw-bold">
              <td colspan="8"><i class="bi bi-pin-angle-fill text-primary me-2"></i><?= htmlspecialchars($group ?: 'Others') ?></td>
          </tr>
          <?php
                          $prev_group = $group;
                      endif;
          ?>
          <tr>
              <td><?= $i++ ?></td>
              <td><?= htmlspecialchars($r['test_name']) ?></td>
              <td><?= htmlspecialchars($r['result']) ?></td>
              <td><?= htmlspecialchars($r['flag']) ?></td>
              <td><?= htmlspecialchars($r['unit']) ?></td>
              <td><?= htmlspecialchars($r['normal_range']) ?></td>
              <td><?= htmlspecialchars($r['method']) ?></td>
              <td>
                  <span class="badge text-white <?= strtolower(trim($r['status'])) === 'completed' ? 'bg-success' : 'bg-warning' ?>">
                      <?= htmlspecialchars(ucfirst($r['status'])) ?>
                  </span>
              </td>
          </tr>
          <?php
                  endwhile;
              else:
                  echo '<tr><td colspan="8" class="text-center">No results for selected date.</td></tr>';
              endif;
          } else {
              echo '<tr><td colspan="8" class="text-center">Invalid patient selection.</td></tr>';
          }
          ?>
          </tbody>

      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once 'includes/footer.php'; ?>
