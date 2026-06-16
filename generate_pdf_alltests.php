<?php
// NO BOM, no whitespace before `<?php`
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ERROR | E_PARSE);

require 'includes/db.php';
require 'pdf_common.php';
date_default_timezone_set('Asia/Bangkok');

/* ------------ Inputs ------------ */
$clinic_id = isset($_GET['clinic_id']) ? (int)$_GET['clinic_id'] : 0;
$quote_no  = trim((string)($_GET['quote_no'] ?? ''));  // optional manual quotation number

if ($clinic_id <= 0) {
    if (ob_get_length()) { ob_end_clean(); }
    die('Missing clinic_id');
}

/* ------------ Fetch clinic meta ------------ */
$clinic_name = $clinic_addr = $clinic_phone = $clinic_email = $company_name = '';

$stmt = $conn->prepare("
    SELECT c.name, c.company_name, c.phone, c.address, u.email
    FROM clinics c
    LEFT JOIN users u ON u.clinic_id = c.id
    WHERE c.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $clinic_id);
$stmt->execute();
if ($row = $stmt->get_result()->fetch_assoc()) {
    $clinic_name  = $row['name']         ?? '';
    $company_name = $row['company_name'] ?? '';
    $clinic_addr  = $row['address']      ?? '';
    $clinic_phone = $row['phone']        ?? '';
    $clinic_email = $row['email']        ?? '';
}
$stmt->close();

$attnLine = $clinic_name ? "ผู้จัดการ {$clinic_name}" : "";

/* ------------ PDF ------------ */
$pdf = new CeltacPDF();
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(true);
$pdf->showRightImage = false;
$pdf->AddPage();

$today = date('d/m/Y');

/* ---- layout helpers ---- */
$padX   = 10;
$m      = $pdf->getMargins();
$pageW  = $pdf->getPageWidth();
$pageH  = $pdf->getPageHeight();
$L      = $m['left']  + $padX;
$R      = $pageW - $m['right'] - $padX;
$usableW = $R - $L;

/* ---------------- Letterhead ---------------- */

// === Logo sizes & positions ===
$logoW = 22;   // both logos same width
$logoY = 14;   // vertical alignment
$gap   = 5;    // spacing between logos and text box

// Left logo (Celtac)  ✅ FIXED Image() parameters (x,y,w)
if (is_file('assets/celtaclogo.png')) {
    $pdf->Image('assets/celtaclogo.png', $L + 6, $logoY, 22);
}

// Right logo (Medical Lab)
if (is_file('assets/medicallogo.jpg')) {
    $rightLogoW = 26;
    $rightLogoY = 18;
    $pdf->Image('assets/medicallogo.jpg', $R - $rightLogoW, $rightLogoY, $rightLogoW);
}

// === Define centered TEXT AREA between logos ===
$textX = $L + $logoW + $gap;
$textW = $usableW - (2 * $logoW) - (2 * $gap);

// === Centered company name ===
$pdf->SetFont($pdf->baseFont, 'B', 16);
$pdf->SetXY($textX, 16);
$pdf->Cell($textW, 8, 'บริษัท เซลแทค แล๊บ จำกัด (สำนักงานใหญ่)', 0, 1, 'C');

// === Address line 1 ===
$pdf->SetFont($pdf->baseFont, '', 11);
$pdf->SetX($textX);
$pdf->Cell($textW, 6, '221 ซอยอินทามระ 33 แยก 2 ถนนสุทธิสารวินิจฉัย แขวงรัชดาภิเษก เขตดินแดง กรุงเทพฯ 10400', 0, 1, 'C');

// === Address line 2 ===
$pdf->SetX($textX);
$pdf->Cell($textW, 6, 'โทร. 0-2275-2498   แฟ็กซ์. 0-2076-6288   เลขประจำตัวผู้เสียภาษี 0105562129981', 0, 1, 'C');


/* ---------------- Title ---------------- */
$pdf->Ln(2);
$pdf->SetFont($pdf->baseFont, '', 13);
$pdf->Cell($usableW, 6, 'ใบเสนอราคา', 0, 1, 'C');
$pdf->SetFont($pdf->baseFont, '', 10);
$pdf->Cell($usableW, 5, 'QUOTATION', 0, 1, 'C');
$pdf->Ln(2);

