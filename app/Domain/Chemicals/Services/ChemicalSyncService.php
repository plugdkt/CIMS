<?php

declare(strict_types=1);

namespace App\Domain\Chemicals\Services;

use App\Domain\Chemicals\DTO\PubChemCompoundData;
use App\Domain\Shared\DocumentNumberGenerator;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\ItemCategory;
use Illuminate\Support\Facades\DB;

final class ChemicalSyncService
{
    public function __construct(
        private readonly PubChemClient $client,
        private readonly DocumentNumberGenerator $documentNumberGenerator,
        private readonly ChemicalNameSanitizer $sanitizer,
    ) {
    }

    /**
     * Synchronize an existing item with PubChem data.
     * Multi-tier lookup:
     * 1. By item's CAS No.
     * 2. By CAS No. extracted from name_th/name_en if missing on model
     * 3. By exact name_en
     * 4. By exact name_th (if ASCII)
     * 5. By sanitized chemical name (strips %, grades, brackets, commercial notes)
     *
     * Records an entity-level AuditLog for any modified safety and chemical data.
     */
    public function syncItem(Item $item): bool
    {
        $compound = null;

        // 1. Direct CAS lookup if available
        if ($item->cas_no !== null && trim($item->cas_no) !== '') {
            $compound = $this->client->lookupByCas(trim($item->cas_no));
        }

        // 2. Try extracting CAS from name if item has no cas_no
        if ($compound === null) {
            $extractedCas = $this->sanitizer->extractCas($item->name_th.' '.($item->name_en ?? ''));
            if ($extractedCas !== null) {
                $compound = $this->client->lookupByCas($extractedCas);
                if ($compound !== null && ($item->cas_no === null || trim($item->cas_no) === '')) {
                    $item->cas_no = $extractedCas;
                }
            }
        }

        // 3. Lookup by exact name_en
        if ($compound === null && $item->name_en !== null && trim($item->name_en) !== '') {
            $compound = $this->client->lookupByName(trim($item->name_en));
        }

        // 4. Lookup by exact name_th (if pure ASCII)
        if ($compound === null && $item->name_th !== '' && preg_match('/^[a-zA-Z0-9\s\-]+$/', $item->name_th)) {
            $compound = $this->client->lookupByName(trim($item->name_th));
        }

        // 5. Sanitized name lookup (strips percentages, grades, brackets, Thai text)
        if ($compound === null) {
            $nameCandidate = $item->name_en ?: $item->name_th;
            $cleaned = $this->sanitizer->sanitize($nameCandidate);
            if ($cleaned !== '' && preg_match('/[a-zA-Z]/', $cleaned) && mb_strlen($cleaned) >= 3) {
                $compound = $this->client->lookupByName($cleaned);
            }
        }

        if ($compound === null) {
            return false;
        }

        DB::transaction(function () use ($item, $compound) {
            $before = [
                'cas_no' => $item->cas_no,
                'formula' => $item->formula,
                'name_en' => $item->name_en,
                'ghs_codes' => $item->ghs_codes,
                'h_statements' => $item->h_statements,
                'p_statements' => $item->p_statements,
                'specification' => $item->specification,
            ];

            if ($compound->molecularFormula !== null && $compound->molecularFormula !== '') {
                $item->formula = mb_substr($compound->molecularFormula, 0, 128);
            }

            if (($item->name_en === null || trim($item->name_en) === '') && $compound->title !== '') {
                $item->name_en = mb_substr($compound->title, 0, 255);
            }

            $item->ghs_codes = $compound->ghsCodes;
            $item->h_statements = $compound->hStatements;
            $item->p_statements = $compound->pStatements;

            $specParagraph = $this->buildSpecificationParagraph($compound, $item->cas_no, $item->grade);
            $item->specification = $this->mergeSpecification($item->specification, $specParagraph);

            $item->save();

            $after = [
                'cas_no' => $item->cas_no,
                'formula' => $item->formula,
                'name_en' => $item->name_en,
                'ghs_codes' => $item->ghs_codes,
                'h_statements' => $item->h_statements,
                'p_statements' => $item->p_statements,
                'specification' => $item->specification,
            ];

            $authId = auth()->id();
            AuditLog::record(
                action: 'PUBCHEM_SYNC',
                result: 'SUCCESS',
                userId: $authId !== null ? (int) $authId : null,
                username: auth()->user()?->username,
                entityType: Item::class,
                entityId: $item->id,
                oldValue: $before,
                newValue: $after,
                message: "PubChem CID: {$compound->cid}",
            );
        });

        return true;
    }

