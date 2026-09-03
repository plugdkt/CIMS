<?php

declare(strict_types=1);

namespace App\Domain\Labeling\Services;

use Picqer\Barcode\BarcodeGeneratorSVG;

/** Code 128 encodes any ASCII string (containers.barcode is a plain VARCHAR), so it's
 *  the one symbology every container/lot code can round-trip through without a format change. */
final class BarcodeGenerator
{
    private readonly BarcodeGeneratorSVG $generator;

    public function __construct()
    {
        $this->generator = new BarcodeGeneratorSVG();
    }

    /**
     * Picqer returns a full standalone SVG document (with an `<?xml ... ?>` prolog),
     * which an HTML parser (mPDF included) doesn't recognise mid-document — it just
     * prints the prolog as literal visible text. Strip everything before the root
     * `<svg` tag so this is safe to embed directly inline.
     */
    public function svg(string $code, float $widthFactor = 1.4, float $height = 18): string
    {
        $document = $this->generator->getBarcode($code, BarcodeGeneratorSVG::TYPE_CODE_128, $widthFactor, $height);
        $svgStart = strpos($document, '<svg');

        return $svgStart === false ? $document : substr($document, $svgStart);
    }
}
