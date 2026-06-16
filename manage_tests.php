<?php
require_once 'includes/db.php';
include 'includes/header.php';
require 'includes/session_check.php';

$limit = 20; // results per page
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Handle insert
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['test_name'])) {
    $test_name = trim($_POST['test_name']);
    if (!empty($test_name)) {
        $stmt = $conn->prepare("SELECT id FROM lab_tests WHERE name = ?");
        $stmt->bind_param("s", $test_name);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows == 0) {
            $insert = $conn->prepare("INSERT INTO lab_tests (name) VALUES (?)");
            $insert->bind_param("s", $test_name);
            $insert->execute();
        }
    }
    header("Location: manage_tests.php");
    exit;
}

// Get total records
$total_result = $conn->query("SELECT COUNT(*) AS total FROM lab_tests")->fetch_assoc();
$total_records = $total_result['total'];
$total_pages = ceil($total_records / $limit);

// Fetch records for current page
$stmt = $conn->prepare("SELECT id, name FROM lab_tests ORDER BY name ASC LIMIT ? OFFSET ?");
$stmt->bind_param("ii", $limit, $offset);
$stmt->execute();
$results = $stmt->get_result();
?>

<div class="container py-4">
    <div class="bg-white rounded shadow p-4" style="max-width: 1000px; margin: auto;">
        <h4 class="mb-3">🧪 Manage Lab Tests</h4>
        <form method="POST" class="d-flex mb-3">
            <input type="text" name="test_name" class="form-control me-2" placeholder="Enter test name..." required>
            <button type="submit" class="btn btn-primary">Add Test</button>
        </form>

        <table class="table table-bordered table-sm">
            <thead class="table-light">
                <tr>
                    <th style="width: 60px;">#</th>
                    <th>Test Name</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $i = $offset + 1;
                while ($row = $results->fetch_assoc()) {
                    echo "<tr><td>{$i}</td><td>" . htmlspecialchars($row['name']) . "</td></tr>";
                    $i++;
                }
                ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <nav>
            <ul class="pagination justify-content-center">
                <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?= $page - 1 ?>">Previous</a>
                    </li>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?= $page + 1 ?>">Next</a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
