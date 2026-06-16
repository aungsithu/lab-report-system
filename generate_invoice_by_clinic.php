<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ob_start();

require 'includes/db.php';
require_once('fpdf/fpdf.php');

$clinic_id = intval($_GET['clinic_id'] ?? 0);
$invoice_id = intval($_GET['invoice_id'] ?? 0);
if ($invoice_id <= 0) {
    die("Invalid clinic or invoice ID");
}


// ====== 📋 Fetch Invoice Number ======
$invoice = $conn->query("SELECT invoice_number, created_at FROM invoices WHERE id = $invoice_id AND clinic_id = $clinic_id")->fetch_assoc();
$invoice_number = $invoice['invoice_number'] ?? 'Unknown';
$invoice_date = $invoice['created_at'] ?? date('Y-m-d H:i');

// ====== 📋 Fetch Clinic Info ======
$clinic = $conn->query("SELECT name FROM clinics WHERE id = $clinic_id")->fetch_assoc();
$clinic_name = $clinic['name'] ?? 'Unknown Clinic';

// ====== 🦪 Fetch Test Prices For Invoice ======
$stmt = $conn->prepare("SELECT test_name, price, discount_percent, (price - (price * discount_percent / 100)) AS final_price FROM test_prices WHERE clinic_id = ? AND invoice_id = ?");
$stmt->bind_param("ii", $clinic_id, $invoice_id);
$stmt->execute();
$results = $stmt->get_result();

// ====== 🖨 Generate PDF ======
$pdf = new FPDF();
$pdf->AddPage();
$pdf->Image('assets/celtaclogo.png', 10, 10, 12);
$pdf->Image('assets/test.jpg', 170, 10, 25);

$pdf->AddFont('THSarabunNew', '', 'THSarabunNew.php');
$pdf->SetFont('THSarabunNew', '', 16);
$pdf->SetXY(35, 10);
$pdf->MultiCell(130, 5,
    "Address\n221 Inthamara 33 Alley, Din Daeng, Bangkok 10400\nPhone: 082-6291915, 065-394-2446\nemail: celtaclab221@gmail.com   www.celtaclab.com",
    0, 'L'
);

// ====== 📄 Invoice Header ======
$pdf->SetFont('Arial', 'B', 13);
$pdf->Ln(5);
$pdf->Cell(0, 10, 'Clinic Name - ' . $clinic_name, 0, 1);

$pdf->SetFont('Arial', '', 12);
$pdf->Cell(0, 8, 'Invoice No: ' . $invoice_number, 0, 1);
$pdf->Cell(0, 8, 'Date: ' . $invoice_date, 0, 1);
$pdf->Ln(5);

// ====== 📊 Table Setup ======
$col1 = 80; $col2 = 35; $col3 = 35; $col4 = 40;
$tableWidth = $col1 + $col2 + $col3 + $col4;
$pageWidth = 210;
$margin = ($pageWidth - $tableWidth) / 2;

// ====== 📊 Table Header ======
$pdf->SetFont('Arial', 'B', 12);
$pdf->SetX($margin);
$pdf->Cell($col1, 8, 'Test Name', 1);
$pdf->Cell($col2, 8, 'Price (THB)', 1);
$pdf->Cell($col3, 8, 'Discount (%)', 1);
$pdf->Cell($col4, 8, 'Final Price (THB)', 1);
$pdf->Ln();

// ====== 📊 Table Rows ======
$pdf->SetFont('Arial', '', 12);
$total = 0;
$subtotal = 0;
$discount_total = 0;

while ($row = $results->fetch_assoc()) {
    $price = $row['price'];
    $discount = $row['discount_percent'];
    $final = $row['final_price'];

    $subtotal += $price;
    $discount_total += ($price * $discount / 100);
    $total += $final;

    $pdf->SetX($margin);
    $pdf->Cell($col1, 8, iconv('UTF-8', 'windows-1252', $row['test_name']), 1);
    $pdf->Cell($col2, 8, number_format($price, 2), 1);
    $pdf->Cell($col3, 8, number_format($discount, 2), 1);
    $pdf->Cell($col4, 8, number_format($final, 2), 1);
    $pdf->Ln();
}

// ====== 💰 Total Rows ======
$pdf->SetFont('Arial', 'B', 12);
$pdf->SetX($margin);
$pdf->Cell($col1 + $col2 + $col3, 8, 'Subtotal', 1);
$pdf->Cell($col4, 8, number_format($subtotal, 2), 1);
$pdf->Ln();

$pdf->SetX($margin);
$pdf->Cell($col1 + $col2 + $col3, 8, 'Total Discount', 1);
$pdf->Cell($col4, 8, number_format($discount_total, 2), 1);
$pdf->Ln();

$pdf->SetX($margin);
$pdf->Cell($col1 + $col2 + $col3, 8, 'Net Total', 1);
$pdf->Cell($col4, 8, number_format($total, 2), 1);

// ====== 🔚 Output PDF ======
ob_end_clean();
$pdf->Output("Invoice-$invoice_number.pdf", 'I');
exit;
