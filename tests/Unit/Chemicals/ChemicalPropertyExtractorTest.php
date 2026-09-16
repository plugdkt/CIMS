<?php

declare(strict_types=1);

use App\Domain\Chemicals\Services\ChemicalPropertyExtractor;

test('ChemicalPropertyExtractor strictly preserves IUPAC locant numbers', function () {
    $extractor = new ChemicalPropertyExtractor();

    $result1 = $extractor->extractAndClean('1,3-Butylene Glycol');
    expect($result1['clean_name'])->toBe('1,3-Butylene Glycol');

    $result2 = $extractor->extractAndClean('1,4-Phenylenediamine Sulfate >98.0%(GC)(T');
    expect($result2['clean_name'])->toBe('1,4-Phenylenediamine Sulfate');

    $result3 = $extractor->extractAndClean('2,4-D [ C8H6Cl2O3] /solid');
    expect($result3['clean_name'])->toBe('2,4-D');
    expect($result3['formula'])->toBe('C8H6Cl2O3');
    expect($result3['physical_state'])->toBe('solid');

    $result4 = $extractor->extractAndClean('1,2-Dichloroethane AR grade');
    expect($result4['clean_name'])->toBe('1,2-Dichloroethane');
    expect($result4['grade'])->toBe('AR');
});

test('ChemicalPropertyExtractor extracts physical states accurately in English and Thai', function () {
    $extractor = new ChemicalPropertyExtractor();

    expect($extractor->extractAndClean('Sodium Hydroxide /powder')['physical_state'])->toBe('powder');
    expect($extractor->extractAndClean('Ethanol 99.5% liquid')['physical_state'])->toBe('liquid');
    expect($extractor->extractAndClean('Sodium Chloride (solid)')['physical_state'])->toBe('solid');
    expect($extractor->extractAndClean('Formaldehyde 37% solution')['physical_state'])->toBe('solution');
    expect($extractor->extractAndClean('Nitrogen gas')['physical_state'])->toBe('gas');
    expect($extractor->extractAndClean('Copper Sulfate crystal')['physical_state'])->toBe('crystal');
    expect($extractor->extractAndClean('Potassium Hydroxide pellet')['physical_state'])->toBe('pellet');

    // Thai states
    expect($extractor->extractAndClean('โซเดียมคลอไรด์ (ผง)')['physical_state'])->toBe('powder');
    expect($extractor->extractAndClean('เอทานอล ของเหลว')['physical_state'])->toBe('liquid');
    expect($extractor->extractAndClean('กรดไฮโดรคลอริก สารละลาย')['physical_state'])->toBe('solution');
});

test('ChemicalPropertyExtractor extracts grades and cleans assay/purity expressions', function () {
    $extractor = new ChemicalPropertyExtractor();

    $ar = $extractor->extractAndClean('Acetone AR 99.5%');
    expect($ar['clean_name'])->toBe('Acetone');
    expect($ar['grade'])->toBe('AR');

    $hplc = $extractor->extractAndClean('Methanol HPLC grade >99.8%');
    expect($hplc['clean_name'])->toBe('Methanol');
    expect($hplc['grade'])->toBe('HPLC');

    $cosmetic = $extractor->extractAndClean('Glycerin Cosmetic grade');
    expect($cosmetic['clean_name'])->toBe('Glycerin');
    expect($cosmetic['grade'])->toBe('Cosmetic');

    $technical = $extractor->extractAndClean('Ethanol Technical');
    expect($technical['clean_name'])->toBe('Ethanol');
    expect($technical['grade'])->toBe('Technical');
});

test('ChemicalPropertyExtractor safely handles Thai multibyte UTF-8 without byte corruption', function () {
    $extractor = new ChemicalPropertyExtractor();

    $thaiResult = $extractor->extractAndClean('แอลกอฮอล์สำหรับล้างแผล 70% /liquid');
    expect($thaiResult['clean_name'])->toBe('แอลกอฮอล์สำหรับล้างแผล');
    expect($thaiResult['physical_state'])->toBe('liquid');
});
