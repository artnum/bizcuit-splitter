<?php
namespace BizCuit\Splitter;
require_once(__DIR__ . '/vendor/autoload.php');

define('DENSITY', 300);
/* DENSITY is in INCHES per PIXELS, so 300 DPI is 300/25.4 = 11.811023622 pixels */	
define('RATIO', DENSITY / 25.4);
define('QRSIZE', 60 * RATIO);
define('QRYPOS', 5 * RATIO);

use setasign\Fpdi\Tfpdf\Fpdi;
use Imagick;
use ImagickPixel;
use Zxing\QrReader;

function blank () {

}

function splitter (string $filename) {
    $filename = realpath($filename);

    $IMagick = new Imagick();
    if (!$IMagick->setResolution(DENSITY, DENSITY)) { return false; }
    if (!$IMagick->readImage($filename)) { return false; }
    $whiteColor = new ImagickPixel('white');
    if (!$IMagick->setImageBackgroundColor($whiteColor)) { return false; }
    
    $pdf = new Fpdi();
    $doc = 0;
    $from = 0;
    $rotate = 0;
    for ($i = 0; $i < $IMagick->getNumberImages(); $i++) {   

        $IMagick->setIteratorIndex($i);
        $im = $IMagick->getImage();
        
        $w = $im->getImageWidth();
        if ($w < QRSIZE) { continue; }
        $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        $im->cropImage(QRSIZE, QRSIZE, ($w / 2) - (QRSIZE / 2), QRYPOS);
        $im->setImageFormat('png');
        $im->writeImage($i . '.png');
        $output = [];
        $qrreader = new QRReader($im->getImageBlob(), QRReader::SOURCE_TYPE_BLOB);
        $im->destroy();
        $text = $qrreader->text(['TRY_HARDER' => true]);
        if ($text !== false && preg_match('/\+\+\+BIZCUIT_SEPARATOR_PAGE_([A|B|C|D])\+\+\+/i', $text, $matches)) {
            echo 'SEPARATOR PAGE ';
            $rotate = 0;
            switch($matches[1]) {
                case 'B': $rotate = 90; break;
                case 'C': $rotate = 180; break;
                case 'D': $rotate = 270; break;
            }
            $pdf = new Fpdi();
            $pdf->setSourceFile($filename);
            for ($j = $from; $j < $i; $j++) {
                $pdf->AddPage('P', 'A4', $rotate);
                $pdf->useTemplate($pdf->importPage($j + 1));
            }
            $pdf->Output('file' . $doc . '.pdf', 'F');
            $doc++;
            $from = $i + 1;
            
            continue;
        }
        
        
        
    }
    if ($i < $j) {
        $pdf = new Fpdi();
        $pdf->setSourceFile($filename);
        for ($j = $from; $j < $i; $j++) {
            $pdf->AddPage('P', 'A4', $rotate);
            $pdf->useTemplate($pdf->importPage($j + 1));
        }
        $pdf->Output('file' . $doc . '.pdf', 'F');
    }
}