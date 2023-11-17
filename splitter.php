<?php
namespace BizCuit\Splitter;

const DENSITY = 300;
const RATIO = DENSITY / 25.4;
const QRSIZE = 60 * RATIO;
const QRYPOS = 5 * RATIO;

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


function getSeparatorPage (Imagick $IMagick, int $imageNumber):string|false {
    $IMagick->setIteratorIndex($imageNumber);
    $im = $IMagick->getImage();
    
    $w = $im->getImageWidth();
    if ($w < QRSIZE) { return false; }
    $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
    $im->cropImage(QRSIZE, QRSIZE, ($w / 2) - (QRSIZE / 2), QRYPOS);

    /* As QR Code are quite big, when scanning with a low end scanner qr is 
     * filled with white spots. Bluring, contrast and scaling remove those
     * spots.
     */
    $im->adaptiveBlurImage(0, 1);
    $im->brightnessContrastImage(0, 100);
    $im->scaleImage(QRSIZE / 1.4, QRSIZE / 1.4);
    $im->setImageFormat('png');

    $qrreader = new QRReader($im->getImageBlob(), QRReader::SOURCE_TYPE_BLOB);
    $im->destroy();
    $text = $qrreader->text(['TRY_HARDER' => true]);
    if ($text !== false && preg_match('/\+\+\+BIZCUIT_SEPARATOR_PAGE_([A|B|C|D])\+\+\+/i', $text, $matches)) {
        return $matches[1];
    }
    return false;
}

function createPDFFrom (string $source, int $from, int $to, array $format, $rotate):string {
    $pdf = new Fpdi();
    $pdf->setSourceFile($source);
    $format = getPDFFormat($pdf);
    for ($j = $from; $j < $to; $j++) {
        $pdf->AddPage($format[1], $format[0], $rotate);
        $pdf->useTemplate($pdf->importPage($j + 1));
    }
    return $pdf->Output('S');
}

function splitter (string $filename):Generator {
    $filename = realpath($filename);
    $IMagick = new Imagick();
    if (!$IMagick->setResolution(DENSITY, DENSITY)) { return false; }
    if (!$IMagick->readImage($filename)) { return false; }
    $whiteColor = new ImagickPixel('white');
    if (!$IMagick->setImageBackgroundColor($whiteColor)) { return false; }
    
    $from = 0;
    $rotate = 0;
    $format = ['A4', 'P'];
    $end = $max = $IMagick->getNumberImages();
    /* in case someone put a separator page at the beginning of the document */
    while (getSeparatorPage($IMagick, $from) !== false) { $from++; }
    for ($i = 0; $i < $max; $i++) {   
        if (($rotationType = getSeparatorPage($IMagick, $i)) !== false) {
            $rotate = 0;
            switch($rotationType) {
                case 'B': $rotate = 90; break;
                case 'C': $rotate = 180; break;
                case 'D': $rotate = 270; break;
            }
            /* when doing r/v scan, there's two separator page in a row. Avoid
             * creating a blank pdf
             */
            if ($from !== $i) {
                yield createPDFFrom($filename, $from, $i, $format, $rotate);
            }
            /* save in case we break out the loop */
            $end = $i;
            /* skip verso separator page */
            $i++;
            while ($i < $max && getSeparatorPage($IMagick, $i) !== false) { $i++; }

            if ($i >= $max) { break; }
            $from = $i;
            continue;
        }        
    }
    if ($from < $max) {
        yield createPDFFrom($filename, $from, $max, $format, $rotate);
    }
}