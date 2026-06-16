<?php
// pdf_common.php — shared helpers for TCPDF (Celtac Lab)

// ---- 1) Load TCPDF from your /onlinereport/tcpdf/ folder ----
$tcpdfPath = __DIR__ . '/tcpdf/tcpdf.php';
if (!is_file($tcpdfPath)) {
    die("TCPDF not found at $tcpdfPath");
}
require_once $tcpdfPath;

/* ---- 2) Thai Baht in words helper ---- */
function thai_baht_text($number) {
    $number = number_format((float)$number, 2, '.', '');
    list($int, $dec) = explode('.', $number);

    $txtnum1 = ['', 'หนึ่ง','สอง','สาม','สี่','ห้า','หก','เจ็ด','แปด','เก้า','สิบ'];
    $txtnum2 = ['', 'สิบ','ร้อย','พัน','หมื่น','แสน','ล้าน'];
    $convert = function($n) use($txtnum1,$txtnum2){
        $c = ''; $len = strlen($n);
        for ($i=0;$i<$len;$i++) {
            $num = intval($n[$i]);
            if ($num != 0) {
                if ($i == ($len-1) && $num == 1 && $len > 1) $c .= 'เอ็ด';
                elseif ($i == ($len-2) && $num == 2)        $c .= 'ยี่';
                elseif ($i == ($len-2) && $num == 1)        ; // “สิบ”
                else                                        $c .= $txtnum1[$num];
                $c .= $txtnum2[$len-$i-1];
            }
        }
        return $c;
    };
    $baht = '';
    $p = explode(',', strrev($int));
    foreach ($p as $i => $g) {
        $g = strrev($g);
        if (intval($g) == 0) { continue; }
        $baht = $convert($g) . ($i>0 ? 'ล้าน' : '') . $baht;
    }
    if ($baht === '') $baht = 'ศูนย์';
    $baht .= 'บาท';
    if (intval($dec) > 0) $baht .= $convert($dec) . 'สตางค์';
    else $baht .= 'ถ้วน';
    return $baht;
}

/* ---- 3) Base PDF class ---- */
class CeltacPDF extends TCPDF {
    public $baseFont = 'helvetica';   // fallback if Sarabun unavailable
    public $showRightImage = true;

    function __construct() {
        parent::__construct('P', 'mm', 'A4', true, 'UTF-8', false);

        $this->SetCreator('Celtac Lab');
        $this->SetAuthor('Celtac Lab');
        $this->SetMargins(12, 12, 12);
        $this->SetAutoPageBreak(TRUE, 18);

        // Try to load THSarabunNew from /tcpdf/fonts/
        $sarabunPath = __DIR__ . '/tcpdf/fonts/THSarabunNew.ttf';
        if (is_file($sarabunPath)) {
            $f = TCPDF_FONTS::addTTFfont($sarabunPath, 'TrueTypeUnicode', '', 32);
            if ($f) {
                $this->baseFont = $f;
            }
        }

        // IMPORTANT: set default font for entire document
        $this->SetFont($this->baseFont, '', 12);
    }

    public function Footer() {
        $this->SetY(-15);
        $this->SetFont($this->baseFont, 'I', 8); // use baseFont (no arial)
        $this->Cell(0, 10, 'Page '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(), 0, 0, 'R');
    }

    public function drawCompanyHeader($docTitle = 'ใบเสนอราคา / QUOTATION') {
        // Left logo
        if (is_file('assets/celtaclogo.png')) {
            $this->Image('assets/celtaclogo.png', 12, 10, 12);
        }
        // Right image
        if ($this->showRightImage && is_file('assets/test.jpg')) {
            $this->Image('assets/test.jpg', 170, 10, 25);
        }

        // Company/address block (Thai-friendly font)
        $this->SetFont($this->baseFont, '', 14);
        $this->SetXY(35, 10);
        $addr  = "บริษัท เซลแทค แล๊บ จำกัด (สำนักงานใหญ่)\n";
        $addr .= "221 ซอยอินทามระ 33 แยก 2 ถนนสุทธิสารวินิจฉัย แขวงรัชดาภิเษก เขตดินแดง กรุงเทพฯ 10400\n";
        $addr .= "โทร. 082-6291915   แฟ็กซ์. 0-2076-6288   เลขประจำตัวผู้เสียภาษี 0105562129981";
        $this->MultiCell(130, 5, $addr, 0, 'L', false, 1, '', '', true, 0, false, true, 0);

        $this->Ln(2);
        $this->SetFont($this->baseFont, 'B', 14); // title in baseFont (no arial)
        $this->Cell(0, 8, $docTitle, 0, 1);

        // Return to normal for body
        $this->SetFont($this->baseFont, '', 12);
    }
}
