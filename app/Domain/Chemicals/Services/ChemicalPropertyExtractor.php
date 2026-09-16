<?php

declare(strict_types=1);

namespace App\Domain\Chemicals\Services;

final class ChemicalPropertyExtractor
{
    /**
     * @return array{
     *     clean_name: string,
     *     physical_state: ?string,
     *     formula: ?string,
     *     grade: ?string
     * }
     */
    public function extractAndClean(string $name): array
    {
        $cleanName = trim($name);
        $state = null;
        $extractedFormula = null;
        $extractedGrade = null;

        // 1. Extract formula inside brackets e.g. [ C8H6Cl2O3]
        if (preg_match('/\[\s*([A-Za-z0-9\(\)\.\s]+)\s*\]/u', $cleanName, $m)) {
            $candidateFormula = trim($m[1]);
            // Check if looks like a chemical formula (contains uppercase letter and letters/numbers)
            if (preg_match('/^[A-Za-z0-9\(\)\.\s]+$/u', $candidateFormula) && preg_match('/[A-Z]/u', $candidateFormula)) {
                $extractedFormula = preg_replace('/\s+/u', '', $candidateFormula);
                $cleanName = (string) preg_replace('/\[\s*[A-Za-z0-9\(\)\.\s]+\s*\]/u', ' ', $cleanName);
            }
        }

        // 2. Extract Physical State: powder, liquid, solid, gas, solution, crystal, pellet
        if (preg_match('/(?:\/|\(|\b)(powder|liquid|solid|gas|solution|crystal|pellet|ผง|ของเหลว|ของแข็ง|สารละลาย|ก๊าซ|แก๊ส)(?:\)|\b|\/|$)/iu', $cleanName, $m)) {
            $rawState = mb_strtolower($m[1]);
            $stateMap = [
                'powder' => 'powder',
                'ผง' => 'powder',
                'liquid' => 'liquid',
                'ของเหลว' => 'liquid',
                'solid' => 'solid',
                'ของแข็ง' => 'solid',
                'gas' => 'gas',
                'ก๊าซ' => 'gas',
                'แก๊ส' => 'gas',
                'solution' => 'solution',
                'สารละลาย' => 'solution',
                'crystal' => 'crystal',
                'ผลึก' => 'crystal',
                'pellet' => 'pellet',
                'เกล็ด' => 'pellet',
            ];
            $state = $stateMap[$rawState] ?? $rawState;

            // Strip state keyword from name if preceded by space, slash, or paren
            $cleanName = (string) preg_replace('/(?:\/|\s*\(\s*|\s+)(?:powder|liquid|solid|gas|solution|crystal|pellet|ผง|ของเหลว|ของแข็ง|สารละลาย|ก๊าซ|แก๊ส)(?:\s*\)|\s*|\/|$)/iu', ' ', $cleanName);
        }

        // 3. Extract Grade: AR, ACS, HPLC, GC, GR, CP, Technical, Cosmetic, Food grade, etc.
        if (preg_match('/\b(HPLC grade|AR grade|ACS grade|Technical grade|Cosmetic grade|Food grade|HPLC|AR|ACS|GR|CP|Cosmetic|Technical)\b/iu', $cleanName, $m)) {
            $extractedGrade = strtoupper(trim(str_ireplace('grade', '', $m[1])));
            if ($extractedGrade === 'COSMETIC') {
                $extractedGrade = 'Cosmetic';
            } elseif ($extractedGrade === 'TECHNICAL') {
                $extractedGrade = 'Technical';
            }

            // Strip grade keyword from name
            $cleanName = (string) preg_replace('/\b(?:HPLC grade|AR grade|ACS grade|Technical grade|Cosmetic grade|Food grade|HPLC|AR|ACS|GR|CP|Cosmetic|Technical)\b/iu', ' ', $cleanName);
        }

        // 4. Clean purity ranges and assay percentages e.g. 28-30%, >98.0%(GC)(T, 99.5%
        $cleanName = (string) preg_replace('/[>≥]?\s*\d+(\.\d+)?(\s*(?:-|to|–)\s*\d+(\.\d+)?)?\s*%\s*(?:\([A-Za-z0-9\/\+\-\.]+\))*/u', ' ', $cleanName);

        // 5. Clean dangling opening parentheses or trailing slashes e.g. "(T" or "/" or trailing dots
        $cleanName = (string) preg_replace('/[\s\/,\.]+$/u', '', $cleanName);
        $cleanName = (string) preg_replace('/\s*\([A-Za-z0-9]*\s*$/u', '', $cleanName);
        $cleanName = (string) preg_replace('/\(\s*([A-Za-z0-9]+)\s*\)/u', '($1)', $cleanName);
        $cleanName = (string) preg_replace('/\(\s*\)/u', '', $cleanName);

        // 6. Normalize whitespace, preserve IUPAC locants
        $cleanName = trim((string) preg_replace('/\s+/u', ' ', $cleanName));

        return [
            'clean_name' => $cleanName,
            'physical_state' => $state,
            'formula' => $extractedFormula,
            'grade' => $extractedGrade,
        ];
    }
}