    /**
     * Create a new Item directly from PubChem compound data.
     * Prevents TOCTOU duplicates inside transaction by checking CAS with row lock.
     */
    public function createFromPubChem(PubChemCompoundData $compound, ?string $cas = null): Item
    {
        return DB::transaction(function () use ($compound, $cas) {
            if ($cas !== null && trim($cas) !== '') {
                $existing = Item::where('cas_no', trim($cas))->lockForUpdate()->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            $categoryId = ItemCategory::where('code', 'CHEMICAL')->value('id')
                ?? ItemCategory::firstOrFail()->id;

            $itemCode = $this->generateNextItemCode();
            $casForSpec = $cas !== null && trim($cas) !== '' ? trim($cas) : null;

            return Item::create([
                'item_code' => $itemCode,
                'category_id' => $categoryId,
                'name_th' => mb_substr($compound->title, 0, 255),
                'name_en' => mb_substr($compound->title, 0, 255),
                'cas_no' => $casForSpec !== null ? mb_substr($casForSpec, 0, 20) : null,
                'formula' => $compound->molecularFormula !== null ? mb_substr($compound->molecularFormula, 0, 128) : null,
                'ghs_codes' => $compound->ghsCodes,
                'h_statements' => $compound->hStatements,
                'p_statements' => $compound->pStatements,
                'specification' => 'นำเข้าอัตโนมัติจาก PubChem — '.$this->buildSpecificationParagraph($compound, $casForSpec, null),
                // ponytail: base_unit_id is intentionally left null per the working-stock convention;
                // it is set/backfilled upon initial goods receipt (GRN) when packaging and unit are physically received.
                'base_unit_id' => null,
                'is_active' => true,
            ]);
        });
    }

    /**
     * One flowing procurement-usable line combining what's actually available — never
     * invents a grade/purity PubChem doesn't provide; only echoes it back when the item
     * (or import parsing) already recorded one.
     */
    private function buildSpecificationParagraph(PubChemCompoundData $compound, ?string $cas, ?string $grade): string
    {
        $parts = [];

        if ($compound->molecularFormula !== null && $compound->molecularFormula !== '') {
            $parts[] = "สูตรโมเลกุล {$compound->molecularFormula}";
        }

        if ($compound->molecularWeight !== null) {
            $parts[] = "น้ำหนักโมเลกุล (MW) {$compound->molecularWeight} g/mol";
        }

        if ($cas !== null && trim($cas) !== '') {
            $parts[] = 'CAS No. '.trim($cas);
        }

        if ($compound->physicalDescription !== null) {
            $parts[] = "ลักษณะทางกายภาพ: {$compound->physicalDescription}";
        }

        if ($grade !== null && trim($grade) !== '') {
            $parts[] = 'เกรด '.trim($grade);
        }

        $summary = implode(', ', $parts);

        return ($summary !== '' ? "{$summary} " : '')."(PubChem CID: {$compound->cid})";
    }

    /**
     * Replaces a previously-written PubChem line in place (identified by its own CID
     * marker) so repeated syncs refresh the data instead of endlessly appending
     * duplicate lines — any other free text already in the field (e.g. from the
     * original catalog import) is left untouched.
     */
    private function mergeSpecification(?string $existing, string $pubChemParagraph): string
    {
        if ($existing === null || trim($existing) === '') {
            return $pubChemParagraph;
        }

        $lines = preg_split('/\r\n|\r|\n/', $existing);
        $lines = $lines !== false ? $lines : [$existing];

        foreach ($lines as $i => $line) {
            if (str_contains($line, '(PubChem CID:')) {
                $lines[$i] = $pubChemParagraph;

                return implode("\n", $lines);
            }
        }

        return $existing."\n".$pubChemParagraph;
    }

    /**
     * Generate the next available sequential item code with prefix CHM- via DocumentNumberGenerator.
     * Guarantees atomic generation with row lock and skips any colliding manual codes.
     */
    public function generateNextItemCode(): string
    {
        do {
            $candidate = $this->documentNumberGenerator->next('CHM');
        } while (Item::where('item_code', $candidate)->exists());

        return $candidate;
    }
}
