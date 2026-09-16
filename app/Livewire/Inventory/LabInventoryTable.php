<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Models\Container;
use App\Models\Lab;
use App\Models\Location;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * "สต็อกคงคลังย่อยของฉัน" (docs/lab_inventory_handover_spec.md, งานที่ 1) — every SEALED/
 * IN_USE container with stock left, scoped to the viewer's own lab. Only ADMIN/AUDITOR
 * (who already see cross-lab data elsewhere, e.g. the §7.8 reports) get a lab picker;
 * everyone else's own `lab_id` always wins over anything the URL carries — same
 * "never widened via the query string" rule `ReportController::labIdFor()` already
 * applies, so a LAB_MANAGER/SCIENTIST/STAFF can't see another branch's stock by hand-
 * editing the URL.
 */
#[Layout('components.layout')]
final class LabInventoryTable extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public ?int $locationId = null;

    #[Url]
    public ?int $labId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Container::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedLocationId(): void
    {
        $this->resetPage();
    }

    public function updatedLabId(): void
    {
        $this->resetPage();
    }

    private function canPickAnyLab(User $user): bool
    {
        return $user->hasRole('ADMIN') || $user->hasRole('AUDITOR');
    }

    public function render(): View
    {
        /** @var User $user */
        $user = auth()->user();

        $privileged = $this->canPickAnyLab($user);
        $labId = $privileged ? $this->labId : $user->lab_id;
        // A non-privileged viewer with no lab_id has nothing to scope by — show
        // nothing, never every lab's stock (privileged + no $labId is the only case
        // that legitimately means "no filter, show every lab").
        $unassigned = ! $privileged && $labId === null;

        $containers = Container::query()
            ->with(['item.baseUnit', 'location'])
            ->whereIn('status', ['SEALED', 'IN_USE'])
            ->where('remaining_qty_base', '>', 0)
            ->when($unassigned, fn ($query) => $query->whereRaw('1 = 0'))
            ->when(! $unassigned && $labId !== null, fn ($query) => $query->whereHas('location', fn ($q) => $q->where('lab_id', $labId)))
            ->when($this->locationId !== null, fn ($query) => $query->where('location_id', $this->locationId))
            ->when(trim($this->search) !== '', function ($query) {
                $term = trim($this->search);
                $query->where(function ($q) use ($term) {
                    $q->where('barcode', 'like', "%{$term}%")
                        ->orWhereHas('item', function ($itemQuery) use ($term) {
                            $itemQuery->where('name_th', 'like', "%{$term}%")
                                ->orWhere('name_en', 'like', "%{$term}%")
                                ->orWhere('item_code', 'like', "%{$term}%");
                        });
                });
            })
            ->orderByRaw('expiry_date IS NULL, expiry_date ASC')
            ->paginate(20);

        $locations = Location::query()
            ->when($unassigned, fn ($query) => $query->whereRaw('1 = 0'))
            ->when(! $unassigned && $labId !== null, fn ($query) => $query->where('lab_id', $labId))
            ->orderBy('name')
            ->get();

        return view('livewire.inventory.lab-inventory-table', [
            'containers' => $containers,
            'locations' => $locations,
            'labs' => $privileged ? Lab::where('is_active', true)->orderBy('name_th')->get() : null,
            'canStockIn' => $this->userCanStockIn($user),
        ]);
    }

    /** Matches the same gate the sidebar's own "รับเข้าคลังย่อย" link already uses. */
    private function userCanStockIn(User $user): bool
    {
        return $user->can('receiving.manage') || $user->can('item.manage') || $user->can('ledger.adjust') || $user->hasRole('ADMIN');
    }
}
