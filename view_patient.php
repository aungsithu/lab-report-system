<?php
ob_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Bangkok');
require 'includes/db.php';
include 'includes/header.php';
require 'includes/session_check.php';
require 'includes/phpspreadsheet/src/PhpSpreadsheet/IOFactory.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
// PhpSpreadsheet
spl_autoload_register(function ($class) {
    $prefix = 'PhpOffice\\PhpSpreadsheet\\';
    $base_dir = __DIR__ . '/includes/phpspreadsheet/src/PhpSpreadsheet/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) require $file;
});
// PSR Cache
spl_autoload_register(function ($class) {
    $prefix = 'Psr\\SimpleCache\\';
    $base_dir = __DIR__ . '/includes/phpspreadsheet/src/Psr/SimpleCache/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) require $file;
});
// Composer Pcre
spl_autoload_register(function ($class) {
    $prefix = 'Composer\\Pcre\\';
    $base_dir = __DIR__ . '/includes/phpspreadsheet/src/Composer/Pcre/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) require $file;
});
$id = $_GET['id'] ?? 0;
$role = $_SESSION['role'] ?? $_SESSION['user_role'] ?? '';
$clinic_id = $_SESSION['clinic_id'] ?? 0;
if ($role === 'clinic_user') {
  $stmt = $conn->prepare("SELECT id FROM patients WHERE id = ? AND clinic_id = ?");
  $stmt->bind_param("ii", $id, $clinic_id);
  $stmt->execute();
  if ($stmt->get_result()->num_rows === 0) {
    echo "<div class='alert alert-danger m-4'>❌ Access denied.</div>";
    include 'includes/footer.php';
    exit;
  }
}
if (isset($_POST['update_status'])) {
  $new_status = $_POST['status'] ?? 'standby';
  $stmt = $conn->prepare("UPDATE patients SET status = ? WHERE id = ?");
  $stmt->bind_param("si", $new_status, $id);
  $stmt->execute();
}
if (isset($_POST['save_patient_info']) && $role !== 'clinic_user') {
  $name = $_POST['edit_name'];
  $hn = $_POST['edit_hn'];
  $ln = $_POST['edit_ln'];
  $sex = $_POST['edit_sex'];
  $age = (int)$_POST['edit_age'];
  $doctor = $_POST['edit_doctor'];
  $stmt = $conn->prepare("UPDATE patients SET name=?, hn=?, ln=?, sex=?, age=?, doctor=? WHERE id=?");
  $stmt->bind_param("ssssssi", $name, $hn, $ln, $sex, $age, $doctor, $id);
  $stmt->execute();
  header("Location: view_patient.php?id=" . $id);
  exit;
}
if (isset($_POST['add_test']) && $role !== 'clinic_user') {
  $test_name = trim($_POST['test_name']);
  if (!empty($test_name)) {
    $stmt = $conn->prepare("INSERT INTO lab_results (patient_id, test_name) VALUES (?, ?)");
    $stmt->bind_param("is", $id, $test_name);
    $stmt->execute();
  }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_test_id']) && $role !== 'clinic_user') {
    $delete_id = (int) $_POST['delete_test_id'];
    $id = $_GET['id'] ?? $_POST['patient_id'] ?? null;

    if ($delete_id && $id) {
        $stmt = $conn->prepare("DELETE FROM lab_results WHERE id = ?");
        $stmt->bind_param("i", $delete_id);
        $stmt->execute();
        $stmt->close();

        $check = $conn->prepare("SELECT COUNT(*) FROM lab_results WHERE patient_id = ? AND status != 'completed'");
        $check->bind_param("i", $id);
        $check->execute();
        $check->bind_result($not_done);
        $check->fetch();
        $check->close();

        $new_status = ($not_done == 0) ? 'completed' : 'processing';
        $conn->query("UPDATE patients SET status = '$new_status' WHERE id = $id");

        header("Location: view_patient.php?id=$id");
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    $uploaded_file_path = 'uploads/' . basename($_FILES['excel_file']['name']);
    move_uploaded_file($_FILES['excel_file']['tmp_name'], $uploaded_file_path);
    try {
        $spreadsheet = IOFactory::load($uploaded_file_path);
        $worksheet = $spreadsheet->getActiveSheet();
        $highestRow = $worksheet->getHighestRow();

        $expected = ['test name', 'result', 'flag', 'unit', 'reference range', 'method'];
        $highestCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($worksheet->getHighestColumn());
        $test_name_col = null;
        for ($row = 1; $row <= 5; $row++) {
            for ($col = 1; $col <= $highestCol; $col++) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                $value = trim(strtolower((string)$worksheet->getCell($colLetter . $row)->getValue()));
                foreach ($expected as $keyword) {
                    if (strpos($value, $keyword) !== false) {
                        $test_name_col = $colLetter;
                        break 2;
                    }
                }
            }
        }
        $header_map = [];
        $header_order = [];
        $pdf_mode = false;
        $footer_keywords = [
            'approve', 'remark', 'request', 'reported', 'check in', 'date/time', 'lab', 'clinic', 'doctor',
            'รายงานฉบับนี้รับรองเฉพาะ', 'page',
            'แก้ไขหรือคัดลอกเฉพาะบางส่วนโดยไม่ได้รับ',
            'รายงานฉบับนี้รับรองเฉพาะตัวอย่างที่ได้ทดสอบเท่านั้น และรับรองผลทดสอบเฉพาะ',
            'รายงานฉบับจริงหรือสำเนารายงานฉบับจริงที่มีข้อมูลครบถ้วนทั้งฉบับ',
            'ห้ามนำใบรายการนี้ไปทำการเพิ่มเติม',
            'แก้ไขหรือคัดลอกเฉพาะบางส่วนโดยไม่ได้รับอนุญาต',
            'แก้ ไขหรือคัดลอกเฉพาะบางส่วนโดยไม่ ได้รับอนุญาต'
        ];
        $skip_keywords = ['test name', 'result', 'flag', 'unit', 'reference range', 'method'];
        $empty_row_count = 0;
        $current_category = '';
        $test_index = 1;
        for ($row = 1; $row <= $highestRow; $row++) {
            $firstCell = trim((string)$worksheet->getCell('A' . $row)->getValue());
            $normalized_cell = strtolower(trim(preg_replace('/\s+/', ' ', $firstCell)));
            $known_groups = ['cardiovascular', 'other lab', 'urine examination'];

            if (in_array($normalized_cell, $known_groups)) {
                $current_category = ucwords($normalized_cell); 
                continue; 
            }

            $secondCell = trim((string)$worksheet->getCell('B' . $row)->getValue());

            $firstCell = str_replace(["\n", "\r"], ' ', $firstCell);
            $firstCell = preg_replace('/\s+/', ' ', $firstCell);

            $lower_test = strtolower($firstCell);


            if ($lower_test === 'bioavailable testosterone' || $lower_test === '%bioavailable testosterone') {
                $excel_order = $test_index++;
                $test_name = $firstCell;
                $result = $secondCell;
                $flag = '';
                $unit = trim((string)$worksheet->getCell('D' . $row)->getValue());
                $normal_range = trim((string)$worksheet->getCell('E' . $row)->getValue());
                $method = trim((string)$worksheet->getCell('F' . $row)->getValue());

                for ($i = 1; $i <= 10; $i++) {
                    $next_row = $row + $i;
                    $next_test = trim((string)$worksheet->getCell("A$next_row")->getValue());
                    $next_range = trim((string)$worksheet->getCell("E$next_row")->getValue());

                    if ($next_test === '' && $next_range !== '') {
                        $normal_range .= ' | ' . $next_range;
                        $row++; 
                    } else {
                        break;
                    }
                }

                $check = $conn->prepare("SELECT id FROM lab_results WHERE patient_id = ? AND test_name = ?");
                $check->bind_param("is", $id, $test_name);
                $check->execute();
                $check->store_result();

                if ($check->num_rows === 0) {
                    $stmt = $conn->prepare("INSERT INTO lab_results 
                        (patient_id, test_name, result, flag, unit, normal_range, method, status, test_group)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'processing', ?)");
                    $stmt->bind_param("isssssss", $id, $test_name, $result, $flag, $unit, $normal_range, $method, $current_category);
                    $stmt->execute();
                } else {
                    $check->bind_result($existing_id);
                    $check->fetch();
                    $stmt = $conn->prepare("UPDATE lab_results 
                        SET result = ?, flag = ?, unit = ?, normal_range = ?, method = ?, test_group = ?
                        WHERE id = ?");
                    $stmt->bind_param("ssssssi", $result, $flag, $unit, $normal_range, $method, $current_category, $existing_id);
                    $stmt->execute();
                }

                continue; 
            }

            $normalizedHeader = strtolower(preg_replace('/\s+/', ' ', $firstCell));
            if (
                strpos($normalizedHeader, 'test name') !== false &&
                strpos($normalizedHeader, 'result') !== false &&
                substr_count($firstCell, '  ') >= 1
            ) {
                $columns = preg_split('/\s{2,}/u', trim($firstCell));
                $columns = array_filter($columns, fn($v) => $v !== '');
                $header_order = array_map('strtolower', array_map('trim', $columns));
                $pdf_mode = true;
                continue;
            }
            $header_keywords = ['test name', 'result', 'flag', 'unit', 'reference range', 'method'];
            $found_headers = 0;
            $temp_map = [];
            foreach (range('A', $worksheet->getHighestColumn()) as $col) {
                $val = strtolower(trim((string)$worksheet->getCell($col . $row)->getValue()));
                if (in_array($val, $header_keywords)) {
                    $temp_map[$val] = $col;
                    $found_headers++;
                }
            }
            if ($found_headers >= 3) {
                $header_map = $temp_map;
                $pdf_mode = false;
                continue;
            }
            if (!$pdf_mode && (!isset($header_map['test name']) || !isset($header_map['result']))) continue;
            if ($pdf_mode) {
                $values = preg_split('/\s{2,}/u', $firstCell);
                $values = array_map('trim', $values);
                $assoc = array_combine($header_order, $values + array_fill(0, count($header_order), ''));
                $test_name    = $assoc['test name'] ?? '';
                $result       = $assoc['result'] ?? '-';
                $flag         = $assoc['flag'] ?? '-';
                $unit         = $assoc['unit'] ?? '-';
                $normal_range = $assoc['reference range'] ?? '-';
                $method       = $assoc['method'] ?? '-';
            } else {
                $colA = trim((string)$worksheet->getCell('A' . $row)->getValue());
                $colB = trim((string)$worksheet->getCell('B' . $row)->getValue());
                $colC = trim((string)$worksheet->getCell('C' . $row)->getValue());
                if (
                    !$pdf_mode &&
                    substr_count($firstCell, '  ') >= 2 &&
                    empty(trim($secondCell)) &&
                    !preg_match('/(approve|reported|remark|clinic|doctor|date|signature|page|check in|request)/i', $firstCell)
                ) {
                    $chunks = preg_split('/\s{2,}/u', $firstCell);
                    $chunks = array_values(array_filter(array_map('trim', $chunks), fn($v) => $v !== ''));
                    if (count($chunks) >= 3) {
                        $test_name = $chunks[0] ?? '';
                        $result = $chunks[1] ?? '-';
                        $unit = $chunks[2] ?? '-';
                        $method = $chunks[3] ?? '-';
                        $flag = '-';
                        $normal_range = '-';
                        $peek_range = [];
                        for ($i = 1; $i <= 3; $i++) {
                            $next_val = trim((string)$worksheet->getCell('A' . ($row + $i))->getValue());
                            if (preg_match('/\d{2}-\d{2}/', $next_val) || stripos($next_val, 'established') !== false) {
                                $peek_range[] = $next_val;
                            }
                        }
                        if (!empty($peek_range)) {
                            $normal_range = implode(" | ", $peek_range);
                            $row += count($peek_range);
                        }
                        goto insert_test_row;
                    }
                }
                $test_name = $colA;
                $result       = isset($header_map['result']) ? trim((string)$worksheet->getCell($header_map['result'] . $row)->getValue()) : '-';
                $flag         = isset($header_map['flag']) ? trim((string)$worksheet->getCell($header_map['flag'] . $row)->getValue()) : '-';
                $unit         = isset($header_map['unit']) ? trim((string)$worksheet->getCell($header_map['unit'] . $row)->getValue()) : '-';
                $normal_range = isset($header_map['reference range']) ? trim((string)$worksheet->getCell($header_map['reference range'] . $row)->getValue()) : '-';
                $method       = isset($header_map['method']) ? trim((string)$worksheet->getCell($header_map['method'] . $row)->getValue()) : '-';
                if ((empty($result) || $result === '-') && preg_match('/^(.+?)\s+(\d+(\.\d+)?)/', $test_name, $m)) {
                    $test_name = trim($m[1]);
                    $result = trim($m[2]);
                }
                $test_name = preg_replace('/\s+/', ' ', str_replace("\n", ' ', $test_name));
                $result       = isset($header_map['result']) ? trim((string)$worksheet->getCell($header_map['result'] . $row)->getValue()) : '-';
                $flag         = isset($header_map['flag']) ? trim((string)$worksheet->getCell($header_map['flag'] . $row)->getValue()) : '-';
                $unit         = isset($header_map['unit']) ? trim((string)$worksheet->getCell($header_map['unit'] . $row)->getValue()) : '-';
                $normal_range = isset($header_map['reference range']) ? trim((string)$worksheet->getCell($header_map['reference range'] . $row)->getValue()) : '-';
                $method       = isset($header_map['method']) ? trim((string)$worksheet->getCell($header_map['method'] . $row)->getValue()) : '-';
                if (
                    $result === '-' && $flag === '-' && $unit === '-' &&
                    $normal_range === '-' && $method === '-' && trim($test_name) !== ''
                ) {
                    foreach (range('F', 'L') as $col) {
                        $val = trim((string)$worksheet->getCell($col . $row)->getFormattedValue());
                        if ($val === '' || $val === '-' || strtolower($val) === strtolower($test_name)) continue;
                        if (preg_match('/(clia|calculate|automate|microscopic)/i', $val)) {
                            $method = $method === '-' ? $val : "$method $val";
                        } else {
                            $normal_range = $normal_range === '-' ? $val : "$normal_range $val";
                        }
                    }
                }
            }
            $lower_name = strtolower($test_name);
            if (strpos($test_name, "\n") !== false) {
                $test_name = trim(str_replace("\n", ' ', $test_name));
            }
            $test_name = preg_replace('/\s+/', ' ', $test_name);
            $lower_name = strtolower($test_name);
            $normalized = preg_replace('/[\x{00A0}\s]+/u', ' ', $lower_name);
            foreach ($footer_keywords as $kw) {
                $normalized_kw = preg_replace('/[\x{00A0}\s]+/u', ' ', strtolower(trim($kw)));
                if (stripos($normalized, $normalized_kw) !== false) continue 2;
            }
            if (in_array($lower_name, $skip_keywords)) continue;

            if (!$pdf_mode && isset($header_map['test name'])) {
                $cell_value = trim((string)$worksheet->getCell($header_map['test name'] . $row)->getValue());
                $style = $worksheet->getStyle($header_map['test name'] . $row);
                $is_bold = $style->getFont()->getBold();
                $lower_header = strtolower(preg_replace('/\s+/', ' ', $cell_value));

                $known_group_labels = ['cardiovascular', 'other lab', 'urine examination'];

                if (
                    ($is_bold && $result === '') ||
                    (in_array($lower_header, $known_group_labels) && $result === '')
                ) {
                    $current_category = ucwords(strtolower($cell_value));
                    continue;
                }
            }

            if (stripos($test_name, 'test name') !== false && stripos($result, 'result') !== false) {
                continue;
            }
            foreach (['result', 'flag', 'unit', 'normal_range', 'method'] as $field) {
                if (empty($$field) || trim($$field) === '') {
                    $$field = '-';
                }
            }
            if (
                $result === '-' && $flag === '-' && $unit === '-' &&
                $normal_range === '-' && $method === '-' && trim($test_name) !== ''
            ) {
                $range_extra = '';
                $method_extra = '';
                foreach (range('F', 'L') as $col) {
                    $val = trim(preg_replace("/\s+/", " ", (string)$worksheet->getCell($col . $row)->getFormattedValue()));
                    if ($val === '' || $val === '-' || strtolower($val) === strtolower($test_name)) continue;
                    if (preg_match('/(clia|calculate|automate|microscopic)/i', $val)) {
                        $method_extra .= ($method_extra ? ' ' : '') . $val;
                    } else {
                        $range_extra .= ($range_extra ? ' ' : '') . $val;
                    }
                }
                if (empty($normal_range)) $normal_range = $range_extra;
                if (empty($method)) $method = $method_extra;
            }
            $test_name_cleaned = trim(preg_replace('/\s+/', ' ', $test_name));
            if ($test_name_cleaned === '' || $test_name_cleaned === '-') {
                continue;
            }
            $current_group_cleaned = trim(preg_replace('/\s+/', ' ', $current_category));
            $lower_test_name = strtolower($test_name_cleaned);
            $lower_group_name = strtolower($current_group_cleaned);

            if (
                $lower_test_name === $lower_group_name &&
                ($result === '' || $result === '-' || $result === null) &&
                ($unit === '' || $unit === '-' || $unit === null) &&
                ($normal_range === '' || $normal_range === '-' || $normal_range === null) &&
                ($method === '' || $method === '-' || $method === null)
            ) {
                continue;
            }

            $has_value = false;
            if ($lower_test_name !== '') {
                foreach ([$result, $unit, $normal_range, $method] as $val) {
                    if (trim($val) !== '' && trim($val) !== '-') {
                        $has_value = true;
                        break;
                    }
                }
            }

            insert_test_row:
            
            $test_name = preg_replace('/\s+/', ' ', str_replace("\n", ' ', $test_name));
            $test_name = trim($test_name);

            $style = $worksheet->getStyle('A' . $row);
            $is_bold = $style->getFont()->getBold();
            $clean_test_name = trim(preg_replace('/\s+/', ' ', str_replace("\n", ' ', $test_name)));
            $lower_name = strtolower($clean_test_name);
            $header_words = ['test name', 'result', 'flag', 'unit', 'reference range', 'method', 'remarks'];

            $repeat_groups = ['Urine Examination', 'Other Lab', 'Cardiovascular'];

            if (
                $is_bold &&
                strlen($clean_test_name) > 1 &&
                !preg_match('/^(remark|interpretation|comment|approve|report|clinic|doctor|check in|request)/i', $lower_name) &&
                !in_array($lower_name, $header_words)
            ) {
                $normalized_group = ucwords(strtolower($clean_test_name));

                if (strcasecmp($normalized_group, $current_category) === 0) {
                    continue;
                }

                foreach ($repeat_groups as $group_label) {
                    if (
                        strcasecmp($normalized_group, $group_label) === 0 &&
                        strcasecmp($current_category, $group_label) === 0
                    ) {
                        continue 2;
                    }
                }

                $current_category = $normalized_group;
                continue;
            }

            $manual_group_map = [
                'edim test (apo10 & tktl1)' => 'Other Lab',
                'oxidized ldl' => 'Other Lab',
                'nad level' => 'Other Lab',
                'dna methylation (mytruehealth)' => 'Other Lab',
            ];

            if (isset($manual_group_map[$lower_name])) {
                $current_category = $manual_group_map[$lower_name];
            }

            if (in_array($lower_name, $skip_keywords)) return;
            $normalized = preg_replace('/[\x{00A0}\s]+/u', ' ', $lower_name);
            $skip_this_row = false;
            foreach ($footer_keywords as $kw) {
                $normalized_kw = preg_replace('/[\x{00A0}\s]+/u', ' ', strtolower(trim($kw)));
                if (strlen($normalized) < 60 && stripos($normalized, $normalized_kw) !== false) {
                    $skip_this_row = true;
                    break;
                }
            }
            if ($skip_this_row) continue;

            $check = $conn->prepare("SELECT id, result, flag, unit, normal_range, method, test_group 
                                     FROM lab_results 
                                     WHERE patient_id = ? AND test_name = ?");
            $check->bind_param("is", $id, $test_name);
            $check->execute();
            $check->store_result();

            if ($check->num_rows === 0) {
                $stmt = $conn->prepare("INSERT INTO lab_results 
                (patient_id, test_name, result, flag, unit, normal_range, method, status, test_group, excel_order) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'processing', ?, ?)");
                $current_category = ucwords(strtolower(trim($current_category)));
                $stmt->bind_param("isssssssi", $id, $test_name, $result, $flag, $unit, $normal_range, $method, $current_category, $excel_order);
                $stmt->execute();
            } else {
                $check->bind_result($existing_id, $old_result, $old_flag, $old_unit, $old_range, $old_method, $old_group);
                $check->fetch();
                if (
                    $result !== $old_result || $flag !== $old_flag ||
                    $unit !== $old_unit || $normal_range !== $old_range ||
                    $method !== $old_method || $current_category !== $old_group
                ) {
                    $update = $conn->prepare("UPDATE lab_results 
                    SET result = ?, flag = ?, unit = ?, normal_range = ?, method = ?, test_group = ?, excel_order = ? 
                    WHERE id = ?");
                   $update->bind_param("ssssssii", $result, $flag, $unit, $normal_range, $method, $current_category, $excel_order, $existing_id);
                    $update->execute();
                }
            }
        }
        echo "<div class='alert alert-success' style='border-radius:12px;'>✅ Excel imported successfully.</div>";

        $update = $conn->prepare("
            UPDATE lab_results 
            SET status = 'Completed' 
            WHERE patient_id = ? 
            AND TRIM(result) != '' 
            AND TRIM(result) != '-' 
            AND TRIM(result) != 'รอผลตรวจ'
        ");
        $update->bind_param("i", $id);
        $update->execute();
        $update->close();

        $check = $conn->prepare("
            SELECT COUNT(*) 
            FROM lab_results 
            WHERE patient_id = ? 
            AND (
                TRIM(result) = '' 
                OR TRIM(result) = '-' 
                OR TRIM(result) = 'รอผลตรวจ'
                OR status = 'Processing'
            )
        ");
        $check->bind_param("i", $id);
        $check->execute();
        $check->bind_result($incomplete_count);
        $check->fetch();
        $check->close();

        $final_status = ($incomplete_count == 0) ? 'Completed' : 'Processing';
        $update_status = $conn->prepare("UPDATE patients SET status = ? WHERE id = ?");
        $update_status->bind_param("si", $final_status, $id);
        $update_status->execute();
        $update_status->close();

    } catch (Exception $e) {
        echo "<div class='alert alert-danger' style='border-radius:12px;'>❌ Import failed: " . $e->getMessage() . "</div>";
    }
}
//Pagination
$per_page = 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$start = ($page - 1) * $per_page;
$total_tests = $conn->query("SELECT COUNT(*) as total FROM lab_results WHERE patient_id = $id")->fetch_assoc()['total'];
$total_pages = ceil($total_tests / $per_page);
$tests = $conn->query("SELECT * FROM lab_results 
    WHERE patient_id = $id 
    ORDER BY excel_order ASC 
    LIMIT $start, $per_page");

if (isset($_GET['delete_report_id']) && in_array($role, ['superadmin', 'admin'])) {
    $report_id = (int)$_GET['delete_report_id'];
    $result = $conn->query("SELECT file_path FROM patient_reports WHERE id = $report_id LIMIT 1");
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $file_path = $row['file_path'];
        $conn->query("DELETE FROM patient_reports WHERE id = $report_id");
        if (file_exists($file_path)) {
            unlink($file_path);
        }
        header("Location: view_patient.php?id=" . (int)$_GET['id'] . "&deleted=1");
        exit;
    }
}
if (isset($_POST['save_note']) && $role !== 'clinic_user') {
  $note = trim($_POST['admin_note']);
  $stmt = $conn->prepare("UPDATE patients SET admin_note = ? WHERE id = ?");
  $stmt->bind_param("si", $note, $id);
  $stmt->execute();
  header("Location: view_patient.php?id=$id&note=updated");
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_group']) && $role !== 'clinic_user') {
    $id = $_GET['id'] ?? $_POST['patient_id'] ?? null;
    $group_to_delete = $_POST['delete_group'];
    if (!$id || !is_numeric($id)) {
        echo "<div class='alert alert-danger'>❌ Invalid patient ID.</div>";
        return;
    }
    if (strtolower(trim($group_to_delete)) === 'others') {
        echo "<div class='alert alert-warning'>⚠️ Cannot delete the 'Others' group.</div>";
    } else {
        $stmt = $conn->prepare("DELETE FROM lab_results WHERE patient_id = ? AND TRIM(test_group) = TRIM(?)");
        $stmt->bind_param("is", $id, $group_to_delete);
        $stmt->execute();
        $stmt->close();

        $check = $conn->prepare("SELECT COUNT(*) FROM lab_results WHERE patient_id = ? AND status != 'completed'");
        $check->bind_param("i", $id);
        $check->execute();
        $check->bind_result($not_done);
        $check->fetch();
        $check->close();

        $new_status = ($not_done == 0) ? 'completed' : 'processing';
        $conn->query("UPDATE patients SET status = '$new_status' WHERE id = $id");

        header("Location: view_patient.php?id=$id&group_deleted=" . urlencode($group_to_delete));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_selected']) && isset($_POST['test_ids']) && is_array($_POST['test_ids'])) {
        $ids = $_POST['test_ids'];
        $id = $_GET['id'] ?? $_POST['patient_id'] ?? null;

        if (count($ids) > 0 && $id) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $types = str_repeat('i', count($ids));
            $stmt = $conn->prepare("DELETE FROM lab_results WHERE id IN ($placeholders)");
            $stmt->bind_param($types, ...$ids);

            if ($stmt->execute()) {
                $_SESSION['success_message'] = '🗑️ Deleted ' . $stmt->affected_rows . ' selected test(s).';
            } else {
                $_SESSION['error_message'] = '❌ Deletion failed: ' . $stmt->error;
            }
            $stmt->close();

            $check = $conn->prepare("SELECT COUNT(*) FROM lab_results WHERE patient_id = ?");
            $check->bind_param("i", $id);
            $check->execute();
            $check->bind_result($remaining);
            $check->fetch();
            $check->close();

            if ($remaining == 0) {
                $conn->query("UPDATE patients SET status = 'processing' WHERE id = $id");
            } else {
                $check2 = $conn->prepare("SELECT COUNT(*) FROM lab_results WHERE patient_id = ? AND status != 'completed'");
                $check2->bind_param("i", $id);
                $check2->execute();
                $check2->bind_result($not_done);
                $check2->fetch();
                $check2->close();

                $new_status = ($not_done == 0) ? 'completed' : 'processing';
                $conn->query("UPDATE patients SET status = '$new_status' WHERE id = $id");
            }

            header("Location: view_patient.php?id=" . $id);
            exit;
        }
    }
}

if (isset($_POST['apply_bulk_status']) && !empty($_POST['test_ids']) && !empty($_POST['bulk_status'])) {
    $ids = array_map('intval', $_POST['test_ids']);
    $id_list = implode(',', $ids);
    $new_status = $_POST['bulk_status'] === 'completed' ? 'Completed' : 'Processing';

    $conn->query("UPDATE lab_results SET status = '$new_status' WHERE id IN ($id_list)");

    $patient_id = intval($_GET['id']);

    $check = $conn->prepare("SELECT COUNT(*) FROM lab_results WHERE patient_id = ? AND status != 'Completed'");
    $check->bind_param("i", $patient_id);
    $check->execute();
    $check->bind_result($not_done);
    $check->fetch();
    $check->close();

    $final_status = ($not_done == 0) ? 'Completed' : 'Processing';
    $conn->query("UPDATE patients SET status = '$final_status' WHERE id = $patient_id");

    header("Location: view_patient.php?id=" . $patient_id);
    exit;
}

if (isset($_POST['save_test']) && $role !== 'clinic_user') {
  $test_id = $_POST['test_id'];
  $result = trim($_POST['result']);
  $flag = trim($_POST['flag']);
  $unit = trim($_POST['unit']);
  $range = trim($_POST['range']);
  $method = trim($_POST['method']);
  $status = (strtolower($result) !== 'รอผลตรวจ' && $result !== '' && $result !== '-') ? 'completed' : 'processing';

  $stmt = $conn->prepare("UPDATE lab_results SET result=?, flag=?, unit=?, normal_range=?, method=?, status=? WHERE id=?");
  $stmt->bind_param("ssssssi", $result, $flag, $unit, $range, $method, $status, $test_id);
  $stmt->execute();
  $stmt->close();

  $get_patient = $conn->prepare("SELECT patient_id FROM lab_results WHERE id = ?");
  $get_patient->bind_param("i", $test_id);
  $get_patient->execute();
  $get_patient->bind_result($patient_id);
  $get_patient->fetch();
  $get_patient->close();

    $check = $conn->prepare("
        SELECT COUNT(*) 
        FROM lab_results 
        WHERE patient_id = ? 
        AND (
            TRIM(result) = '' 
            OR TRIM(result) = '-' 
            OR TRIM(result) = 'รอผลตรวจ'
            OR status = 'Processing'
        )
    ");
    $check->bind_param("i", $patient_id);
    $check->execute();
    $check->bind_result($not_completed_count);
    $check->fetch();
    $check->close();

    $new_status = ($not_completed_count == 0) ? 'Completed' : 'Processing';

    $update = $conn->prepare("UPDATE patients SET status = ? WHERE id = ?");
    $update->bind_param("si", $new_status, $patient_id);
    $update->execute();
    $update->close();

  header("Location: view_patient.php?id=" . $patient_id);
  exit;
}

$patient = $conn->query("SELECT p.*, c.name AS clinic_name 
                         FROM patients p 
                         LEFT JOIN clinics c ON p.clinic_id = c.id 
                         WHERE p.id = $id")->fetch_assoc();
$full_hn  = trim($patient['hn'] ?? '');
$full_ln  = trim($patient['ln'] ?? '');
$clinic_id_current = (int)($patient['clinic_id'] ?? 0);

$hn_prefix = $full_hn;
$pos = strpos($full_hn, '/');
if ($pos !== false && $pos > 0) {
    $hn_prefix = substr($full_hn, 0, $pos);
}
$hn_prefix = trim($hn_prefix);

$patient_name_norm = mb_strtolower(trim(preg_replace('/\s+/', ' ', $patient['name'] ?? '')));

$all_tests = $conn->query("SELECT name FROM lab_tests ORDER BY name");
$report_pdf = $patient['report_pdf'] ?? '';
$pdf_exists = !empty($report_pdf) && file_exists(__DIR__ . '/' . $report_pdf);
?>

<style>
/* ── Design tokens ── */
:root {
  --vp-blue:    #2563eb;
  --vp-indigo:  #4f46e5;
  --vp-green:   #16a34a;
  --vp-red:     #dc2626;
  --vp-amber:   #d97706;
  --vp-border:  rgba(15,23,42,.08);
  --vp-shadow:  0 4px 24px rgba(15,23,42,.07);
  --vp-r:       14px;
}

/* ── Hero ── */
.vp-hero {
  background: linear-gradient(120deg, #0f2444 0%, #1d4ed8 55%, #3b82f6 100%);
  border-radius: var(--vp-r);
  padding: 22px 28px;
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  position: relative;
  overflow: hidden;
  margin-bottom: 20px;
}
.vp-hero::before {
  content:''; position:absolute; right:-50px; top:-50px;
  width:220px; height:220px; border-radius:50%;
  background:rgba(255,255,255,.05);
}
.vp-hero::after {
  content:''; position:absolute; right:80px; bottom:-70px;
  width:160px; height:160px; border-radius:50%;
  background:rgba(255,255,255,.04);
}
.vp-hero h4 { font-size:20px; font-weight:750; margin:0 0 3px; letter-spacing:-.2px; }
.vp-hero p  { margin:0; font-size:13px; opacity:.75; }

/* ── Status pill ── */
.vp-status {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 7px 16px; border-radius: 999px;
  font-weight: 700; font-size: 13px;
  border: 1px solid;
  white-space: nowrap;
}
.vp-status-completed { background:#f0fdf4; color:#15803d; border-color:#86efac; }
.vp-status-processing { background:#fffbeb; color:#b45309; border-color:#fcd34d; }
.vp-status-dot { width:8px; height:8px; border-radius:50%; display:inline-block; }
.vp-status-completed .vp-status-dot { background:#16a34a; }
.vp-status-processing .vp-status-dot { background:#d97706; }

/* ── Cards ── */
.vp-card {
  background: #fff;
  border: 1px solid var(--vp-border);
  border-radius: var(--vp-r);
  box-shadow: var(--vp-shadow);
  /* overflow:hidden removed — it breaks position:sticky on thead */
  margin-bottom: 18px;
}
/* Clip card corners on first and last direct children instead */
.vp-card > *:first-child { border-radius: var(--vp-r) var(--vp-r) 0 0; }
.vp-card > *:last-child  { border-radius: 0 0 var(--vp-r) var(--vp-r); }
.vp-card-head {
  background: #f8fafc;
  border-bottom: 1px solid var(--vp-border);
  padding: 13px 20px;
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
}
.vp-card-head .head-title {
  font-size: 14px; font-weight: 700; color: #111827;
  display: flex; align-items: center; gap: 8px;
}
.vp-card-body { padding: 20px; }

/* ── Patient info grid ── */
.pt-info-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 14px;
}
.pt-info-item { display: flex; flex-direction: column; gap: 3px; }
.pt-info-item .label {
  font-size: 10.5px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .07em; color: #9ca3af;
}
.pt-info-item .value {
  font-size: 14px; font-weight: 600; color: #111827;
}

/* ── Upload PDF button ── */
.btn-upload-pdf {
  background: linear-gradient(135deg, #2563eb, #4f46e5);
  color: #fff; border: none;
  border-radius: 10px; padding: 9px 18px;
  font-size: 13.5px; font-weight: 600;
  display: inline-flex; align-items: center; gap: 7px;
  text-decoration: none;
  transition: opacity .15s, transform .1s;
}
.btn-upload-pdf:hover { opacity:.88; transform:translateY(-1px); color:#fff; }

/* ── Reports list ── */
.vp-report-item {
  display: flex; align-items: center; justify-content: space-between;
  padding: 11px 14px;
  border: 1px solid var(--vp-border);
  border-left: 4px solid #93c5fd;
  border-radius: 10px;
  background: #f8fafc;
  margin-bottom: 8px;
  gap: 10px;
}
.vp-report-item a { font-size: 13.5px; font-weight: 600; color: #1d4ed8; text-decoration: none; }
.vp-report-item a:hover { text-decoration: underline; }
.vp-report-item .ts { font-size: 12px; color: #9ca3af; margin-top: 2px; }
.btn-del-report {
  background: #fef2f2; border: 1px solid #fecaca;
  color: #dc2626; border-radius: 8px;
  padding: 5px 10px; font-size: 12px; font-weight: 600;
  text-decoration: none; transition: background .12s;
  white-space: nowrap;
}
.btn-del-report:hover { background:#fee2e2; color:#b91c1c; }

/* ── Note / Import cards ── */
.vp-note-card, .vp-import-card {
  background: #fff;
  border: 1px solid var(--vp-border);
  border-radius: var(--vp-r);
  box-shadow: var(--vp-shadow);
  padding: 18px 20px;
  height: 100%;
}
.vp-note-card .note-label {
  font-size: 13px; font-weight: 700; color: #dc2626;
  display: flex; align-items: center; gap: 6px;
  margin-bottom: 10px;
}
.vp-import-card .import-label {
  font-size: 13px; font-weight: 700; color: #1d4ed8;
  display: flex; align-items: center; gap: 6px;
  margin-bottom: 10px;
}
.vp-note-card textarea {
  border-radius: 10px; border: 1.5px solid rgba(15,23,42,.11);
  font-size: 14px; background: #f9fafb;
  transition: border-color .15s, box-shadow .15s;
  resize: vertical;
}
.vp-note-card textarea:focus {
  border-color: #2563eb;
  box-shadow: 0 0 0 3px rgba(37,99,235,.13);
  background: #fff;
}

/* ── Lab table ── */
.vp-table-wrap {
  border: 1px solid var(--vp-border);
  border-radius: var(--vp-r);
  /* overflow intentionally NOT set — preserves position:sticky on thead */
}
.vp-table-scroll {
  overflow-x: auto;
  overflow-y: visible;
  /* Sticky thead works because its nearest scroll ancestor is the page itself */
}

#labTable thead th {
  position: sticky; top: 0; z-index: 50;
  background: #f8fafc;
  font-size: 11px; font-weight: 700;
  text-transform: uppercase; letter-spacing: .06em; color: #64748b;
  padding: 12px 14px !important;
  border-bottom: 2px solid rgba(15,23,42,.08);
  white-space: nowrap;
  /* Shadow appears when header is stuck — makes it visually "lift" above rows */
  box-shadow: 0 2px 8px rgba(15,23,42,.08);
}
/* Rounded corners on first/last sticky th */
#labTable thead th:first-child { border-radius: 14px 0 0 0; }
#labTable thead th:last-child  { border-radius: 0 14px 0 0; }
#labTable tbody td {
  padding: 12px 14px !important;
  font-size: 14px;
  vertical-align: middle;
  border-color: rgba(15,23,42,.05);
  color: #111827;
}
#labTable tbody tr { transition: background .1s; }
#labTable tbody tr:hover { background: #f0f7ff; }

/* Group header row */
.vp-group-row td {
  background: linear-gradient(90deg, #eff6ff, #f8fafc) !important;
  border-top: 2px solid #bfdbfe !important;
  padding: 10px 14px !important;
}
.vp-group-label {
  font-size: 12.5px; font-weight: 800; color: #1d4ed8;
  display: flex; align-items: center; gap: 7px;
}
.btn-del-group {
  background: #fef2f2; border: 1px solid #fecaca;
  color: #dc2626; border-radius: 8px;
  padding: 4px 10px; font-size: 12px; font-weight: 600;
  transition: background .12s; cursor: pointer;
}
.btn-del-group:hover { background: #fee2e2; }

/* Result cell */
.result-val { font-weight: 700; color: #111827; font-size: 14px; font-variant-numeric: tabular-nums; }

/* Previous result */
.prev-val { color: #6b7280; font-size: 13.5px; font-variant-numeric: tabular-nums; }
.prev-val.has-value { color: #0369a1; font-weight: 700; }

/* Flag cell */
.flag-high { color: #dc2626; font-weight: 800; font-size: 14px; }
.flag-low  { color: #2563eb; font-weight: 800; font-size: 14px; }
.flag-none { color: #6b7280; font-size: 13.5px; }

/* Status badge */
.vp-badge {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 3px 9px; border-radius: 999px;
  font-size: 11.5px; font-weight: 700; white-space: nowrap;
}
.vp-badge-done { background:#f0fdf4; color:#15803d; border:1px solid #86efac; }
.vp-badge-proc { background:#fffbeb; color:#b45309; border:1px solid #fcd34d; }

/* Edit link */
.edit-link {
  color: #1d4ed8; text-decoration: none; font-weight: 600;
  display: inline-flex; align-items: center; gap: 5px;
  font-size: 13.5px;
}
.edit-link:hover { color: #1e40af; text-decoration: underline; }

/* Delete test btn */
.btn-del-test {
  width: 30px; height: 30px; border-radius: 8px;
  background: #fef2f2; border: 1px solid #fecaca;
  color: #dc2626; display: inline-flex; align-items: center; justify-content: center;
  transition: background .12s; cursor: pointer;
  font-size: 12px;
}
.btn-del-test:hover { background: #fee2e2; }

/* ── Bulk action bar ── */
.vp-bulk-bar {
  display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
  margin-top: 14px;
  padding: 12px 16px;
  background: #f8fafc;
  border: 1px solid var(--vp-border);
  border-radius: 10px;
}
.vp-bulk-bar select {
  border-radius: 9px; border: 1.5px solid rgba(15,23,42,.11);
  font-size: 13.5px; padding: 7px 12px;
}
.btn-bulk-apply {
  background: #16a34a; color: #fff; border: none;
  border-radius: 9px; padding: 7px 16px;
  font-size: 13.5px; font-weight: 600; transition: opacity .15s;
}
.btn-bulk-apply:hover { opacity:.85; }
.btn-bulk-delete {
  background: #fef2f2; color: #dc2626;
  border: 1px solid #fecaca; border-radius: 9px;
  padding: 7px 16px; font-size: 13.5px; font-weight: 600;
  transition: background .12s;
}
.btn-bulk-delete:hover { background: #fee2e2; }

/* ── Pagination ── */
.vp-pagination .page-link {
  border-radius: 9px !important; margin: 0 2px;
  font-size: 13px; font-weight: 600;
  border-color: rgba(15,23,42,.10); color: #374151;
  padding: 6px 13px;
}
.vp-pagination .page-item.active .page-link {
  background: var(--vp-blue); border-color: var(--vp-blue);
  box-shadow: 0 3px 10px rgba(37,99,235,.3);
}
.vp-pagination .page-item.disabled .page-link { color: #cbd5e1; }

/* ── Edit patient btn ── */
.btn-edit-patient {
  background: #eff6ff; color: #1d4ed8;
  border: 1px solid #bfdbfe; border-radius: 9px;
  padding: 7px 14px; font-size: 13px; font-weight: 600;
  display: inline-flex; align-items: center; gap: 6px;
  transition: background .12s;
}
.btn-edit-patient:hover { background: #dbeafe; }

/* ── Modal polish ── */
.vp-modal .modal-content {
  border-radius: 16px; border: 1px solid var(--vp-border);
  box-shadow: 0 20px 60px rgba(15,23,42,.14); overflow:hidden;
}
.vp-modal .modal-header {
  background: linear-gradient(120deg, #1e3a5f, #2563eb);
  color: #fff; border: none; padding: 17px 22px;
}
.vp-modal .modal-title { font-weight: 700; font-size: 15.5px; }
.vp-modal .btn-close { filter: invert(1) opacity(.8); }
.vp-modal .modal-body { padding: 20px 22px; }
.vp-modal .modal-footer {
  border-top: 1px solid var(--vp-border);
  padding: 13px 22px; background: #f8fafc;
}
.vp-modal label {
  font-size: 11px; font-weight: 700;
  text-transform: uppercase; letter-spacing: .06em;
  color: #6b7280; margin-bottom: 5px;
}
.vp-modal .form-control, .vp-modal .form-select {
  border-radius: 9px; border: 1.5px solid rgba(15,23,42,.11);
  font-size: 14px; background: #f9fafb;
  transition: border-color .15s, box-shadow .15s;
}
.vp-modal .form-control:focus, .vp-modal .form-select:focus {
  border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.13);
  background: #fff;
}

/* ── Alert ── */
.vp-alert-deleted {
  background: #f0fdf4; border: 1px solid #86efac; color: #15803d;
  border-radius: 10px; padding: 12px 18px; font-size: 14px; font-weight:500;
  display:flex; align-items:center; gap:10px; margin-bottom:14px;
}
</style>

<div style="max-width:1500px; margin:0 auto;">

  <?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success" style="border-radius:12px;"><?= $_SESSION['success_message'] ?></div>
    <?php unset($_SESSION['success_message']); ?>
  <?php endif; ?>
  <?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger" style="border-radius:12px;"><?= $_SESSION['error_message'] ?></div>
    <?php unset($_SESSION['error_message']); ?>
  <?php endif; ?>

  <!-- ── Hero ── -->
  <div class="vp-hero">
    <div style="position:relative;z-index:1;">
      <h4><i class="bi bi-person-vcard me-2" style="opacity:.85;"></i>Patient Report</h4>
      <p>Patient details, uploaded reports, admin notes, and lab test results</p>
    </div>
    <div style="position:relative;z-index:1;">
      <?php
        $status = strtolower($patient['status'] ?? 'processing');
        $isOk = ($status === 'completed');
      ?>
      <div class="vp-status <?= $isOk ? 'vp-status-completed' : 'vp-status-processing' ?>">
        <span class="vp-status-dot"></span>
        <?= ucfirst($status) ?>
      </div>
    </div>
  </div>

  <?php if (isset($_GET['deleted'])): ?>
    <div class="vp-alert-deleted">
      <i class="bi bi-check-circle-fill fs-5"></i> Report deleted successfully.
    </div>
  <?php endif; ?>

  <!-- ── Patient Info Card ── -->
  <div class="vp-card">
    <div class="vp-card-head">
      <span class="head-title">
        <i class="bi bi-person-lines-fill text-primary"></i> Patient Information
      </span>
      <?php if ($role !== 'clinic_user'): ?>
        <button class="btn-edit-patient" data-bs-toggle="modal" data-bs-target="#editPatientModal">
          <i class="bi bi-pencil-square"></i> Edit Info
        </button>
      <?php endif; ?>
    </div>
    <div class="vp-card-body">
      <div class="pt-info-grid">
        <div class="pt-info-item">
          <span class="label">Full Name</span>
          <span class="value"><?= htmlspecialchars($patient['name']) ?></span>
        </div>
        <div class="pt-info-item">
          <span class="label">Clinic</span>
          <span class="value"><?= htmlspecialchars($patient['clinic_name']) ?></span>
        </div>
        <div class="pt-info-item">
          <span class="label">HN</span>
          <span class="value" style="font-family:monospace;"><?= htmlspecialchars($patient['hn']) ?></span>
        </div>
        <div class="pt-info-item">
          <span class="label">LN</span>
          <span class="value" style="font-family:monospace;"><?= htmlspecialchars($patient['ln']) ?></span>
        </div>
        <div class="pt-info-item">
          <span class="label">Age</span>
          <span class="value"><?= htmlspecialchars($patient['age']) ?></span>
        </div>
        <div class="pt-info-item">
          <span class="label">Sex</span>
          <span class="value"><?= htmlspecialchars($patient['sex']) ?></span>
        </div>
        <div class="pt-info-item">
          <span class="label">Doctor</span>
          <span class="value"><?= htmlspecialchars($patient['doctor']) ?></span>
        </div>
        <div class="pt-info-item">
          <span class="label">Registered</span>
          <span class="value"><i class="bi bi-calendar3 me-1" style="opacity:.5;font-size:11px;"></i><?= htmlspecialchars($patient['register_date']) ?></span>
        </div>
      </div>

      <?php if ($role !== 'clinic_user'): ?>
        <div class="mt-4">
          <a href="upload_pdf.php?id=<?= $id ?>" class="btn-upload-pdf">
            <i class="bi bi-file-earmark-arrow-up"></i> Upload PDF Files
          </a>
        </div>
      <?php endif; ?>

      <!-- Uploaded reports -->
      <?php
        $report_files = $conn->query("SELECT * FROM patient_reports WHERE patient_id = $id ORDER BY uploaded_at DESC");
        if ($report_files && $report_files->num_rows > 0):
      ?>
        <div class="mt-4">
          <div style="font-size:13px;font-weight:700;color:#374151;margin-bottom:10px;">
            <i class="bi bi-folder2-open me-1 text-primary"></i> Uploaded Reports
          </div>
          <?php while ($r = $report_files->fetch_assoc()): ?>
            <div class="vp-report-item">
              <div>
                <a href="<?= htmlspecialchars($r['file_path']) ?>" target="_blank">
                  <i class="bi bi-file-earmark-pdf me-1" style="color:#dc2626;"></i>
                  <?= preg_replace('/(_\d{10})\.pdf$/', '.pdf', basename($r['file_path'])) ?>
                </a>
                <div class="ts"><?= htmlspecialchars($r['uploaded_at']) ?></div>
              </div>
              <?php if (in_array($role, ['superadmin', 'admin'])): ?>
                <a href="view_patient.php?id=<?= $id ?>&delete_report_id=<?= $r['id'] ?>"
                   onclick="return confirm('Are you sure to delete this report file?')"
                   class="btn-del-report">
                  <i class="bi bi-trash3 me-1"></i> Delete
                </a>
              <?php endif; ?>
            </div>
          <?php endwhile; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Note + Import Row ── -->
  <div class="row g-3 mb-3">
    <div class="col-md-6">
      <div class="vp-note-card">
        <div class="note-label"><i class="bi bi-pin-angle-fill"></i> Admin Note</div>
        <?php if ($role !== 'clinic_user'): ?>
          <form method="post">
            <textarea name="admin_note" rows="4" class="form-control mb-2 w-100"><?= htmlspecialchars($patient['admin_note'] ?? '') ?></textarea>
            <button type="submit" name="save_note" class="btn btn-sm btn-success" style="border-radius:9px; font-weight:600;">
              <i class="bi bi-floppy me-1"></i> Save Note
            </button>
          </form>
        <?php else: ?>
          <div class="alert alert-info" style="border-radius:10px;">
            <p class="mb-0"><?= nl2br(htmlspecialchars($patient['admin_note'] ?? 'No note available yet.')) ?></p>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($role !== 'clinic_user'): ?>
    <div class="col-md-6">
      <div class="vp-import-card">
        <div class="import-label"><i class="bi bi-file-earmark-excel"></i> Import Lab Tests from Excel</div>
        <form method="POST" enctype="multipart/form-data">
          <input type="file" name="excel_file" accept=".xlsx,.xls" required
                 class="form-control mb-2" style="border-radius:9px; font-size:14px;">
          <button type="submit" name="import_excel"
                  class="btn btn-primary btn-sm" style="border-radius:9px; font-weight:600;">
            <i class="bi bi-upload me-1"></i> Upload & Import
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── Lab Tests Card ── -->
  <div class="vp-card">
    <div class="vp-card-head">
      <span class="head-title">
        <i class="bi bi-journal-medical text-primary"></i>
        Lab Tests
        <span style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:999px;padding:2px 9px;font-size:11px;font-weight:700;"><?= number_format($total_tests) ?> tests</span>
      </span>
      <?php if ($role !== 'clinic_user'): ?>
        <span style="font-size:12px;color:#9ca3af;">Click a test name to edit</span>
      <?php endif; ?>
    </div>
    <div class="vp-card-body">
      <form method="POST">
        <?php
        $all_tests_grouped = [];
        while ($row = $tests->fetch_assoc()) {
            $group = $row['test_group'] ?: 'Others';
            $all_tests_grouped[$group][] = $row;
        }
        ?>

        <div class="vp-table-wrap">
            <table class="table table-hover align-middle mb-0" id="labTable">
              <thead>
                <tr>
                  <?php if ($role !== 'clinic_user'): ?>
                    <th style="width:44px;"><input type="checkbox" id="selectAll" style="width:16px;height:16px;cursor:pointer;"></th>
                    <th style="width:44px;">#</th>
                  <?php else: ?>
                    <th style="width:44px;">#</th>
                  <?php endif; ?>
                  <th>Test Name</th>
                  <th>Result</th>
                  <th>Prev. Result</th>
                  <th>Flag</th>
                  <th>Unit</th>
                  <th>Reference Range</th>
                  <th>Method</th>
                  <th>Status</th>
                  <?php if ($role !== 'clinic_user'): ?>
                    <th style="width:50px;" class="text-center">Del</th>
                  <?php endif; ?>
                </tr>
              </thead>
              <tbody>
                <?php $index = ($page - 1) * $per_page + 1; ?>
                <?php foreach ($all_tests_grouped as $group_name => $group_tests): ?>

                  <!-- Group header -->
                  <tr class="vp-group-row">
                    <td colspan="<?= $role !== 'clinic_user' ? '11' : '10' ?>">
                      <div class="d-flex align-items-center justify-content-between">
                        <div class="vp-group-label">
                          <i class="bi bi-layers" style="font-size:13px;"></i>
                          <?= htmlspecialchars($group_name) ?>
                        </div>
                        <?php if ($role !== 'clinic_user'): ?>
                          <button type="submit" name="delete_group"
                                  value="<?= htmlspecialchars($group_name) ?>"
                                  class="btn-del-group"
                                  onclick="return confirm('Remove group <?= htmlspecialchars(addslashes($group_name)) ?> and all its tests?')">
                            <i class="bi bi-trash3 me-1"></i> Remove Group
                          </button>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>

                  <!-- Test rows -->
                  <?php foreach ($group_tests as $row):
                    $test_name = trim($row['test_name'] ?? '');
                    $current_patient_id = (int)$row['patient_id'];
                    $latest_result = '-';

                    // 1) Exact HN
                    if ($full_hn !== '') {
                        $sqlExact = "SELECT l.result FROM lab_results l JOIN patients p ON l.patient_id = p.id
                                     WHERE p.hn = ? AND p.clinic_id = ? AND l.test_name = ? AND p.id < ?
                                     AND l.result IS NOT NULL AND l.result <> '' AND l.result <> '-'
                                     ORDER BY p.id DESC, l.id DESC LIMIT 1";
                        $stmt = $conn->prepare($sqlExact);
                        $stmt->bind_param("sisi", $full_hn, $clinic_id_current, $test_name, $current_patient_id);
                        $stmt->execute(); $stmt->bind_result($prev);
                        if ($stmt->fetch()) { $latest_result = $prev; }
                        $stmt->close();
                    }
                    // 2) Same LN
                    if ($latest_result === '-' && $full_ln !== '') {
                        $sqlLn = "SELECT l.result FROM lab_results l JOIN patients p ON l.patient_id = p.id
                                  WHERE p.ln = ? AND p.clinic_id = ? AND l.test_name = ? AND p.id < ?
                                  AND l.result IS NOT NULL AND l.result <> '' AND l.result <> '-'
                                  ORDER BY p.id DESC, l.id DESC LIMIT 1";
                        $stmt = $conn->prepare($sqlLn);
                        $stmt->bind_param("sisi", $full_ln, $clinic_id_current, $test_name, $current_patient_id);
                        $stmt->execute(); $stmt->bind_result($prev2);
                        if ($stmt->fetch()) { $latest_result = $prev2; }
                        $stmt->close();
                    }
                    // 3) HN prefix + name match
                    if ($latest_result === '-' && $hn_prefix !== '' && $hn_prefix !== $full_hn) {
                        $sqlPrefix = "SELECT l.result FROM lab_results l JOIN patients p ON l.patient_id = p.id
                                      WHERE p.hn LIKE CONCAT(?, '/%') AND p.clinic_id = ? AND l.test_name = ? AND p.id < ?
                                      AND l.result IS NOT NULL AND l.result <> '' AND l.result <> '-'
                                      AND LOWER(TRIM(REPLACE(p.name, '  ', ' '))) = ?
                                      ORDER BY p.id DESC, l.id DESC LIMIT 1";
                        $stmt = $conn->prepare($sqlPrefix);
                        $stmt->bind_param("sisis", $hn_prefix, $clinic_id_current, $test_name, $current_patient_id, $patient_name_norm);
                        $stmt->execute(); $stmt->bind_result($prev3);
                        if ($stmt->fetch()) { $latest_result = $prev3; }
                        $stmt->close();
                    }

                    $flag_val = $row['flag'] ?: '-';
                    $flag_class = '';
                    if (in_array(strtoupper(trim($flag_val)), ['H', 'HIGH', 'HH'])) $flag_class = 'flag-high';
                    elseif (in_array(strtoupper(trim($flag_val)), ['L', 'LOW', 'LL'])) $flag_class = 'flag-low';
                    else $flag_class = 'flag-none';

                    $row_status = strtolower($row['status'] ?? 'processing');
                  ?>
                  <tr>
                    <?php if ($role !== 'clinic_user'): ?>
                      <td style="text-align:center;"><input type="checkbox" name="test_ids[]" value="<?= $row['id'] ?>" style="width:15px;height:15px;cursor:pointer;"></td>
                      <td><span style="color:#374151;font-size:13px;font-weight:700;"><?= $index++ ?></span></td>
                    <?php else: ?>
                      <td><span style="color:#374151;font-size:13px;font-weight:700;"><?= $index++ ?></span></td>
                    <?php endif; ?>

                    <td>
                      <?php if ($role !== 'clinic_user'): ?>
                        <a href="#" class="edit-link"
                           data-id="<?= $row['id'] ?>"
                           data-result="<?= htmlspecialchars($row['result']) ?>"
                           data-flag="<?= htmlspecialchars($row['flag']) ?>"
                           data-unit="<?= htmlspecialchars($row['unit']) ?>"
                           data-range="<?= htmlspecialchars($row['normal_range']) ?>"
                           data-method="<?= htmlspecialchars($row['method']) ?>"
                           data-status="<?= $row['status'] ? ucfirst($row['status']) : '-' ?>">
                          <i class="bi bi-pencil" style="font-size:11px;opacity:.6;"></i>
                          <?= htmlspecialchars($row['test_name']) ?>
                        </a>
                      <?php else: ?>
                        <span style="font-weight:600;color:#111827;"><?= htmlspecialchars($row['test_name']) ?></span>
                      <?php endif; ?>
                    </td>

                    <td><span class="result-val"><?= htmlspecialchars($row['result']) ?></span></td>

                    <td>
                      <span class="prev-val <?= $latest_result !== '-' ? 'has-value' : '' ?>">
                        <?= htmlspecialchars($latest_result) ?>
                      </span>
                    </td>

                    <td><span class="<?= $flag_class ?>"><?= htmlspecialchars($flag_val) ?></span></td>
                    <td style="color:#374151;"><?= htmlspecialchars($row['unit']) ?></td>
                    <td style="max-width:200px;word-break:break-word;color:#111827;text-align:center;font-size:13.5px;font-weight:500;">
                      <?= ltrim(str_replace("\xc2\xa0", ' ', trim($row['normal_range']))) ?>
                    </td>
                    <td style="color:#374151;font-size:13.5px;"><?= htmlspecialchars($row['method']) ?></td>

                    <td>
                      <span class="vp-badge <?= $row_status === 'completed' ? 'vp-badge-done' : 'vp-badge-proc' ?>">
                        <span style="width:6px;height:6px;border-radius:50%;display:inline-block;background:<?= $row_status === 'completed' ? '#16a34a' : '#d97706' ?>;"></span>
                        <?= ucfirst($row_status) ?>
                      </span>
                    </td>

                    <?php if ($role !== 'clinic_user'): ?>
                      <td class="text-center">
                        <button type="submit" name="delete_test_id" value="<?= $row['id'] ?>"
                                onclick="return confirm('Delete this test?')"
                                class="btn-del-test" title="Delete">
                          <i class="bi bi-trash3"></i>
                        </button>
                      </td>
                    <?php endif; ?>
                  </tr>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              </tbody>
            </table>
        </div>

        <!-- Bulk action bar -->
        <?php if ($role !== 'clinic_user'): ?>
          <div class="vp-bulk-bar">
            <span style="font-size:13px;font-weight:600;color:#374151;">Bulk Action:</span>
            <select name="bulk_status" class="form-select" style="width:auto;">
              <option value="">— Set Status —</option>
              <option value="completed">✅ Completed</option>
              <option value="processing">⏳ Processing</option>
            </select>
            <button type="submit" name="apply_bulk_status" class="btn-bulk-apply">Apply to Selected</button>
            <button type="submit" name="delete_selected" class="btn-bulk-delete">
              <i class="bi bi-trash3 me-1"></i> Delete Selected
            </button>
          </div>
        <?php endif; ?>
      </form>

      <!-- Pagination -->
      <?php if ($total_pages > 1): ?>
        <nav class="mt-3">
          <ul class="pagination justify-content-center vp-pagination">
            <?php if ($page > 1): ?>
              <li class="page-item"><a class="page-link" href="?id=<?= $id ?>&page=<?= $page - 1 ?>"><i class="bi bi-chevron-left" style="font-size:11px;"></i> Prev</a></li>
            <?php endif; ?>

            <?php
              $start_page = max(1, $page - 2);
              $end_page = min($total_pages, $page + 2);
              if ($start_page > 1) echo '<li class="page-item disabled"><span class="page-link" style="border:0;background:transparent;">…</span></li>';
            ?>
            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
              <li class="page-item <?= $page == $i ? 'active' : '' ?>">
                <a class="page-link" href="?id=<?= $id ?>&page=<?= $i ?>"><?= $i ?></a>
              </li>
            <?php endfor; ?>
            <?php if ($end_page < $total_pages) echo '<li class="page-item disabled"><span class="page-link" style="border:0;background:transparent;">…</span></li>'; ?>

            <?php if ($page < $total_pages): ?>
              <li class="page-item"><a class="page-link" href="?id=<?= $id ?>&page=<?= $page + 1 ?>">Next <i class="bi bi-chevron-right" style="font-size:11px;"></i></a></li>
            <?php endif; ?>
          </ul>
        </nav>
      <?php endif; ?>
    </div>
  </div>

</div><!-- /wrapper -->

<!-- ── Edit Lab Test Modal ── -->
<div class="modal fade vp-modal" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" id="editForm">
      <input type="hidden" name="test_id" id="edit_test_id">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Edit Lab Test</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <div class="mb-1"><label>Result</label></div>
              <input name="result" id="edit_result" class="form-control">
            </div>
            <div class="col-md-6">
              <div class="mb-1"><label>Flag</label></div>
              <input name="flag" id="edit_flag" class="form-control">
            </div>
            <div class="col-md-6">
              <div class="mb-1"><label>Unit</label></div>
              <input name="unit" id="edit_unit" class="form-control">
            </div>
            <div class="col-12">
              <div class="mb-1"><label>Reference Range</label></div>
              <input name="range" id="edit_range" class="form-control">
            </div>
            <div class="col-12">
              <div class="mb-1"><label>Method</label></div>
              <input name="method" id="edit_method" class="form-control">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal" style="border-radius:9px;">Cancel</button>
          <button type="submit" name="save_test" class="btn btn-primary" style="border-radius:9px;font-weight:600;">
            <i class="bi bi-floppy me-1"></i> Save Changes
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ── Edit Patient Modal ── -->
<div class="modal fade vp-modal" id="editPatientModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-person-gear me-2"></i>Edit Patient Information</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label>Full Name</label>
              <input name="edit_name" value="<?= htmlspecialchars($patient['name']) ?>" class="form-control">
            </div>
            <div class="col-md-6">
              <label>HN</label>
              <input name="edit_hn" value="<?= htmlspecialchars($patient['hn']) ?>" class="form-control" style="font-family:monospace;">
            </div>
            <div class="col-md-6">
              <label>LN</label>
              <input name="edit_ln" value="<?= htmlspecialchars($patient['ln']) ?>" class="form-control" style="font-family:monospace;">
            </div>
            <div class="col-md-6">
              <label>Sex</label>
              <select name="edit_sex" class="form-select">
                <option value="Male"   <?= $patient['sex'] == 'Male'   ? 'selected' : '' ?>>Male</option>
                <option value="Female" <?= $patient['sex'] == 'Female' ? 'selected' : '' ?>>Female</option>
              </select>
            </div>
            <div class="col-md-6">
              <label>Age</label>
              <input name="edit_age" value="<?= htmlspecialchars($patient['age']) ?>" class="form-control" type="number" min="0">
            </div>
            <div class="col-12">
              <label>Doctor</label>
              <input name="edit_doctor" value="<?= htmlspecialchars($patient['doctor']) ?>" class="form-control">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal" style="border-radius:9px;">Cancel</button>
          <button type="submit" name="save_patient_info" class="btn btn-success" style="border-radius:9px;font-weight:600;">
            <i class="bi bi-floppy me-1"></i> Save Patient Info
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
document.getElementById('selectAll')?.addEventListener('change', function () {
  document.querySelectorAll('input[name="test_ids[]"]').forEach(cb => cb.checked = this.checked);
});

document.addEventListener("DOMContentLoaded", function () {
  document.querySelectorAll(".edit-link").forEach(link => {
    link.addEventListener("click", function (e) {
      e.preventDefault();
      document.getElementById("edit_test_id").value = this.dataset.id;
      document.getElementById("edit_result").value  = this.dataset.result;
      document.getElementById("edit_flag").value    = this.dataset.flag;
      document.getElementById("edit_unit").value    = this.dataset.unit;
      document.getElementById("edit_range").value   = this.dataset.range;
      document.getElementById("edit_method").value  = this.dataset.method;
      new bootstrap.Modal(document.getElementById("editModal")).show();
    });
  });
});

setTimeout(() => {
  const url = new URL(window.location.href);
  url.searchParams.delete('deleted');
  window.history.replaceState({}, document.title, url.toString());
}, 500);

// ── Floating sticky header ──
(function () {
  const table   = document.getElementById('labTable');
  if (!table) return;

  const realHead = table.querySelector('thead');
  if (!realHead) return;

  // Create a ghost thead that floats fixed at the top
  const ghost = document.createElement('table');
  ghost.className = table.className;
  ghost.style.cssText = [
    'position:fixed',
    'top:0',
    'left:0',
    'z-index:9999',
    'display:none',
    'background:#f8fafc',
    'border-bottom:2px solid rgba(15,23,42,.10)',
    'box-shadow:0 4px 16px rgba(15,23,42,.12)',
    'pointer-events:auto',
    'margin:0',
    'border-collapse:collapse',
    'table-layout:fixed',
  ].join(';');

  const ghostHead = realHead.cloneNode(true);
  ghost.appendChild(ghostHead);
  document.body.appendChild(ghost);

  function syncGhost() {
    const tableRect = table.getBoundingClientRect();
    const headRect  = realHead.getBoundingClientRect();

    // Show ghost when real thead has scrolled above viewport
    const shouldShow = headRect.bottom < 0 && tableRect.bottom > 0;
    ghost.style.display = shouldShow ? 'table' : 'none';

    // When ghost is visible, the first visible row is partially hidden under it.
    // We don't need padding — ghost has pointer-events:none so clicks pass through.
    // But the checkbox in row #1 under the ghost gets visually confused.
    // Solution: make ghost height match real thead, then nothing is blocked.
    if (!shouldShow) return;

    // Match position and width to the real table
    ghost.style.left  = tableRect.left + 'px';
    ghost.style.width = tableRect.width + 'px';

    // Sync each column width
    const realCells  = realHead.querySelectorAll('th');
    const ghostCells = ghostHead.querySelectorAll('th');
    realCells.forEach((th, i) => {
      if (ghostCells[i]) {
        ghostCells[i].style.width      = th.offsetWidth + 'px';
        ghostCells[i].style.minWidth   = th.offsetWidth + 'px';
        ghostCells[i].style.maxWidth   = th.offsetWidth + 'px';
        ghostCells[i].style.background = '#f8fafc';
        ghostCells[i].style.padding    = '12px 14px';
        ghostCells[i].style.fontSize   = '11px';
        ghostCells[i].style.fontWeight = '700';
        ghostCells[i].style.textTransform = 'uppercase';
        ghostCells[i].style.letterSpacing = '.06em';
        ghostCells[i].style.color         = '#64748b';
        ghostCells[i].style.whiteSpace    = 'nowrap';
        ghostCells[i].style.borderBottom  = 'none';
      }
    });
  }

  // Forward checkbox clicks from ghost to real thead
  ghost.addEventListener('change', function(e) {
    if (e.target.type === 'checkbox') {
      const realCheckbox = realHead.querySelector('#selectAll');
      if (realCheckbox) {
        realCheckbox.checked = e.target.checked;
        realCheckbox.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }
  });

  // Keep ghost checkbox in sync with real
  function syncCheckbox() {
    const realCb  = realHead.querySelector('#selectAll');
    const ghostCb = ghostHead.querySelector('#selectAll');
    if (realCb && ghostCb) ghostCb.checked = realCb.checked;
  }

  window.addEventListener('scroll', function() { syncGhost(); syncCheckbox(); }, { passive: true });
  window.addEventListener('resize', syncGhost, { passive: true });
  syncGhost();
})();
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include 'includes/footer.php'; ?>
<?php ob_end_flush(); ?>