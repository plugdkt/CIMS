<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\Lab;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layout')]
final class LabTable extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', Lab::class);
    }

    public function render(): View
    {
        return view('livewire.admin.lab-table', [
            'labs' => Lab::orderBy('name_th')->get(),
        ]);
    }
}
