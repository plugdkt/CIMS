<?php

declare(strict_types=1);

namespace App\Domain\Chemicals\Services;

use App\Domain\Chemicals\DTO\PubChemCompoundData;
use App\Models\Item;
use App\Models\ItemCategory;
use Illuminate\Support\Facades\DB;

final class ChemicalSyncService
{
    public function __construct(
        private readonly PubChemClient $client,
    ) {
    }

    /**
     * Synchronize an existing item with PubChem data.
     * Looks up by CAS first, then by name_en, then by name_th.
     */
    public function syncItem(Item $item): bool
    {
        $compound = null;

        if ($item->cas_no !== null && trim($item->cas_no) !== '') {
            $compound = $this->client->lookupByCas(trim($item->cas_no));
        }

        if ($compound === null && $item->name_en !== null && trim($item->name_en) !== '') {
            $compound = $this->client->lookupByName(trim($item->name_en));
        }

        if ($compound === null && $item->name_th !== '' && preg_match('/^[a-zA-Z0-9\s\-]+$/', $item->name_th)) {
            $compound = $this->client->lookupByName(trim($item->name_th));
        }

        if ($compound === null) {
            return false;
        }

        DB::transaction(function () use ($item, $compound) {
            if ($compound->molecularFormula !== null && $compound->molecularFormula !== '') {
                $item->formula = mb_substr($compound->molecularFormula, 0, 128);
            }

            if (($item->name_en === null || trim($item->name_en) === '') && $compound->title !== '') {
                $item->name_en = mb_substr($compound->title, 0, 255);
            }

            $item->ghs_codes = $compound->ghsCodes;
            $item->h_statements = $compound->hStatements;
            $item->p_statements = $compound->pStatements;

            if ($compound->molecularWeight !== null) {
                $mwLine = "MW: {$compound->molecularWeight} g/mol (PubChem CID: {$compound->cid})";
                if ($item->specification === null || $item->specification === '') {
                    $item->specification = $mwLine;
                } elseif (! str_contains($item->specification, 'PubChem CID')) {
                    $item->specification .= "\n{$mwLine}";
                }
            }

            $item->save();
        });

        return true;
    }

    /**
     * Create a new Item directly from PubChem compound data.
     */
    public function createFromPubChem(PubChemCompoundData $compound, ?string $cas = null): Item
    {
        return DB::transaction(function () use ($compound, $cas) {
            $categoryId = ItemCategory::where('code', 'CHEMICAL')->value('id')
                ?? ItemCategory::firstOrFail()->id;

            $itemCode = $this->generateNextItemCode();

            $specLines = ["นำเข้าอัตโนมัติจาก PubChem (CID: {$compound->cid})"];
            if ($compound->molecularWeight !== null) {
                $specLines[] = "น้ำหนักโมเลกุล (MW): {$compound->molecularWeight} g/mol";
            }
            if ($compound->iupacName !== null) {
                $specLines[] = "IUPAC Name: {$compound->iupacName}";
            }

            return Item::create([
                'item_code' => $itemCode,
                'category_id' => $categoryId,
                'name_th' => mb_substr($compound->title, 0, 255),
                'name_en' => mb_substr($compound->title, 0, 255),
                'cas_no' => $cas !== null && trim($cas) !== '' ? mb_substr(trim($cas), 0, 20) : null,
                'formula' => $compound->molecularFormula !== null ? mb_substr($compound->molecularFormula, 0, 128) : null,
                'ghs_codes' => $compound->ghsCodes,
                'h_statements' => $compound->hStatements,
                'p_statements' => $compound->pStatements,
                'specification' => implode("\n", $specLines),
                'is_active' => true,
            ]);
        });
    }

    /**
     * Generate the next available sequential item code with prefix CHM-.
     */
    public function generateNextItemCode(): string
    {
        $lastCode = Item::where('item_code', 'REGEXP', '^CHM-[0-9]+$')
            ->orderByRaw('LENGTH(item_code) DESC, item_code DESC')
            ->value('item_code');

        if ($lastCode !== null && preg_match('/^CHM-(\d+)$/', $lastCode, $matches)) {
            $next = ((int) $matches[1]) + 1;
        } else {
            $next = 1;
        }

        $candidate = sprintf('CHM-%05d', $next);
        while (Item::where('item_code', $candidate)->exists()) {
            $next++;
            $candidate = sprintf('CHM-%05d', $next);
        }

        return $candidate;
    }
}
