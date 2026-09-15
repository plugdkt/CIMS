<?php

declare(strict_types=1);

namespace App\Livewire\Chemicals;

use App\Domain\Chemicals\Services\PubChemClient;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Standalone PubChem (NIH) lookup for procurement research — "is this the right
 * compound before we buy it, and what hazard class does it carry" — reachable
 * without going through the item-create form at all. Gated on a bare `item.view`
 * permission (see ChemicalLookupController's own docblock for why no Policy class).
 */
#[Layout('components.layout')]
final class PubchemLookup extends Component
{
    public string $query = '';

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

    public function mount(): void
    {
        $this->authorize('item.view');
    }

    public function search(PubChemClient $client): void
    {
        $this->authorize('item.view');
        $this->validate(['query' => ['required', 'string', 'max:255']]);

        $this->searched = true;
        $result = $this->by === 'cas' ? $client->lookupByCas($this->query) : $client->lookupByName($this->query);

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
    }

    public function render(): View
    {
        return view('livewire.chemicals.pubchem-lookup');
    }
}
