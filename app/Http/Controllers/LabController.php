<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\LabRequest;
use App\Models\Lab;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

final class LabController extends Controller
{
    public function create(): View
    {
        $this->authorize('create', Lab::class);

        return view('labs.form', ['lab' => new Lab()]);
    }

    public function store(LabRequest $request): RedirectResponse
    {
        $lab = Lab::create($request->validated());

        return redirect()->route('admin.labs.edit', $lab)->with('status', __('labs.saved'));
    }

    public function edit(Lab $lab): View
    {
        $this->authorize('update', $lab);

        return view('labs.form', ['lab' => $lab]);
    }

    public function update(LabRequest $request, Lab $lab): RedirectResponse
    {
        $lab->update($request->validated());

        return redirect()->route('admin.labs.edit', $lab)->with('status', __('labs.saved'));
    }
}
