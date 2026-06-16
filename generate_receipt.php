<?php
// NO extra spaces/newlines before <?php
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ERROR | E_PARSE);

require 'includes/db.php';
require 'pdf_common.php';
date_default_timezone_set('Asia/Bangkok');

/* ---------- helpers ---------- */
if (!function_exists('thai_baht_text')) {
  function thai_baht_text($amount) {
    $number = floor($amount);
    $fraction = round(($amount - $number) * 100);

    $txtnum1 = ['ศูนย์','หนึ่ง','สอง','สาม','สี่','ห้า','หก','เจ็ด','แปด','เก้า','สิบ'];
    $txtnum2 = ['','สิบ','ร้อย','พัน','หมื่น','แสน','ล้าน'];
    $result = '';

    $num = $number;
    $pos = 0;
    while ($num > 0) {
      $digit = $num % 10;
      $word = '';
      if ($pos == 0 && $digit == 1 && $num > 10) {
        $word = 'เอ็ด';
      } else if ($pos == 1 && $digit == 2) {
        $word = 'ยี่';
      } else if ($pos == 1 && $digit == 1) {
        $word = '';
      } else if ($digit != 0) {
        $word = $txtnum1[$digit];
      }
      if ($digit != 0) $word .= $txtnum2[$pos];
      $result = $word . $result;
      $num = (int)($num / 10);
      $pos++;
    }
    if ($result === '') $result = 'ศูนย์';

    $result .= 'บาท';

    if ($fraction > 0) {
      $num = $fraction;
      $pos = 0; $fraTxt = '';
      while ($num > 0) {
        $digit = $num % 10;
        $word = '';
        if ($pos == 0 && $digit == 1 && $num > 10) {
          $word = 'เอ็ด';
        } else if ($pos == 1 && $digit == 2) {
          $word = 'ยี่';
        } else if ($pos == 1 && $digit == 1) {
          $word = '';
        } else if ($digit != 0) {
          $word = $txtnum1[$digit];
        }
        if ($digit != 0) $word .= $txtnum2[$pos];
        $fraTxt = $word . $fraTxt;
        $num = (int)($num / 10);
        $pos++;
      }
      $result .= $fraTxt . 'สตางค์';
    } else {
      $result .= 'ถ้วน';
    }
    return $result;
  }
}

/* ---------- inputs ---------- */
$clinic_id  = (int)($_GET['clinic_id'] ?? 0);
$invoice_id = (int)($_GET['invoice_id'] ?? 0);
if ($clinic_id <= 0 || $invoice_id <= 0) {
  if (ob_get_length()) { ob_end_clean(); }
  die('Missing clinic_id or invoice_id');
}