/* ---------------- Recipient block ---------------- */
$leftW  = 110;
$rightW = $usableW - $leftW;

$pdf->SetFont($pdf->baseFont, '', 11);

// row 1
$pdf->SetX($L);
$pdf->Cell(14, 7, 'เรียน', 0, 0, 'L');
$pdf->Cell($leftW-14, 7, ' ' . $attnLine, 'B', 0, 'L');

$pdf->SetX($L + $leftW + 4);
$pdf->Cell(16, 7, 'เลขที่', 0, 0, 'L');
$pdf->Cell($rightW-16, 7, ' ' . $quote_no, 'B', 1, 'L');

// row 2
$pdf->SetX($L);
$pdf->Cell(14, 7, 'บริษัท', 0, 0, 'L');
$pdf->Cell($leftW-14, 7, ' ' . ($company_name ?: '-'), 'B', 0, 'L');

$pdf->SetX($L + $leftW + 4);
$pdf->Cell(16, 7, 'วันที่', 0, 0, 'L');
$pdf->Cell($rightW-16, 7, $today, 'B', 1, 'L');

// row 3 (address + email)
$pdf->SetX($L);
$pdf->Cell(14, 7, 'ที่อยู่', 0, 0, 'L');

$addrW       = $leftW - 14;
$rightLabelW = 16;
$rightValW   = $rightW - $rightLabelW;

$addrText = ' ' . trim((string)$clinic_addr);
$addrH    = max(7, $pdf->getStringHeight($addrW, $addrText));
$rowH     = $addrH;

$pdf->MultiCell($addrW, $rowH, $addrText, 'B', 'L', 0, 0);

$pdf->SetX($L + $leftW + 4);
$pdf->Cell($rightLabelW, 7, 'E-mail', 0, 0, 'L');
$pdf->MultiCell($rightValW, $rowH, ' ' . trim((string)$clinic_email), 'B', 'L', 0, 1);

// row 4 (mobile)
$pdf->SetX($L);
$pdf->Cell(14, 7, 'มือถือ', 0, 0, 'L');
$pdf->Cell($leftW + $rightW - 14, 7, ' ' . $clinic_phone, 'B', 1, 'L');

// row 5
$pdf->SetX($L);
$pdf->Cell(14, 7, 'เรื่อง', 0, 0, 'L');
$pdf->Cell($leftW + $rightW - 14, 7, ' ใบเสนอค่าใช้จ่ายตรวจวิเคราะห์ทางห้องปฏิบัติการ', 'B', 1, 'L');

/* ---------------- Table (ADD CODE COLUMN) ---------------- */
$pdf->Ln(6);
$pdf->SetFont($pdf->baseFont, '', 10);

$col = [
  'no'    => 10,
  'code'  => 18,
  'name'  => 62,
  'samp'  => 34,
  'meth'  => 32,
  'tat'   => 16,
  'price' => 18
];

$sumW  = array_sum($col);
$scale = ($sumW > $usableW) ? $usableW / $sumW : 1.0;
foreach ($col as $k => $w) { $col[$k] = round($w * $scale, 2); }
$scaledSum = array_sum($col);
$tblX      = $L + max(0, ($usableW - $scaledSum) / 2);

$th = 8; $lineH = 5.5; $cellPad = 1.0;

$printHeader = function() use ($pdf, $tblX, $col, $th) {
  $pdf->SetFillColor(242,242,242);
  $pdf->SetXY($tblX, $pdf->GetY());
  $pdf->Cell($col['no'],    $th, 'No.',       1, 0, 'C', true);
  $pdf->Cell($col['code'],  $th, 'CODE',      1, 0, 'C', true);
  $pdf->Cell($col['name'],  $th, 'TEST NAME', 1, 0, 'C', true);
  $pdf->Cell($col['samp'],  $th, 'SAMPLE',    1, 0, 'C', true);
  $pdf->Cell($col['meth'],  $th, 'METHOD',    1, 0, 'C', true);
  $pdf->Cell($col['tat'],   $th, 'TAT',       1, 0, 'C', true);
  $pdf->Cell($col['price'], $th, 'Price',     1, 1, 'C', true);
};
$printHeader();

/* ✅ Fetch code too */
$sql = "
  SELECT test_code, test_name, specimen, method, tat_day, price
  FROM imported_prices
  ORDER BY test_name ASC
