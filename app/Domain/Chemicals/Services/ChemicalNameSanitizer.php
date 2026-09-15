<?php

declare(strict_types=1);

namespace App\Domain\Chemicals\Services;

final class ChemicalNameSanitizer
{
    /**
     * Common commercial keywords, packaging, forms, and grades to strip from chemical names.
     */
    private const KEYWORDS = [
        'grade', 'liquid', 'liquids', 'solid', 'solids', 'powder', 'solution',
        'flakes', 'synthetic', 'pure', 'commercial', 'cosmetic', 'technical',
        'for analysis', 'emsure', 'hplc', 'deb', 'hdpe', 'com', 'pcs',
        'ar', 'acs', 'gr', 'v/v', 'w/w', 'w/v',
    ];

    /**
     * Clean a raw chemical name by removing Thai text, percentages, grade keywords,
     * bracketed formulas/notes, and stray punctuation so it can be queried against PubChem.
     */
    public function sanitize(string $name): string
    {
        // 1. Remove Thai script characters
        $clean = preg_replace('/[\x{0E00}-\x{0E7F}]+/u', ' ', $name) ?? $name;

        // 2. Remove percentages (e.g. 95%, 99.9%, 70% v/v)
        $clean = preg_replace('#\b\d+(\.\d+)?\s*%\s*(v/v|w/w|w/v)?#i', ' ', $clean) ?? $clean;

        // 3. Remove parenthesized or bracketed content (e.g. formulas or notes like [(C2H5OH)])
        $clean = preg_replace('/\[.*?\]|\(.*?\)/', ' ', $clean) ?? $clean;

        // 4. Remove common commercial and grade keywords
        $quotedKeywords = array_map(fn (string $k) => preg_quote($k, '#'), self::KEYWORDS);
        $pattern = '#\b('.implode('|', $quotedKeywords).')\b#i';
        $clean = preg_replace($pattern, ' ', $clean) ?? $clean;

        // 5. Remove unwanted punctuation characters, keeping alphanumeric, spaces, and hyphens
        $clean = preg_replace('/[^a-zA-Z0-9\s\-]/', ' ', $clean) ?? $clean;

        // 6. Normalize multiple whitespaces into a single space and trim
        return trim(preg_replace('/\s+/', ' ', $clean) ?? '');
    }

    /**
     * Extract a valid CAS registry number from anywhere inside a text string if present.
     * Standard CAS format: 2-7 digits, hyphen, 2 digits, hyphen, 1 digit (e.g. 64-17-5).
     */
    public function extractCas(string $text): ?string
    {
        if (preg_match('#\b(\d{2,7}-\d{2}-\d)\b#', $text, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
