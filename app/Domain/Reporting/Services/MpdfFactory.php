<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;

/**
 * Sarabun, for real — `resources/fonts/pdf/Sarabun-{Regular,Bold}.ttf` were built
 * once (not fetched from anywhere new) by merging the Thai + Latin subsets already
 * self-hosted under `public/fonts/` (T-010's web font split) back into complete
 * single-file fonts via `fontTools.merge`, since mPDF's embedder needs one TTF per
 * style, not a browser-style unicode-range split. See CLAUDE.md.
 */
final class MpdfFactory
{
    /** @param  array<string, mixed>  $config */
    public static function make(array $config = []): Mpdf
    {
        $tempDir = storage_path('framework/cache/mpdf');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $fontDirs = (new ConfigVariables())->getDefaults()['fontDir'];
        $fontData = (new FontVariables())->getDefaults()['fontdata'];

        return new Mpdf(array_merge([
            'tempDir' => $tempDir,
            'fontDir' => array_merge($fontDirs, [resource_path('fonts/pdf')]),
            'fontdata' => $fontData + [
                'sarabun' => [
                    'R' => 'Sarabun-Regular.ttf',
                    'B' => 'Sarabun-Bold.ttf',
                ],
            ],
            'default_font' => 'sarabun',
        ], $config));
    }
}