";
$res = $conn->query($sql);

$i = 1;
while ($res && ($r = $res->fetch_assoc())) {
  $codeTxt = trim((string)($r['test_code'] ?? ''));
  if ($codeTxt === '') $codeTxt = '-';

  $nameTxt = trim((string)($r['test_name'] ?? ''));
  $sampTxt = trim((string)($r['specimen']  ?? ''));
  $methTxt = trim((string)($r['method']    ?? ''));
  $tatTxt  = trim((string)($r['tat_day']   ?? ''));

  $nameLines = max(1, $pdf->getNumLines($nameTxt, $col['name'] - 2*$cellPad));
  $sampLines = max(1, $pdf->getNumLines($sampTxt, $col['samp'] - 2*$cellPad));
  $methLines = max(1, $pdf->getNumLines($methTxt, $col['meth'] - 2*$cellPad));

  $rowLines  = max($nameLines, $sampLines, $methLines);
  $rowH      = max($th, $rowLines * $lineH + 2*$cellPad);

  $bottomMargin = $pdf->getBreakMargin();
  if ($pdf->GetY() + $rowH > ($pageH - $bottomMargin)) {
    $pdf->AddPage();
    $printHeader();
  }

  $x = $tblX; $y = $pdf->GetY();

  // borders
  $pdf->Rect($x, $y, $col['no'], $rowH);
  $pdf->Rect($x + $col['no'], $y, $col['code'], $rowH);
  $pdf->Rect($x + $col['no'] + $col['code'], $y, $col['name'], $rowH);
  $pdf->Rect($x + $col['no'] + $col['code'] + $col['name'], $y, $col['samp'], $rowH);
  $pdf->Rect($x + $col['no'] + $col['code'] + $col['name'] + $col['samp'], $y, $col['meth'], $rowH);
  $pdf->Rect($x + $col['no'] + $col['code'] + $col['name'] + $col['samp'] + $col['meth'], $y, $col['tat'], $rowH);
  $pdf->Rect($x + $col['no'] + $col['code'] + $col['name'] + $col['samp'] + $col['meth'] + $col['tat'], $y, $col['price'], $rowH);

  // No
  $pdf->SetXY($x, $y + ($rowH - $lineH)/2);
  $pdf->MultiCell($col['no'], $lineH, (string)$i, 0, 'C');

  // Code
  $pdf->SetXY($x + $col['no'], $y + ($rowH - $lineH)/2);
  $pdf->MultiCell($col['code'], $lineH, $codeTxt, 0, 'C');

  // Name
  $pdf->SetXY($x + $col['no'] + $col['code'] + $cellPad, $y + $cellPad);
  $pdf->MultiCell($col['name'] - 2*$cellPad, $lineH, $nameTxt, 0, 'L');

  // Sample
  $pdf->SetXY($x + $col['no'] + $col['code'] + $col['name'] + $cellPad, $y + $cellPad);
  $pdf->MultiCell($col['samp'] - 2*$cellPad, $lineH, $sampTxt, 0, 'L');

  // Method
  $pdf->SetXY($x + $col['no'] + $col['code'] + $col['name'] + $col['samp'] + $cellPad, $y + $cellPad);
  $pdf->MultiCell($col['meth'] - 2*$cellPad, $lineH, $methTxt, 0, 'L');

  // TAT
  $pdf->SetXY($x + $col['no'] + $col['code'] + $col['name'] + $col['samp'] + $col['meth'], $y + ($rowH - $lineH)/2);
  $pdf->MultiCell($col['tat'], $lineH, $tatTxt, 0, 'C');

  // Price
  $price = number_format((float)$r['price'], 2);
  $pdf->SetXY(
    $x + $col['no'] + $col['code'] + $col['name'] + $col['samp'] + $col['meth'] + $col['tat'] + $cellPad,
    $y + ($rowH - $lineH)/2
  );
  $pdf->MultiCell($col['price'] - 2*$cellPad, $lineH, $price, 0, 'R');

  $pdf->SetY($y + $rowH);
  $i++;
}