/* ---------- header info ---------- */
$hdr = $conn->query("
  SELECT
    i.invoice_number, i.created_at,
    i.patient_name, i.tax_id, i.tel, i.salesman_no,
    COALESCE(i.customer_address, c.address, '') AS clinic_address,
    c.name AS clinic_name,
    i.customer_address
  FROM invoices i
  JOIN clinics c ON c.id = i.clinic_id
  WHERE i.id = {$invoice_id} AND i.clinic_id = {$clinic_id}
")->fetch_assoc();

if (!$hdr) {
  if (ob_get_length()) { ob_end_clean(); }
  die('Invoice not found');
}

$invoice_no   = trim($hdr['invoice_number']);
$doc_date     = (new DateTimeImmutable($hdr['created_at']))->format('d/m/Y');
$clinic_name  = trim($hdr['clinic_name']);
$patient_name = trim($hdr['patient_name'] ?? '');
$tax_id       = trim($hdr['tax_id'] ?? '');
$tel          = trim($hdr['tel'] ?? '');
$cust_addr    = trim($hdr['customer_address'] ?? '');
$clinic_addr  = trim($hdr['clinic_address'] ?? '');

/* Prefer saved customer address; fall back to clinic address */
$address      = ($cust_addr !== '' ? $cust_addr : $clinic_addr);
$display_name = ($patient_name !== '') ? ('คุณ '.$patient_name) : $clinic_name;

/* ---------- items (UPDATED: join imported_prices to get CODE) ---------- */
/*
  ✅ CODE COLUMN SOURCE:
  - Using imported_prices.test_code
  - If your DB column is "code" instead, change:
      COALESCE(ip.test_code,'')
    to:
      COALESCE(ip.code,'')
*/
$stmt = $conn->prepare("
  SELECT
    tp.test_name,
    COALESCE(ip.test_code,'') AS test_code,
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
$rs = $stmt->get_result();

$items = [];
$subtotal = $discount_total = $net_total = 0.0;

while ($r = $rs->fetch_assoc()) {
  $p = (float)$r['price'];
  $d = (float)$r['discount_percent'];
  $final = $p * (1 - $d/100);

  $subtotal       += $p;
  $discount_total += ($p - $final);
  $net_total      += $final;

  $code = trim((string)($r['test_code'] ?? ''));
  if ($code === '') $code = '-';

  $items[] = [
    'code'  => $code,
    'name'  => $r['test_name'],
    'price' => $p,
    'final' => $final
  ];
}
$stmt->close();

/* ---------- PDF setup ---------- */
$pdf = new CeltacPDF();
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(true);
$pdf->AddPage();

$padX   = 10;
$m      = $pdf->getMargins();
$pageW  = $pdf->getPageWidth();
$pageH  = $pdf->getPageHeight();
$L      = $m['left']  + $padX;
$R      = $pageW - $m['right'] - $padX;
$usableW = $R - $L;

/* ---------- Letterhead (Thai left wider / English right narrower, with logo lane) ---------- */
$panelY  = $pdf->GetY();
$panelH  = 42;
$logoW   = 22;
$lanePad = 8;
$laneW   = $logoW + 2*$lanePad;

$leftW   = ($usableW - $laneW) * 0.55;
$rightW  = ($usableW - $laneW) * 0.45;
$leftX   = $L;
$laneX   = $L + $leftW;
$rightX  = $laneX + $laneW;

if (is_file('assets/celtaclogo.png')) {
  $logoX = $laneX + ($laneW - $logoW)/2;
  $logoY = $panelY + 4;
  $pdf->Image('assets/celtaclogo.png', $logoX, $logoY, $logoW);
}

$yL = $panelY + 2;
$pdf->SetXY($leftX, $yL);
$pdf->SetFont($pdf->baseFont, 'B', 12.5);
$pdf->MultiCell($leftW, 6, 'บริษัท เซลเทค แล๊บ จำกัด (สำนักงานใหญ่)', 0, 'L');

$pdf->SetFont($pdf->baseFont, '', 12);
$thaiOffset = 1;

$pdf->SetX($leftX + $thaiOffset);
$pdf->MultiCell($leftW - $thaiOffset, 6, '221 ซ.อินทามระ33แยก2 ถ.สุทธิสารวินิจฉัย แขวงรัชดาภิเษก เขตดินแดง กรุงเทพฯ 10400', 0, 'L');

$pdf->SetX($leftX + $thaiOffset);
$pdf->MultiCell($leftW - $thaiOffset, 6, 'โทร/แฟ็กซ์ : 02-076-6288', 0, 'L');

$pdf->SetX($leftX + $thaiOffset);
$pdf->MultiCell($leftW - $thaiOffset, 6, 'เลขประจำตัวผู้เสียภาษี/Tax ID. 0105562129981', 0, 'L');

$pdf->SetXY($rightX, $panelY + 2);
$pdf->SetFont($pdf->baseFont, 'B', 12.5);
$pdf->Cell($rightW, 6, 'CELTAC LAB CO.,LTD. (Head Office)', 0, 2, 'R');
$pdf->SetFont($pdf->baseFont, '', 11);
$pdf->Cell($rightW, 5.2, '221 Soi Inthamara33 yak2, Suthisan Rd.,', 0, 2, 'R');
$pdf->Cell($rightW, 5.2, 'Ratchadapisek, Dindaeng, Bangkok 10400', 0, 2, 'R');
$pdf->Cell($rightW, 5.2, 'Call Center : 082-629-1915', 0, 2, 'R');
$pdf->Cell($rightW, 5.2, 'E-mail : celtaclab221@gmail.com', 0, 2, 'R');

$pdf->SetY($panelY + $panelH);

/* ---------- Top info boxes ---------- */
$leftW  = $usableW * 0.63;
$rightW = $usableW - $leftW;
$y0     = $pdf->GetY() + 2;
$pad    = 3;

$lineH = 5.2;
$leftX = $L;
$leftY = $y0;

$innerX = $leftX + $pad;
$innerY = $leftY + $pad;
$innerW = $leftW - 2*$pad;

$pdf->SetFont($pdf->baseFont, '', 10.3);
$pdf->SetXY($innerX, $innerY);
$pdf->Cell($innerW, $lineH, 'เลขประจำตัวผู้เสียภาษี Tax id : ' . $tax_id, 0, 2, 'L');
$pdf->Cell($innerW, $lineH, 'ชื่อลูกค้า / Name : ' . $display_name, 0, 2, 'L');
$pdf->MultiCell($innerW, $lineH, 'ที่อยู่ / Address : ' . $address, 0, 'L', false, 2);
$pdf->SetX($innerX);
$pdf->Cell($innerW, $lineH, 'โทร. / Tel. : ' . $tel, 0, 2, 'L');

$leftContentBottom = $pdf->GetY();
$leftBoxH = ($leftContentBottom - ($leftY + $pad)) + $pad;

$rightX = $L + $leftW + 2;
$rightY = $y0;

$pdf->SetXY($rightX + $pad, $rightY + $pad);
$pdf->SetFont($pdf->baseFont, 'B', 13.2);
$pdf->Cell($rightW - 2*$pad - 2, 7, 'ใบเสร็จรับเงิน / RECEIPT', 0, 2, 'C');
$pdf->SetFont($pdf->baseFont, 'B', 11.3);
$pdf->Cell($rightW - 2*$pad - 2, 6, 'ต้นฉบับ / ORIGINAL', 0, 2, 'C');

$pdf->Ln(1);
$pdf->SetFont($pdf->baseFont, '', 10.2);
$metaX = $rightX + $pad;

$pdf->SetXY($metaX, $pdf->GetY());
$pdf->Cell(26, 6, 'วันที่ / Date', 0, 0, 'L');
$pdf->Cell(($rightW - 2*$pad - 26 - 2), 6, $doc_date, 0, 1, 'R');

$pdf->SetXY($metaX, $pdf->GetY());
$pdf->Cell(26, 6, 'เลขที่ / No.', 0, 0, 'L');
$pdf->Cell(($rightW - 2*$pad - 26 - 2), 6, $invoice_no, 0, 1, 'R');

$rightContentBottom = $pdf->GetY();
$rightBoxH = ($rightContentBottom - ($rightY + $pad)) + $pad;

$panelH = max($leftBoxH, $rightBoxH);

$pdf->Rect($leftX,  $leftY,  $leftW,  $panelH);
$pdf->Rect($rightX, $rightY, $rightW - 2, $panelH);

$pdf->SetY($y0 + $panelH + 3);

/* ---------- Items table (UPDATED: add CODE column) ---------- */
$pdf->SetFont($pdf->baseFont, '', 10.2);

$col = [
  'no'    => 12,
  'code'  => 18,
  'desc'  => 78,
  'qty'   => 18,
  'unit'  => 28,
  'amount'=> 30
];

$totalW = array_sum($col);
$scale  = ($totalW > $usableW) ? $usableW / $totalW : 1.0;
foreach ($col as $k => $w) $col[$k] = round($w * $scale, 2);
$tblW   = array_sum($col);
$tblX   = $L + max(0, ($usableW - $tblW) / 2);

$th = 8.5;
$lineH = 5.8;
$pad = 1.2;

$drawHeader = function() use ($pdf, $tblX, $col, $th) {
  $pdf->SetFillColor(242,242,242);
  $pdf->SetXY($tblX, $pdf->GetY());
  $pdf->Cell($col['no'],     $th, 'ลำดับ / No.',              1, 0, 'C', true);
  $pdf->Cell($col['code'],   $th, 'CODE',                     1, 0, 'C', true);
  $pdf->Cell($col['desc'],   $th, 'รายละเอียด / Description', 1, 0, 'C', true);
  $pdf->Cell($col['qty'],    $th, 'จำนวน / Qty',              1, 0, 'C', true);
  $pdf->Cell($col['unit'],   $th, 'หน่วยละ / Unit Price',     1, 0, 'C', true);
  $pdf->Cell($col['amount'], $th, 'จำนวนเงิน / Amount',       1, 1, 'C', true);
};
$drawHeader();

$i = 1;
foreach ($items as $it) {
  $code   = trim((string)($it['code'] ?? '-'));
  if ($code === '') $code = '-';

  $desc   = trim($it['name'] ?? '');
  $qty    = '1';
  $unit   = number_format((float)$it['price'], 2);
  $amount = number_format((float)$it['final'], 2);

  $codeLines = max(1, $pdf->getNumLines($code, $col['code'] - 2*$pad));
  $descLines = max(1, $pdf->getNumLines($desc, $col['desc'] - 2*$pad));
  $rowH = max($th, max($codeLines, $descLines) * $lineH + 2*$pad);

  $bottomMargin = $pdf->getBreakMargin();
  if ($pdf->GetY() + $rowH > ($pageH - $bottomMargin)) {
    $pdf->AddPage();
    $drawHeader();
  }

  $x = $tblX; $y = $pdf->GetY();

  $pdf->Rect($x, $y, $col['no'], $rowH);
  $pdf->Rect($x + $col['no'], $y, $col['code'], $rowH);
  $pdf->Rect($x + $col['no'] + $col['code'], $y, $col['desc'], $rowH);
  $pdf->Rect($x + $col['no'] + $col['code'] + $col['desc'], $y, $col['qty'], $rowH);
  $pdf->Rect($x + $col['no'] + $col['code'] + $col['desc'] + $col['qty'], $y, $col['unit'], $rowH);
  $pdf->Rect($x + $col['no'] + $col['code'] + $col['desc'] + $col['qty'] + $col['unit'], $y, $col['amount'], $rowH);

  $pdf->SetXY($x, $y + ($rowH - $lineH)/2);
  $pdf->MultiCell($col['no'], $lineH, (string)$i, 0, 'C');

  $pdf->SetXY($x + $col['no'] + $pad, $y + ($rowH - $lineH)/2);
  $pdf->MultiCell($col['code'] - 2*$pad, $lineH, $code, 0, 'C');

  $pdf->SetXY($x + $col['no'] + $col['code'] + $pad, $y + $pad);
  $pdf->MultiCell($col['desc'] - 2*$pad, $lineH, $desc, 0, 'L');

  $pdf->SetXY($x + $col['no'] + $col['code'] + $col['desc'], $y + ($rowH - $lineH)/2);
  $pdf->MultiCell($col['qty'], $lineH, $qty, 0, 'C');

  $pdf->SetXY($x + $col['no'] + $col['code'] + $col['desc'] + $col['qty'] + $pad, $y + ($rowH - $lineH)/2);
  $pdf->MultiCell($col['unit'] - 2*$pad, $lineH, $unit, 0, 'R');

  $pdf->SetXY($x + $col['no'] + $col['code'] + $col['desc'] + $col['qty'] + $col['unit'] + $pad, $y + ($rowH - $lineH)/2);
  $pdf->MultiCell($col['amount'] - 2*$pad, $lineH, $amount, 0, 'R');

  $pdf->SetY($y + $rowH);
  $i++;
}

/* ====== Remark + Totals + Payment (your same block unchanged) ====== */
$remarkH   = 10;
$totRowH   = 10;
$totRows   = 3;
$totalsH   = $totRowH * $totRows;
$gapAfter  = 4;
$payH      = 44;
$needH     = $remarkH + $totalsH + $gapAfter + $payH;

$bottomMargin = $pdf->getBreakMargin();
if ($pdf->GetY() + $needH > ($pageH - $bottomMargin)) {
    $pdf->AddPage();
}

$remarkLabelW = 30;

$yRemark = $pdf->GetY();
$pdf->Rect($tblX, $yRemark, $remarkLabelW, $remarkH);
$pdf->Rect($tblX + $remarkLabelW, $yRemark, $tblW - $remarkLabelW, $remarkH);
$pdf->SetXY($tblX + 2, $yRemark + ($remarkH - 5.6)/2);
$pdf->SetFont($pdf->baseFont, '', 10.2);
$pdf->Cell($remarkLabelW - 4, 5.6, 'หมายเหตุ / Remark :', 0, 0, 'L');

$pdf->SetY($yRemark + $remarkH);

$totW   = 100;
$labelW = $totW - 44;
$valW   = 44;
$totX   = $tblX + $tblW - $totW;
$yTot   = $pdf->GetY();

$leftMergedW = $totX - $tblX;
$pdf->Rect($tblX, $yTot, $leftMergedW, $totalsH);

$rows = [
  ['label' => 'รวมราคา / Amount',          'value' => number_format($subtotal, 2)],
  ['label' => 'ส่วนลด / Discount',         'value' => number_format($discount_total, 2)],
  ['label' => 'ยอดชำระสุทธิ / Net Total', 'value' => number_format($net_total, 2), 'bold' => true],
];

$pdf->SetFont($pdf->baseFont, '', 10.4);
for ($r = 0; $r < $totRows; $r++) {
    $y = $yTot + $r * $totRowH;
    $pdf->Rect($totX,           $y, $labelW, $totRowH);
    $pdf->Rect($totX + $labelW, $y, $valW,   $totRowH);

    $pdf->SetXY($totX + 2, $y + 2.2);
    $pdf->SetFont($pdf->baseFont, !empty($rows[$r]['bold']) ? 'B' : '', 10.4);
    $pdf->Cell($labelW - 4, 5.6, $rows[$r]['label'], 0, 0, 'L');

    $pdf->SetXY($totX + $labelW, $y + 2.2);
    $pdf->Cell($valW - 2, 5.6, $rows[$r]['value'], 0, 0, 'R');
}

$pdf->SetFont($pdf->baseFont, '', 10.2);
$secondRowY = $yTot + $totRowH;
$pdf->SetXY($tblX + 2, $secondRowY + 2.2);
$pdf->Cell($leftMergedW - 4, 5.6, '( ' . thai_baht_text($net_total) . ' )', 0, 0, 'C');

$pdf->SetY($yTot + $totalsH + $gapAfter);

$pdf->SetFont($pdf->baseFont, '', 10.5);

$collectorW = 60;
$collectorH = $payH;
$collectorX = $tblX + $tblW - $collectorW;
$collectorY = $pdf->GetY();

$payTextW = $collectorX - $tblX - 4;
$pdf->SetXY($tblX, $collectorY);
$pdf->Cell($payTextW, 8, '( ) เงินสด               ( ) เงินโอน              ( ) บัตรเครดิต', 0, 2, 'L');
$pdf->Cell($payTextW, 8, '( ) เช็คธนาคาร........................   สาขา........................   เลขที่........................', 0, 2, 'L');
$pdf->Cell($payTextW, 8, 'ลงวันที่..........................     จำนวนเงิน..........................', 0, 2, 'L');

$pdf->SetXY($collectorX, $collectorY + 6);
$pdf->Cell($collectorW, 6, 'ผู้รับเงิน / Collector', 0, 2, 'C');

$pdf->Ln(12);
$pdf->SetXY($collectorX, $pdf->GetY());
$pdf->Cell($collectorW, 6, '______________________________', 0, 2, 'C');
$pdf->Cell($collectorW, 6, 'วันที่ / Date ........................', 0, 2, 'C');

$pdf->SetY($collectorY + $collectorH + 2);

/* ---------- Save + stream ---------- */
if (ob_get_length()) { ob_end_clean(); }

$invoiceSafe = preg_replace('/[^A-Za-z0-9\-_]/', '_', $invoice_no);
$saveDir = __DIR__ . '/generated_receipts';
if (!is_dir($saveDir)) { @mkdir($saveDir, 0775, true); }

$pdfBytes = $pdf->Output('', 'S');
file_put_contents($saveDir . "/Receipt-{$invoiceSafe}.pdf", $pdfBytes);

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="Receipt-'.$invoiceSafe.'.pdf"');
echo $pdfBytes;
exit;
