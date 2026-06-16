<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ob_start();

require 'includes/db.php';
require_once 'tcpdf_min/tcpdf.php'; // <- adjust if your TCPDF path differs

// -------- Input --------
$clinic_id  = intval($_GET['clinic_id'] ?? 0);
$invoice_id = intval($_GET['invoice_id'] ?? 0);
if ($invoice_id <= 0 || $clinic_id <= 0) {
    die("Invalid clinic or invoice ID");
}

// -------- Fetch invoice header --------
$inv = $conn->query("
    SELECT i.invoice_number, i.created_at, c.name AS clinic_name
    FROM invoices i
    JOIN clinics c ON c.id = i.clinic_id
    WHERE i.id = $invoice_id AND i.clinic_id = $clinic_id
")->fetch_assoc();

if (!$inv) {
    die("Invoice not found");
}

$invoice_number = $inv['invoice_number'] ?: 'Unknown';
$invoice_date   = $inv['created_at'] ?: date('Y-m-d H:i');
$clinic_name    = $inv['clinic_name'] ?: 'Unknown Clinic';

// -------- Fetch items --------
$stmt = $conn->prepare("
    SELECT test_name, price, discount_percent,
           (price - (price * discount_percent / 100)) AS final_price
    FROM test_prices
    WHERE clinic_id = ? AND invoice_id = ?
    ORDER BY id ASC
");
$stmt->bind_param("ii", $clinic_id, $invoice_id);
$stmt->execute();
$items_rs = $stmt->get_result();
$items = [];
$subtotal = 0.0;
$discount_total = 0.0;
$net_total = 0.0;

while ($row = $items_rs->fetch_assoc()) {
    $price    = (float)$row['price'];
    $discount = (float)$row['discount_percent'];
    $final    = (float)$row['final_price'];

    $subtotal       += $price;
    $discount_total += ($price * $discount / 100);
    $net_total      += $final;

    $items[] = [
        'test_name' => $row['test_name'],
        'price'     => $price,
        'discount'  => $discount,
        'final'     => $final,
    ];
}

// ===== TCPDF setup =====
class MYPDF extends TCPDF {
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Page '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(), 0, 0, 'R');
    }
}

$pdf = new MYPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('Celtac Lab');
$pdf->SetAuthor('Celtac Lab');
$pdf->SetTitle('Invoice '.$invoice_number);

// margins similar to your FPDF usage
$pdf->SetMargins(12, 12, 12);
$pdf->SetAutoPageBreak(TRUE, 18);
$pdf->AddPage();

// ---- Font: try TH Sarabun (Thai) + fallback ----
$baseFont = 'helvetica';
try {
    // If you have THSarabunNew.ttf, register once and then you can reuse the name it returns.
    // Place the TTF at: tcpdf_min/fonts/THSarabunNew.ttf  (or adjust path below)
    $sarabunPath = __DIR__ . '/tcpdf_min/fonts/THSarabunNew.ttf';
    if (file_exists($sarabunPath)) {
        $sarabun = TCPDF_FONTS::addTTFfont($sarabunPath, 'TrueTypeUnicode', '', 32);
        if ($sarabun) $baseFont = $sarabun;
    }
} catch (Exception $e) { /* fallback keeps helvetica */ }

// ===== Header graphics & address (replicates your FPDF placement) =====

// Left logo
// args: file, x, y, width (height auto), type
if (file_exists('assets/celtaclogo.png')) {
    $pdf->Image('assets/celtaclogo.png', 12, 10, 12);
}

// Right image
if (file_exists('assets/test.jpg')) {
    $pdf->Image('assets/test.jpg', 170, 10, 25);
}

// Address block
$pdf->SetFont($baseFont, '', 16);
$pdf->SetXY(35, 10);
$addr  = "Address\n221 Inthamara 33 Alley, Din Daeng, Bangkok 10400\n";
$addr .= "Phone: 082-6291915, 065-394-2446\n";
$addr .= "email: celtaclab221@gmail.com   www.celtaclab.com";
$pdf->MultiCell(130, 5, $addr, 0, 'L', false, 1, '', '', true, 0, false, true, 0);

// Invoice header text
$pdf->Ln(5);
$pdf->SetFont('arial', 'B', 13); // keep Arial/Bold look similar
$pdf->Cell(0, 10, 'Clinic Name - '.$clinic_name, 0, 1);
$pdf->SetFont('arial', '', 12);
$pdf->Cell(0, 8, 'Invoice No: '.$invoice_number, 0, 1);
$pdf->Cell(0, 8, 'Date: '.$invoice_date, 0, 1);
$pdf->Ln(5);

// ===== Table (keep your column layout) =====
// Your FPDF widths: col1 = 80, col2 = 35, col3 = 35, col4 = 40  (sum = 190)
// A4 printable width ~ 186mm with our margins; very close. We'll keep 80/35/35/36 to fit.
// To match exact visuals, we'll center within page by margins and keep borders like FPDF.

$col1 = 80; $col2 = 35; $col3 = 35; $col4 = 36; // total = 186
$pdf->SetFont('arial', 'B', 12);

// Build HTML table so borders render nicely
$thead = '
<table border="1" cellpadding="4" cellspacing="0" width="100%">
  <tr style="font-weight:bold; background-color:#f5f5f5;">
    <td width="'.$col1.'">Test Name</td>
    <td width="'.$col2.'" align="right">Price (THB)</td>
    <td width="'.$col3.'" align="right">Discount (%)</td>
    <td width="'.$col4.'" align="right">Final Price (THB)</td>
  </tr>
';
$tbody = '';
$pdf->SetFont('arial', '', 12);

foreach ($items as $it) {
    // Protect text for HTML
    $name = htmlspecialchars($it['test_name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $p = number_format((float)$it['price'], 2);
    $d = number_format((float)$it['discount'], 2);
    $f = number_format((float)$it['final'], 2);

    $tbody .= '
      <tr>
        <td width="'.$col1.'">'.$name.'</td>
        <td width="'.$col2.'" align="right">'.$p.'</td>
        <td width="'.$col3.'" align="right">'.$d.'</td>
        <td width="'.$col4.'" align="right">'.$f.'</td>
      </tr>
    ';
}

$tfoot = '
  <tr style="font-weight:bold;">
    <td width="'.($col1 + $col2 + $col3).'" align="right">Subtotal</td>
    <td width="'.$col4.'" align="right">'.number_format($subtotal, 2).'</td>
  </tr>
  <tr style="font-weight:bold;">
    <td width="'.($col1 + $col2 + $col3).'" align="right">Total Discount</td>
    <td width="'.$col4.'" align="right">'.number_format($discount_total, 2).'</td>
  </tr>
  <tr style="font-weight:bold;">
    <td width="'.($col1 + $col2 + $col3).'" align="right">Net Total</td>
    <td width="'.$col4.'" align="right">'.number_format($net_total, 2).'</td>
  </tr>
</table>
';

// IMPORTANT: always pass a full <table> when using rows (prevents the TCPDF array_push NULL error)
$pdf->writeHTML($thead . $tbody . $tfoot, true, false, true, false, '');

// Output
ob_end_clean();
$pdf->Output('Invoice-'.$invoice_number.'.pdf', 'I');
exit;