/* ---------------- Conditions + signatures ---------------- */
$pdf->Ln(6);
$pdf->SetFont($pdf->baseFont, '', 11);
$condText = implode("\n", [
  'เงื่อนไข :',
  'การยืนยันรับการเสนอราคา : ภายใน 30 วันนับจากวันที่มีการเสนอราคา',
  'ระยะเวลาในการดำเนินงาน : เริ่มทันทีเมื่อมีออเดอร์ของทางลูกค้าเข้ามา',
  'ราคาที่เสนอขอสงวนสิทธิ์ : วันเสนอราคา เป็นต้นไป',
  'การปรับราคา : ทางบริษัทฯ ขอสงวนสิทธิ์ปรับราคาและเปลี่ยนแปลงเงื่อนไขตามสมควรโดยมิต้องแจ้งล่วงหน้า',
]);

$footerPad     = 8;
$gapAboveBoxes = 4;
$gapBetween    = 10;
$boxW          = ($usableW - $gapBetween) / 2;

$innerPad   = 6;
$lineHsig   = 5.8;

$condH = $pdf->getStringHeight($usableW, $condText);
$bottomMargin = $pdf->getBreakMargin();
$maxContentY  = $pageH - $bottomMargin - $footerPad;

if ($pdf->GetY() + $condH + 10 > $maxContentY) { $pdf->AddPage(); }

$pdf->SetX($L);
$pdf->MultiCell($usableW, 6, $condText, 0, 'L', false, 1);

$y0 = $pdf->GetY() + $gapAboveBoxes;

$leftText =
  "ขอแสดงความนับถือ\n\n".
  "ลงชื่อ  ________________________________  ผู้เสนอราคา\n".
  "( __________________________________ )\n\n".
  "วันที่  ____ / ____ / ______\n".
  "สำหรับบริษัท (FOR COMPANY)";

$rightText =
  "บริษัท/ผู้ได้รับการเสนอราคาเห็นชอบตามที่เสนอข้างต้นแล้ว ตกลงสั่งซื้อ\n".
  "โดยได้ลงลายมือชื่อไว้เป็นหลักฐานสำคัญ พร้อมประทับตราบริษัท\n\n".
  "ลงชื่อ  ________________________________  ผู้ซื้อ/ผู้อนุมัติ\n".
  "( __________________________________ )\n\n".
  "วันที่  ____ / ____ / ______\n".
  "สำหรับลูกค้า (FOR CUSTOMER)";

$textW  = $boxW - ($innerPad * 2);
$leftH  = $pdf->getStringHeight($textW,  $leftText);
$rightH = $pdf->getStringHeight($textW, $rightText);
$boxH   = max($leftH, $rightH) + ($innerPad * 2);

if ($y0 + $boxH > $maxContentY) { $pdf->AddPage(); $y0 = $pdf->GetY(); }

$pdf->Rect($L,                       $y0, $boxW, $boxH);
$pdf->Rect($L + $boxW + $gapBetween, $y0, $boxW, $boxH);

$pdf->SetXY($L + $innerPad, $y0 + $innerPad);
$pdf->MultiCell($textW, $lineHsig, $leftText, 0, 'C', false, 1);

$pdf->SetXY($L + $boxW + $gapBetween + $innerPad, $y0 + $innerPad);
$pdf->MultiCell($textW, $lineHsig, $rightText, 0, 'C', false, 1);

$pdf->SetY($y0 + $boxH);

/* ---------------- Save + Stream ---------------- */
$pdfBytes = $pdf->Output('', 'S');

$clinicSafe = preg_replace('/[^A-Za-z0-9\-_]+/', '_', $clinic_name ?: ('clinic_'.$clinic_id));
$quoteSafe  = preg_replace('/[^A-Za-z0-9\-_]+/', '_', ($quote_no !== '' ? $quote_no : 'NoNumber'));
$dateStamp  = date('Ymd');
$saveDir    = __DIR__ . '/generated_quotes';
if (!is_dir($saveDir)) {
    @mkdir($saveDir, 0775, true);
}
$savePath = $saveDir . "/All-Tests-Quotation-{$clinicSafe}-{$quoteSafe}-{$dateStamp}.pdf";
@file_put_contents($savePath, $pdfBytes);

if (ob_get_length()) { ob_end_clean(); }
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="All-Tests-Quotation-'.$clinicSafe.'-'.$quoteSafe.'-'.$dateStamp.'.pdf"');
header('Content-Length: '.strlen($pdfBytes));
echo $pdfBytes;
exit;
