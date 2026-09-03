<?php

declare(strict_types=1);

namespace App\Domain\Labeling\Services;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * Not yet wired into any page — built alongside the barcode generator per T-023's
 * own scope ("Barcode/QR generator"), ready for T-037's F-01 PDF QR-verify corner
 * once `/verify/{ulid}` exists.
 */
final class QrCodeGenerator
{
    /** No XML prolog in the output (see BarcodeGenerator) — safe to embed inline in HTML. */
    public function svg(string $data, int $size = 200): string
    {
        $qrCode = new QrCode(data: $data, size: $size);
        $options = [SvgWriter::WRITER_OPTION_EXCLUDE_XML_DECLARATION => true];

        return (new SvgWriter())->write($qrCode, options: $options)->getString();
    }
}
