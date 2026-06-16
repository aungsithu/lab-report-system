<?php
// generate_pdf_patient_quotation.php
// NO BOM, no whitespace before `<?php`
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ERROR | E_PARSE);

require 'includes/db.php';
require 'pdf_common.php';
date_default_timezone_set('Asia/Bangkok');

/* ------------ Inputs ------------ */
$clinic_id  = isset($_GET['clinic_id']) ? (int)$_GET['clinic_id'] : 0;
$invoice_id = isset($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : 0;

if ($clinic_id <= 0 || $invoice_id <= 0) {
    if (ob_get_length()) { ob_end_clean(); }
    die('Missing clinic_id or invoice_id');
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

/* ------------ Fetch invoice meta (patient name etc.) ------------ */
$patient_name = '';
$tax_id = '';
$tel = '';
$customer_address = '';
$salesman_no = '';

$stmt = $conn->prepare("
  SELECT patient_name, tax_id, tel, customer_address, salesman_no
  FROM invoices
  WHERE id = ? AND clinic_id = ?
  LIMIT 1
");
$stmt->bind_param("ii", $invoice_id, $clinic_id);
$stmt->execute();
$inv = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($inv) {
    $patient_name     = trim((string)($inv['patient_name'] ?? ''));
    $tax_id           = trim((string)($inv['tax_id'] ?? ''));
    $tel              = trim((string)($inv['tel'] ?? ''));
    $customer_address = trim((string)($inv['customer_address'] ?? ''));
    $salesman_no      = trim((string)($inv['salesman_no'] ?? ''));
}

/* fallback */
if ($tel === '') $tel = $clinic_phone;

/* Quotation No (same idea) */
$quote_no = 'Q-' . date('y') . str_pad((string)$invoice_id, 5, '0', STR_PAD_LEFT);

/* Recipient line */
$attnLine = ($patient_name !== '')
    ? $patient_name
    : ($clinic_name ? "ผู้จัดการ {$clinic_name}" : "");

/* ------------ Fetch tests for THIS invoice + join imported_prices ------------ */
/*
  IMPORTANT:
  - We match test_name with normalization:
    LOWER(TRIM(...)) to avoid spacing/case mismatch.

  ✅ CODE COLUMN:
  - Using imported_prices.test_code as the code field.
  - If your column name is "code" instead, change:
      COALESCE(ip.test_code, '')
    to:
      COALESCE(ip.code, '')
*/
$stmt = $conn->prepare("
  SELECT 
    tp.test_name,
    COALESCE(ip.test_code, '') AS test_code,
    COALESCE(ip.specimen, '')  AS specimen,
    COALESCE(ip.method, '')    AS method,
    COALESCE(ip.tat_day, '')   AS tat_day,
    tp.price,
    tp.discount_percent
  FROM test_prices tp
  LEFT JOIN imported_prices ip
    ON LOWER(TRIM(ip.test_name)) = LOWER(TRIM(tp.test_name))
  WHERE tp.clinic_id = ? AND tp.invoice_id = ?
  ORDER BY tp.id ASC
");
$stmt->bind_param("ii", $clinic_id, $invoice_id);
$stmt->execute();
$res = $stmt->get_result();

$items = [];
while ($r = $res->fetch_assoc()) {
    $name = trim((string)($r['test_name'] ?? ''));
    if ($name === '') continue;

    $code = trim((string)($r['test_code'] ?? ''));
    if ($code === '') $code = '-';

    $price = (float)($r['price'] ?? 0);
    $disc  = (float)($r['discount_percent'] ?? 0);

    // Net price (discount applied)
    $net = $price - ($price * ($disc / 100));

    $items[] = [
        'code'     => $code,
        'name'     => $name,
        'specimen' => trim((string)$r['specimen']),
        'method'   => trim((string)$r['method']),
        'tat_day'  => trim((string)$r['tat_day']),
        'price'    => $net,
    ];
}
$stmt->close();

if (!$items) {
    if (ob_get_length()) { ob_end_clean(); }
    die('No tests found for this invoice.');
}

/* ------------ PDF init ------------ */
$pdf = new CeltacPDF();
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(true);
$pdf->showRightImage = false;
$pdf->AddPage();

$today = date('d/m/Y');

/* ---- layout helpers (same as alltests) ---- */
$padX   = 10;
$m      = $pdf->getMargins();
$pageW  = $pdf->getPageWidth();
$pageH  = $pdf->getPageHeight();
$L      = $m['left']  + $padX;
$R      = $pageW - $m['right'] - $padX;
$usableW = $R - $L;

/* ---------------- Letterhead (same as alltests) ---------------- */
$logoW = 22;
$logoY = 14;
$gap   = 5;

// Left logo
if (is_file('assets/celtaclogo.png')) {
    $leftLogoW = 17;
    $leftLogoY = 14;
    $pdf->Image('assets/celtaclogo.png', $L + 6, $leftLogoY, $leftLogoW);
}

// Right logo
if (is_file('assets/medicallogo.jpg')) {
    $rightLogoW = 26;
    $rightLogoY = 18;
    $pdf->Image('assets/medicallogo.jpg', $R - $rightLogoW, $rightLogoY, $rightLogoW);
}

// Text area between logos
$textX = $L + $logoW + $gap;
$textW = $usableW - (2 * $logoW) - (2 * $gap);

$pdf->SetFont($pdf->baseFont, 'B', 16);
$pdf->SetXY($textX, 16);
$pdf->Cell($textW, 8, 'บริษัท เซลแทค แล๊บ จำกัด (สำนักงานใหญ่)', 0, 1, 'C');

$pdf->SetFont($pdf->baseFont, '', 11);
$pdf->SetX($textX);
$pdf->Cell($textW, 6, '221 ซอยอินทามระ 33 แยก 2 ถนนสุทธิสารวินิจฉัย แขวงรัชดาภิเษก เขตดินแดง กรุงเทพฯ 10400', 0, 1, 'C');

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

// row 3 address + email
$pdf->SetX($L);
$pdf->Cell(14, 7, 'ที่อยู่', 0, 0, 'L');

$addrW       = $leftW - 14;
$rightLabelW = 16;
$rightValW   = $rightW - $rightLabelW;

$addrText = ' ' . trim((string)($customer_address !== '' ? $customer_address : $clinic_addr));
$addrH    = max(7, $pdf->getStringHeight($addrW, $addrText));
$rowH     = $addrH;

$pdf->MultiCell($addrW, $rowH, $addrText, 'B', 'L', 0, 0);

$pdf->SetX($L + $leftW + 4);
$pdf->Cell($rightLabelW, 7, 'E-mail', 0, 0, 'L');
$pdf->MultiCell($rightValW, $rowH, ' ' . trim((string)$clinic_email), 'B', 'L', 0, 1);

// row 4 mobile
$pdf->SetX($L);
$pdf->Cell(14, 7, 'มือถือ', 0, 0, 'L');
$pdf->Cell($leftW + $rightW - 14, 7, ' ' . ($tel ?: '-'), 'B', 1, 'L');

// row 5 subject
$pdf->SetX($L);
$pdf->Cell(14, 7, 'เรื่อง', 0, 0, 'L');
$pdf->Cell($leftW + $rightW - 14, 7, ' ใบเสนอค่าใช้จ่ายตรวจวิเคราะห์ทางห้องปฏิบัติการ', 'B', 1, 'L');

/* ---------------- TABLE (NOW WITH CODE COLUMN) ---------------- */
$pdf->Ln(6);
$pdf->SetFont($pdf->baseFont, '', 10);

// ✅ Columns: No | CODE | TEST NAME | SAMPLE | METHOD | TAT | Price
$col = [
  'no'    => 10,
  'code'  => 18,
  'name'  => 58,
  'samp'  => 28,
  'meth'  => 28,
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
  $pdf->Cell($col['tat'],   $th, 'TAT (DAY)', 1, 0, 'C', true);
  $pdf->Cell($col['price'], $th, 'Price',     1, 1, 'C', true);
};

$printHeader();

$bottomMargin = $pdf->getBreakMargin();
$maxY = $pageH - $bottomMargin;

$i = 1;
foreach ($items as $it) {
  $codeTxt = trim((string)($it['code'] ?? '-'));
  if ($codeTxt === '') $codeTxt = '-';

  $nameTxt = trim((string)($it['name'] ?? ''));
  $sampTxt = trim((string)($it['specimen'] ?? ''));
  $methTxt = trim((string)($it['method'] ?? ''));

  $codeLines = max(1, $pdf->getNumLines($codeTxt, $col['code'] - 2*$cellPad));
  $nameLines = max(1, $pdf->getNumLines($nameTxt, $col['name'] - 2*$cellPad));
  $sampLines = max(1, $pdf->getNumLines($sampTxt, $col['samp'] - 2*$cellPad));
  $methLines = max(1, $pdf->getNumLines($methTxt, $col['meth'] - 2*$cellPad));

  $rowLines = max($codeLines, $nameLines, $sampLines, $methLines);
  $rowH     = max($th, $rowLines * $lineH + 2*$cellPad);

  if ($pdf->GetY() + $rowH > $maxY) {
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

  // CODE
  $pdf->SetXY($x + $col['no'] + $cellPad, $y + ($rowH - $lineH)/2);
  $pdf->MultiCell($col['code'] - 2*$cellPad, $lineH, $codeTxt, 0, 'C');

  // Test name
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
  $pdf->MultiCell($col['tat'], $lineH, (string)($it['tat_day'] ?? ''), 0, 'C');

  // Price
  $pdf->SetXY(
    $x + $col['no'] + $col['code'] + $col['name'] + $col['samp'] + $col['meth'] + $col['tat'] + $cellPad,
    $y + ($rowH - $lineH)/2
  );
  $pdf->MultiCell($col['price'] - 2*$cellPad, $lineH, number_format((float)($it['price'] ?? 0), 2), 0, 'R');

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

$clinicSafe  = preg_replace('/[^A-Za-z0-9\-_]+/', '_', $clinic_name ?: ('clinic_'.$clinic_id));
$patientSafe = preg_replace('/[^A-Za-z0-9\-_]+/', '_', $patient_name ?: ('invoice_'.$invoice_id));
$dateStamp   = date('Ymd');

$saveDir = __DIR__ . '/generated_quotes';
if (!is_dir($saveDir)) {
    @mkdir($saveDir, 0775, true);
}
$savePath = $saveDir . "/Patient-Quotation-{$clinicSafe}-{$patientSafe}-{$dateStamp}.pdf";
@file_put_contents($savePath, $pdfBytes);

if (ob_get_length()) { ob_end_clean(); }
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="Patient-Quotation-'.$clinicSafe.'-'.$patientSafe.'-'.$dateStamp.'.pdf"');
header('Content-Length: '.strlen($pdfBytes));
echo $pdfBytes;
exit;
