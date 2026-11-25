<?php

namespace Modules\Ortofrutta\Support;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\Writer\SvgWriter;
use Throwable;

/**
 * QR codes for the labels of bins, cells and lines, drawn with the library
 * core already uses for its own labels. An image as a data URI, so a label is
 * self-contained and prints without another request.
 */
final class Qr
{
    public static function dataUri(string $data, int $size = 200): ?string
    {
        try {
            return Builder::create()
                ->writer(new SvgWriter)
                ->data($data)
                ->encoding(new Encoding('UTF-8'))
                ->size($size)
                ->margin(8)
                ->build()
                ->getDataUri();
        } catch (Throwable $e) {
            // A label without its picture still shows its code in letters.
            report($e);

            return null;
        }
    }
}
