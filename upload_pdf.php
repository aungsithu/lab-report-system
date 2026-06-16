<?php
ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
require 'includes/db.php';
$conn->query("SET time_zone = '+07:00'");
include 'includes/header.php';

$id = (int)($_GET['id'] ?? 0);

$patient = null;
if ($id > 0) {
    $stmt = $conn->prepare("SELECT p.*, c.name AS clinic_name FROM patients p LEFT JOIN clinics c ON p.clinic_id = c.id WHERE p.id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $patient = $stmt->get_result()->fetch_assoc();
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf_file']) && $id > 0) {
    $file = $_FILES['pdf_file'];

    // ✅ Use original filename (Thai allowed) + timestamp
    $filenameOnly = pathinfo($file['name'], PATHINFO_FILENAME);
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = $filenameOnly . '_' . time() . '.' . $extension;

    $targetPath = 'uploads/' . $filename;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        // Save to patient_reports
        $stmt = $conn->prepare("INSERT INTO patient_reports (patient_id, file_path) VALUES (?, ?)");
        $stmt->bind_param("is", $id, $targetPath);
        $stmt->execute();

        // Update patients.report_pdf
        $stmt = $conn->prepare("UPDATE patients SET report_pdf = ? WHERE id = ?");
        $stmt->bind_param("si", $targetPath, $id);
        $stmt->execute();

        header("Location: view_patient.php?id=$id&upload_success=1");
        exit;
    } else {
        echo "<div class='alert alert-danger'>❌ Upload failed. Please try again.</div>";
    }
}
?>
<style>
  .card p {
    margin: 0 0 8px;
  }
</style>

<div class="container py-4">
  <?php if ($patient): ?>
    <div class="card mb-4 p-3 shadow-sm">
      <h5 class="fw-bold text-primary mb-3">📄 Upload Report for: <?= htmlspecialchars($patient['name']) ?></h5>
      <div class="row">
        <div class="col-md-6">
          <p><strong>Name:</strong> <?= htmlspecialchars($patient['name']) ?></p>
          <p><strong>HN:</strong> <?= $patient['hn'] ?></p>
          <p><strong>Age:</strong> <?= $patient['age'] ?></p>
          <p><strong>Doctor:</strong> <?= $patient['doctor'] ?></p>
        </div>
        <div class="col-md-6">
          <p><strong>Clinic:</strong> <?= htmlspecialchars($patient['clinic_name']) ?></p>
          <p><strong>LN:</strong> <?= $patient['ln'] ?></p>
          <p><strong>Sex:</strong> <?= $patient['sex'] ?></p>
          <p><strong>Registered Date:</strong> <?= $patient['register_date'] ?></p>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <h4 class="mb-3">📄 Upload or Replace Report</h4>
  <form method="POST" enctype="multipart/form-data">
    <!-- <div class="mb-3">
      <label class="form-label">Report Name (without .pdf)</label>
      <input type="text" name="custom_name" class="form-control" placeholder="e.g. รายงานผลตรวจ" required>
    </div> -->
    <div class="mb-3">
      <label class="form-label">Choose PDF File</label>
      <input type="file" name="pdf_file" accept=".pdf" required class="form-control">
    </div>
    <button type="submit" class="btn btn-primary">Upload</button>
    <a href="view_patient.php?id=<?= $id ?>" class="btn btn-secondary">Cancel</a>
  </form>
</div>

<?php ob_end_flush(); ?>
