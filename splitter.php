<?php
namespace BizCuit\Splitter;
require_once(__DIR__ . '/vendor/autoload.php');

define('DENSITY', 300);
/* DENSITY is in INCHES per PIXELS, so 300 DPI is 300/25.4 = 11.811023622 pixels */	
define('RATIO', DENSITY / 25.4);
define('QRSIZE', 60 * RATIO);
define('QRYPOS', 5 * RATIO);

use Generator;
use setasign\Fpdi\Tfpdf\Fpdi;
use Imagick;
use ImagickPixel;
use Zxing\QrReader;

function getPDFFormat ($pdf) {
    $page = intval($pdf->GetPageHeight()) .'x'. intval($pdf->GetPageWidth());
    switch($page) {
        case '297x210': return ['A4', 'P'];
        case '210x297': return ['A4', 'L'];
        case '420x297': return ['A3', 'P'];
        case '297x420': return ['A3', 'L'];
        case '148x210': return ['A5', 'P'];
        case '210x148': return ['A5', 'L'];
        /* not sure about letter/legal dimensions, it such a mess */
        case '216x279': return ['Letter', 'P'];
        case '279x216': return ['Letter', 'L'];
        case '356x279': return ['Legal', 'P'];
        case '279x356': return ['Legal', 'L'];
    }
}

function splitter (string $filename):Generator {
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
    $format = ['A4', 'P'];
    for ($i = 0; $i < $IMagick->getNumberImages(); $i++) {   
        $IMagick->setIteratorIndex($i);
        $im = $IMagick->getImage();
        
        $w = $im->getImageWidth();
        if ($w < QRSIZE) { continue; }
        $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        $im->cropImage(QRSIZE, QRSIZE, ($w / 2) - (QRSIZE / 2), QRYPOS);
        $im->setImageFormat('png');
        $im->writeImage($i . '.png');
        $qrreader = new QRReader($im->getImageBlob(), QRReader::SOURCE_TYPE_BLOB);
        $im->destroy();
        $text = $qrreader->text(['TRY_HARDER' => true]);
        if ($text !== false && preg_match('/\+\+\+BIZCUIT_SEPARATOR_PAGE_([A|B|C|D])\+\+\+/i', $text, $matches)) {
            $rotate = 0;
            switch($matches[1]) {
                case 'B': $rotate = 90; break;
                case 'C': $rotate = 180; break;
                case 'D': $rotate = 270; break;
            }
            $pdf = new Fpdi();
            $pdf->setSourceFile($filename);
            $format = getPDFFormat($pdf);
            for ($j = $from; $j < $i; $j++) {
                $pdf->AddPage($format[1], $format[0], $rotate);
                $pdf->useTemplate($pdf->importPage($j + 1));
            }
            yield $pdf->Output('S');
            $doc++;
            $from = $i + 1;
            
            continue;
        }        
    }
    if ($from < $i) {
        $pdf = new Fpdi();
        $pdf->setSourceFile($filename);
        $format = getPDFFormat($pdf);
        for ($j = $from; $j < $i; $j++) {
            $pdf->AddPage($format[1], $format[0], $rotate);
            $pdf->useTemplate($pdf->importPage($j + 1));
        }
        yield $pdf->Output('S');
    }
}