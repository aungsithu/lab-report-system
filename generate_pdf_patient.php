<?php
// NO extra spaces/newlines before <?php
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ERROR | E_PARSE);

require 'includes/db.php';
require 'pdf_common.php';
date_default_timezone_set('Asia/Bangkok'); // <— add this

$clinic_id  = (int)($_GET['clinic_id'] ?? 0);
$invoice_id = (int)($_GET['invoice_id'] ?? 0);
if ($clinic_id <= 0 || $invoice_id <= 0) {
  if (ob_get_length()) { ob_end_clean(); }
  die('Missing clinic_id or invoice_id');
}

/* -------- Header info -------- */
/* CHANGED: also fetch i.patient_name and prefer it over clinic name */
$hdr = $conn->query("
  SELECT i.invoice_number, i.created_at, i.patient_name, c.name AS clinic_name
  FROM invoices i
  JOIN clinics c ON c.id = i.clinic_id
  WHERE i.id = {$invoice_id} AND i.clinic_id = {$clinic_id}
")->fetch_assoc();

if (!$hdr) {
  if (ob_get_length()) { ob_end_clean(); }
  die('Invoice not found');
}

$invoice_no   = $hdr['invoice_number'];
$clinic_name  = $hdr['clinic_name'];
$patient_name = trim($hdr['patient_name'] ?? '');
$doc_date = (new DateTimeImmutable($hdr['created_at']))->format('d/m/Y');

/* Prefer patient name; fallback to clinic */
$recipient_display = ($patient_name !== '' ? $patient_name : $clinic_name);

/* -------- Items -------- */
$stmt = $conn->prepare("
  SELECT test_code, test_name, price, discount_percent
  FROM test_prices
  WHERE clinic_id = ? AND invoice_id = ?
  ORDER BY id ASC
");

$stmt->bind_param("ii", $clinic_id, $invoice_id);
$stmt->execute();
$rs = $stmt->get_result();

$rows = [];
$subtotal = $discount_total = $net_total = 0.0;
while ($r = $rs->fetch_assoc()) {
  $price = (float)$r['price'];
  $dpct  = (float)$r['discount_percent'];
  $final = $price * (1 - $dpct/100);

  $subtotal       += $price;
  $discount_total += ($price - $final);
  $net_total      += $final;

  $rows[] = [
    'code'  => trim($r['test_code'] ?? ''),
    'name'  => $r['test_name'],
    'final' => $final
  ];

}

/* -------- PDF setup -------- */
$pdf = new CeltacPDF();
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(true);
$pdf->AddPage();

$padX   = 10;                               // extra content padding
$m      = $pdf->getMargins();
$pageW  = $pdf->getPageWidth();
$pageH  = $pdf->getPageHeight();
$L      = $m['left']  + $padX;
$R      = $pageW - $m['right'] - $padX;
$usableW = $R - $L;

/* -------- Letterhead (NO border) + QR -------- */
$panelY = $pdf->GetY();
$panelH = 36;

// Logo
if (is_file('assets/celtaclogo.png')) {
  $pdf->Image('assets/celtaclogo.png', $L + 6, $panelY + 4, 18);
}

// Company text
$pdf->SetFont($pdf->baseFont, 'B', 12.5);
$pdf->SetXY($L, $panelY + 4);
$pdf->Cell($usableW, 6, 'บริษัท เซลแทค แล๊บ จำกัด (สำนักงานใหญ่)', 0, 2, 'C');

$pdf->SetFont($pdf->baseFont, '', 10);
$pdf->Cell($usableW, 5, '221 ซอยอินทามระ 33 แยก 2 ถนนสุทธิสารวินิจฉัย แขวงรัชดาภิเษก เขตดินแดง กรุงเทพฯ 10400', 0, 2, 'C');
$pdf->Cell($usableW, 5, 'โทร. 0-2275-2498   แฟ็กซ์. 0-2076-6288   เลขประจำตัวผู้เสียภาษี 0105562129981', 0, 2, 'C');

$pdf->Ln(1);
$pdf->SetFont($pdf->baseFont, 'B', 12);
$pdf->Cell($usableW, 6, 'ใบเสนอราคา', 0, 2, 'C');
$pdf->SetFont($pdf->baseFont, '', 9);
$pdf->Cell($usableW, 4, 'QUOTATION', 0, 2, 'C');

// QR (smaller, moved right a bit, with blue background but NO border)
$qrW = 20; $qrH = 20;
$qrX = $L + $usableW - ($qrW + 10);
$qrY = $panelY + 6;

$pdf->SetFillColor(198, 226, 248);
$pdf->Rect($qrX - 4, $qrY - 4, $qrW + 8, $qrH + 8, 'F'); // fill only

if (is_file('assets/linepng.jpg')) {
  $pdf->Image('assets/linepng.jpg', $qrX, $qrY, $qrW, $qrH);
}

/* Move below panel */
$pdf->SetY($panelY + $panelH + 3);

/* -------- Right meta (เลขที่ / วันที่) BELOW panel, right-aligned -------- */
$pdf->SetFont($pdf->baseFont, '', 10.5);
$metaBlockW = 60;                 // block width for the right meta
$metaX      = $L + $usableW - $metaBlockW;
$lineH      = 6;

// เลขที่
$pdf->SetXY($metaX, $pdf->GetY());
$pdf->Cell(22, $lineH, 'เลขที่', 0, 0, 'R');
$pdf->Cell($metaBlockW - 22, $lineH, '  '.$invoice_no, 'B', 1, 'L');

// วันที่
$pdf->SetXY($metaX, $pdf->GetY() + 1.2);
$pdf->Cell(22, $lineH, 'วันที่', 0, 0, 'R');
$pdf->Cell($metaBlockW - 22, $lineH, '  '.$doc_date, 'B', 1, 'L');

/* -------- Recipient lines (เรียน / เรื่อง) -------- */
$pdf->SetFont($pdf->baseFont, '', 11);
$pdf->SetX($L);
$pdf->Cell(12, 7, 'เรียน', 0, 0, 'L');
/* CHANGED: use patient name (or clinic) already prefixed with "คุณ " */
$pdf->Cell($usableW - 12, 7, $recipient_display, 0, 1, 'L');

$pdf->SetX($L);
$pdf->Cell(12, 7, 'เรื่อง', 0, 0, 'L');
$pdf->Cell($usableW - 12, 7, 'ใบเสนอค่าบริการตรวจวิเคราะห์ทางห้องปฏิบัติการ', 0, 1, 'L');

$pdf->Ln(2);

/* -------- Table (No., Description, Price) -------- */
$pdf->SetFont($pdf->baseFont, '', 10);

// widths baseline -> scale to usable
$col = [
  'no'   => 12,
  'code' => 22,
  'desc' => 98,
  'price'=> 30
];

$totalW = array_sum($col);
$scale  = ($totalW > $usableW) ? $usableW / $totalW : 1.0;
foreach ($col as $k => $w) { $col[$k] = round($w * $scale, 2); }
$scaledSum = array_sum($col);
$tblX      = $L + max(0, ($usableW - $scaledSum) / 2);

$th      = 8;
$lineH   = 5.5;
$cellPad = 1.0;

$printHeader = function() use ($pdf, $tblX, $col, $th) {
  $pdf->SetFillColor(242,242,242);
  $pdf->SetXY($tblX, $pdf->GetY());
  $pdf->Cell($col['no'],    $th, 'ลำดับ / No.', 1, 0, 'C', true);
  $pdf->Cell($col['code'],  $th, 'รหัส / Code', 1, 0, 'C', true);
  $pdf->Cell($col['desc'],  $th, 'รายการ / DESCRIPTION', 1, 0, 'C', true);
  $pdf->Cell($col['price'], $th, 'ราคา / PRICE', 1, 1, 'C', true);
};

$printHeader();

$i = 1;
foreach ($rows as $it) {
  $code  = trim($it['code'] ?? '');
  if ($code === '') $code = '-';

  $desc  = trim($it['name'] ?? '');
  $price = number_format((float)$it['final'], 2);

  $descLines = min(2, max(1, $pdf->getNumLines($desc, $col['desc'] - 2*$cellPad)));
  $rowH = max($th, $descLines * $lineH + 2*$cellPad);

  // page break BEFORE drawing
  $bottomMargin = $pdf->getBreakMargin();
  if ($pdf->GetY() + $rowH > ($pageH - $bottomMargin)) {
    $pdf->AddPage();
    $printHeader();
  }

  $x = $tblX; $y = $pdf->GetY();

  // borders
  $pdf->Rect($x,                                            $y, $col['no'],    $rowH);
  $pdf->Rect($x + $col['no'],                               $y, $col['code'],  $rowH);
  $pdf->Rect($x + $col['no'] + $col['code'],                $y, $col['desc'],  $rowH);
  $pdf->Rect($x + $col['no'] + $col['code'] + $col['desc'], $y, $col['price'], $rowH);

  // No.
  $pdf->SetXY($x, $y + ($rowH - $lineH)/2);
  $pdf->MultiCell($col['no'], $lineH, (string)$i, 0, 'C');

  // Code
  $pdf->SetXY($x + $col['no'], $y + ($rowH - $lineH)/2);
  $pdf->MultiCell($col['code'], $lineH, $code, 0, 'C');

  // Description
  $pdf->SetXY($x + $col['no'] + $col['code'] + $cellPad, $y + $cellPad);
  $pdf->MultiCell($col['desc'] - 2*$cellPad, $lineH, $desc, 0, 'L');

  // Price
  $pdf->SetXY($x + $col['no'] + $col['code'] + $col['desc'] + $cellPad, $y + ($rowH - $lineH)/2);
  $pdf->MultiCell($col['price'] - 2*$cellPad, $lineH, $price, 0, 'R');

  $pdf->SetY($y + $rowH);
  $i++;
}


/* -------- Totals block EXACTLY like your sample (inside the table) -------- */
$pdf->SetFont($pdf->baseFont, '', 11);

$hRow   = 10;            // height per total row
$nRows  = 2;             // "รวมราคา" + "รวมทั้งภาษี"
$needH  = $hRow * $nRows;

// page-break guard before drawing totals
$bottomMargin = $pdf->getBreakMargin();
if ($pdf->GetY() + $needH > ($pageH - $bottomMargin)) {
    $pdf->AddPage();
}

// Table edges
$tblLeft  = $tblX;
$tblRight = $tblX + $col['no'] + $col['code'] + $col['desc'] + $col['price'];


// Right totals sub-table width (labels + values)
$totW     = 90;            // tune 80–100 if needed
$labelW   = $totW - 38;    // label part
$valW     = 38;            // value part
$totX     = $tblRight - $totW;

// 1) Draw the BIG LEFT merged cell (spans ลำดับ + รายการ columns for BOTH rows)
$leftW = $totX - $tblLeft;
$y0    = $pdf->GetY();
$pdf->Rect($tblLeft, $y0, $leftW, $needH); // border for merged left area

// 2) Draw the right totals grid (two rows: label + value)
for ($r = 0; $r < $nRows; $r++) {
    $y = $y0 + $r * $hRow;

    // label cell
    $pdf->Rect($totX,           $y, $labelW, $hRow);
    // value cell
    $pdf->Rect($totX + $labelW, $y, $valW,   $hRow);
}

// 3) Fill in labels + values
// Row 1: รวมราคา = $subtotal
$pdf->SetXY($totX, $y0 + 2.2);
$pdf->MultiCell($labelW - 2, 5, 'รวมราคา', 0, 'R', false, 1);

$pdf->SetXY($totX + $labelW, $y0 + 2.2);
$pdf->MultiCell($valW - 2, 5, number_format($subtotal, 2), 0, 'R', false, 1);

// Row 2: รวมทั้งภาษี = $net_total
$pdf->SetXY($totX, $y0 + $hRow + 2.2);
$pdf->MultiCell($labelW - 2, 5, 'รวมทั้งภาษี', 0, 'R', false, 1);

$pdf->SetXY($totX + $labelW, $y0 + $hRow + 2.2);
$pdf->MultiCell($valW - 2, 5, number_format($net_total, 2), 0, 'R', false, 1);

// NEW: "ตัวอักษร ..." in the second (last) row on the left
$pdf->SetFont($pdf->baseFont, '', 10.5);
$pdf->SetXY($tblLeft + 2, $y0 + $hRow + 2.2);  // shift to second row
$pdf->MultiCell($leftW - 4, 5, 'ตัวอักษร  ' . thai_baht_text($net_total), 0, 'L', false, 1);

// move Y to the end of totals block (so next content continues below)
$pdf->SetY($y0 + $needH);

/* -------- Conditions + signatures (never touch footer) -------- */
$pdf->Ln(3);
$pdf->SetFont($pdf->baseFont, '', 10.8);
$condText = implode("\n", [
  'เงื่อนไขการเสนอราคา : กำหนดภายใน 30 วัน',
  'บริษัทฯ ขอสงวนสิทธิ์ในการปรับราคาได้ตามความเหมาะสม ทั้งนี้ก่อนการสั่งซื้อจริงทางลูกค้าจะรับทราบแล้ว',
]);

$gapBetween = 10;
$boxH       = 42;
$boxW       = ($usableW - $gapBetween) / 2;

$condH      = $pdf->getStringHeight($usableW, $condText);
$needH      = $condH + 4 + $boxH;

$bottomMargin = $pdf->getBreakMargin();
$maxContentY  = $pageH - $bottomMargin - 8;

if ($pdf->GetY() + $needH > $maxContentY) {
  $pdf->AddPage();
}

$pdf->SetX($L);
$pdf->MultiCell($usableW, 6, $condText, 0, 'L', false, 1);

$y0 = $pdf->GetY() + 4;
if ($y0 + $boxH > $maxContentY) {
  $pdf->AddPage();
  $y0 = $pdf->GetY();
}

/* -------- Signature boxes -------- */
$pdf->SetFont($pdf->baseFont, '', 11);

$gapBetween = 10;                         // space between the two boxes
$boxW       = ($usableW - $gapBetween) / 2;
$innerPad   = 6;                          // padding around text
$lineH      = 5.8;                        // line height for text
$textW      = $boxW - ($innerPad * 2);

// Left (Company) text
$leftText =
  "ขอแสดงความนับถือ\n\n".
  "ลงชื่อ  ________________________________  ผู้เสนอราคา\n".
  "( __________________________________ )\n\n".
  "วันที่  ____ / ____ / ______\n".
  "สำหรับบริษัท (FOR COMPANY)";

// Right (Customer) text
$rightText =
  "บริษัท/ผู้ได้รับการเสนอราคาเห็นชอบตามที่เสนอข้างต้นแล้ว ตกลงสั่งซื้อ\n".
  "โดยได้ลงลายมือชื่อไว้เป็นหลักฐานสำคัญ พร้อมประทับตราบริษัท\n\n".
  "ลงชื่อ  ________________________________  ผู้ซื้อ/ผู้อนุมัติ\n".
  "( __________________________________ )\n\n".
  "วันที่  ____ / ____ / ______\n".
  "สำหรับลูกค้า (FOR CUSTOMER)";

// Measure heights required for each
$leftH  = $pdf->getStringHeight($textW,  $leftText,  false, true, '', $lineH);
$rightH = $pdf->getStringHeight($textW, $rightText,  false, true, '', $lineH);

// Add padding to each side
$leftBoxH  = $leftH  + ($innerPad * 2);
$rightBoxH = $rightH + ($innerPad * 2);

// Take the taller of the two so both boxes line up nicely
$boxH = max($leftBoxH, $rightBoxH);

// Page-break guard
$bottomMargin = $pdf->getBreakMargin();
$maxY         = $pageH - $bottomMargin - 8;
$y0           = $pdf->GetY();
if ($y0 + $boxH > $maxY) {
    $pdf->AddPage();
    $y0 = $pdf->GetY();
}

// Draw borders with auto height
$pdf->Rect($L,                         $y0, $boxW, $boxH);
$pdf->Rect($L + $boxW + $gapBetween,   $y0, $boxW, $boxH);

// Write text inside with padding
$pdf->SetXY($L + $innerPad, $y0 + $innerPad);
$pdf->MultiCell($textW, $lineH, $leftText, 0, 'C', false, 1);

$pdf->SetXY($L + $boxW + $gapBetween + $innerPad, $y0 + $innerPad);
$pdf->MultiCell($textW, $lineH, $rightText, 0, 'C', false, 1);

// Move cursor below boxes
$pdf->SetY($y0 + $boxH);

/* -------- Output: save a copy + stream -------- */
if (ob_get_length()) { ob_end_clean(); }

// Safe file name
$invoiceSafe = preg_replace('/[^A-Za-z0-9\-_]/', '_', $invoice_no);
$saveDir = __DIR__ . '/generated_quotes';
if (!is_dir($saveDir)) {
    @mkdir($saveDir, 0775, true);
}

// Save to server
$serverPath = $saveDir . "/Patient-Quotation-{$invoiceSafe}.pdf";
$pdfBytes   = $pdf->Output('', 'S'); // capture as string
file_put_contents($serverPath, $pdfBytes);

// Stream to browser
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="Patient-Quotation-'.$invoiceSafe.'.pdf"');
echo $pdfBytes;
exit;

