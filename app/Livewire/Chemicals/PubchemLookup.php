<?php

declare(strict_types=1);

namespace App\Livewire\Chemicals;

use App\Domain\Chemicals\DTO\PubChemCompoundData;
use App\Domain\Chemicals\Services\ChemicalNameSanitizer;
use App\Domain\Chemicals\Services\ChemicalSyncService;
use App\Domain\Chemicals\Services\PubChemClient;
use App\Models\Item;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Standalone PubChem (NIH) lookup for procurement research and registry synchronization.
 * Supports 1-click sync to registry or updating existing matching items.
 */
#[Layout('components.layout')]
final class PubchemLookup extends Component
{
    #[Url(as: 'q')]
    public string $query = '';

    #[Url(as: 'by')]
    public string $by = 'cas';

    public bool $searched = false;

    public bool $found = false;

    public ?int $cid = null;

    public ?string $title = null;

    public ?string $molecularFormula = null;

    public ?string $molecularWeight = null;

    public ?string $iupacName = null;

    public ?string $signalWord = null;

    /** @var list<string> */
    public array $ghsCodes = [];

    /** @var list<string> */
    public array $hStatements = [];

    /** @var list<string> */
    public array $pStatements = [];

    public ?int $matchedItemId = null;

    public ?string $matchedItemCode = null;

    public ?string $matchedItemName = null;

    public ?string $matchedItemUlid = null;

    public ?string $sanitizedQuery = null;

    public function mount(PubChemClient $client, ChemicalNameSanitizer $sanitizer): void
    {
        $this->authorize('viewAny', Item::class);

        if (trim($this->query) !== '') {
            $this->search($client, $sanitizer);
        }
    }

    public function search(PubChemClient $client, ChemicalNameSanitizer $sanitizer): void
    {
        $this->authorize('viewAny', Item::class);
        $this->validate(['query' => ['required', 'string', 'max:255']]);

        $this->searched = true;
        $this->sanitizedQuery = null;
        $rawQuery = trim($this->query);
        $result = null;

        if ($this->by === 'cas') {
            $cas = $sanitizer->extractCas($rawQuery) ?? $rawQuery;
            $result = $client->lookupByCas($cas);
        } else {
            $result = $client->lookupByName($rawQuery);
            if ($result === null) {
                // Try sanitized chemical name if raw query had %, grades, or commercial terms
                $cleaned = $sanitizer->sanitize($rawQuery);
                if ($cleaned !== '' && mb_strtolower($cleaned) !== mb_strtolower($rawQuery)) {
                    $result = $client->lookupByName($cleaned);
                    if ($result !== null) {
                        $this->sanitizedQuery = $cleaned;
                    }
                }
            }
        }

        $this->found = $result !== null;

        if ($result === null) {
            $this->cid = null;
            $this->title = null;
            $this->molecularFormula = null;
            $this->molecularWeight = null;
            $this->iupacName = null;
            $this->signalWord = null;
            $this->ghsCodes = [];
            $this->hStatements = [];
            $this->pStatements = [];
            $this->matchedItemId = null;
            $this->matchedItemCode = null;
            $this->matchedItemName = null;
            $this->matchedItemUlid = null;

            return;
        }

        $this->cid = $result->cid;
        $this->title = $result->title;
        $this->molecularFormula = $result->molecularFormula;
        $this->molecularWeight = $result->molecularWeight;
        $this->iupacName = $result->iupacName;
        $this->signalWord = $result->signalWord;
        $this->ghsCodes = $result->ghsCodes;
        $this->hStatements = $result->hStatements;
        $this->pStatements = $result->pStatements;

        // Primary matching: CAS No. (exact). Secondary fallback: exact case-insensitive name match.
        $matched = null;
        $casQuery = trim($this->query);
        if ($this->by === 'cas' && $casQuery !== '') {
            $matched = Item::where('cas_no', $casQuery)->first();
        }
        if ($matched === null && trim($this->title) !== '') {
            $titleLower = mb_strtolower(trim($this->title));
            $matched = Item::whereRaw('LOWER(name_en) = ?', [$titleLower])
                ->orWhereRaw('LOWER(name_th) = ?', [$titleLower])
                ->first();
        }

        if ($matched !== null) {
            $this->matchedItemId = $matched->id;
            $this->matchedItemCode = $matched->item_code;
            $this->matchedItemName = $matched->name_th;
            $this->matchedItemUlid = $matched->ulid;
        } else {
            $this->matchedItemId = null;
            $this->matchedItemCode = null;
            $this->matchedItemName = null;
            $this->matchedItemUlid = null;
        }
    }

    public function syncToRegistry(ChemicalSyncService $syncService): mixed
    {
        $this->authorize('create', Item::class);

        if (! $this->found || $this->cid === null || $this->title === null) {
            return null;
        }

        $compound = new PubChemCompoundData(
            cid: $this->cid,
            title: $this->title,
            molecularFormula: $this->molecularFormula,
            molecularWeight: $this->molecularWeight,
            iupacName: $this->iupacName,
            signalWord: $this->signalWord,
            ghsCodes: $this->ghsCodes,
            hStatements: $this->hStatements,
            pStatements: $this->pStatements,
        );

        $cas = $this->by === 'cas' ? $this->query : null;
        $item = $syncService->createFromPubChem($compound, $cas);

        session()->flash('status', __('chemicals.sync_1click_success')." ({$item->item_code})");

        return redirect()->route('items.show', $item);
    }

    public function syncExistingItem(ChemicalSyncService $syncService): mixed
    {
        if ($this->matchedItemId === null) {
            return null;
        }

        $item = Item::find($this->matchedItemId);
        if ($item === null) {
            return null;
        }

        $this->authorize('update', $item);

        $syncService->syncItem($item);
        session()->flash('status', __('chemicals.sync_success')." ({$item->item_code})");

        return redirect()->route('items.show', $item);
    }

    public function render(): View
    {
        $matchedItem = $this->matchedItemId !== null ? Item::find($this->matchedItemId) : null;

        return view('livewire.chemicals.pubchem-lookup', [
            'matchedItem' => $matchedItem,
        ]);
    }
}
