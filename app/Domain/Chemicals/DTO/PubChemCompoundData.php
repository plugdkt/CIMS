<?php

declare(strict_types=1);

namespace App\Domain\Chemicals\DTO;

/** One PubChem compound record, already narrowed down to what `items` can use. */
final readonly class PubChemCompoundData
{
    /**
     * @param  list<string>  $ghsCodes  pictogram class codes (e.g. "GHS05") already
     *                                  filtered to ones this app's config/ghs.php knows
     * @param  list<string>  $hStatements  hazard statement codes (e.g. "H314"), same filtering
     * @param  list<string>  $pStatements  precautionary statement codes (e.g. "P280"), same filtering
     */
    public function __construct(
        public int $cid,
        public string $title,
        public ?string $molecularFormula,
        public ?string $molecularWeight,
        public ?string $iupacName,
        public ?string $signalWord,
        public array $ghsCodes,
        public array $hStatements,
        public array $pStatements,
    ) {
    }
}
