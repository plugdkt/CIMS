<?php

use App\Domain\Inventory\DTO\LedgerEntryData;
use App\Domain\Inventory\Services\LedgerService;
use App\Domain\Reporting\DTO\DateRangeFilter;
use App\Domain\Reporting\Exports\ControlledSubstancesExport;
use App\Domain\Reporting\Services\ControlledSubstancesPdfService;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('§7.8 controlled substances lists ledger movements only for is_controlled items', function () {
    $controlled = makeItem(['is_controlled' => true, 'control_class' => 'วัตถุออกฤทธิ์ประเภท 4']);
    $ordinary = makeItem(['is_controlled' => false]);
    $g = Unit::where('code', 'g')->firstOrFail();
    $user = User::factory()->create();

    app(LedgerService::class)->receive(makeContainer(['item_id' => $controlled->id])->id, '10.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $user->id));
    app(LedgerService::class)->receive(makeContainer(['item_id' => $ordinary->id])->id, '10.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $user->id));

    $rows = (new ControlledSubstancesExport(new DateRangeFilter()))->collection();

    expect($rows)->toHaveCount(1);
    expect($rows[0][1])->toBe($controlled->name_th);
    expect($rows[0][2])->toBe('วัตถุออกฤทธิ์ประเภท 4');
});

test('filtering by date range excludes movements outside the range', function () {
    $controlled = makeItem(['is_controlled' => true]);
    $g = Unit::where('code', 'g')->firstOrFail();
    $user = User::factory()->create();
    app(LedgerService::class)->receive(makeContainer(['item_id' => $controlled->id])->id, '10.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $user->id));

    $rows = (new ControlledSubstancesExport(new DateRangeFilter(dateFrom: now()->addDays(1)->toDateString())))->collection();

    expect($rows)->toHaveCount(0);
});

test('ControlledSubstancesPdfService renders a non-empty PDF', function () {
    $controlled = makeItem(['is_controlled' => true]);
    $g = Unit::where('code', 'g')->firstOrFail();
    $user = User::factory()->create();
    app(LedgerService::class)->receive(makeContainer(['item_id' => $controlled->id])->id, '10.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $user->id));

    $pdf = app(ControlledSubstancesPdfService::class)->render(new DateRangeFilter());

    expect($pdf)->toStartWith('%PDF');
});
