<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/*
 * ITM-2610-051 step 5, the left-over fix from steps 3-4: `%`, `_` and `\` typed into ANY loan search box are literal
 * characters. LoanCounterAccessTest already covers the counter, "my loans" and the branch list; LoanReportTest covers
 * the two report boxes. This file covers the rest: the register (list + "find an item" on the register form), the
 * availability page, and the request form (asset box + "on behalf of" person box).
 */

beforeEach(function () {
    Mail::fake();
});

/** Terms that, used as a wildcard, would match every row here (no name / code / tag in this file contains them). */
const LOAN_WILD_TERMS = ['%', '%%', '___', '\\', '%อุปกรณ์', 'อุป%ทดสอบ', 'อุปกรณ์ให้ยืม_ดสอบ'];

test('the register list, the "find an item" box of the register form and the availability page treat % _ \\ literally', function () {
    [$lab, $pool] = loanBranch();
    $unit = loanExtraAsset($lab, 1, null, 'UNIT');
    $manager = navUser('auditor', ['lab_id' => $lab->id, 'person_type' => 'STAFF']);
    $spare = makeCountItem();     // an item with no asset yet, for the register form's item search

    foreach (LOAN_WILD_TERMS as $term) {
        $this->actingAs($manager)->get(route('loans.assets.index', ['q' => $term]))->assertOk()
            ->assertViewHas('assets', fn ($page) => $page->total() === 0);
        $this->actingAs($manager)->get(route('loans.assets.create', ['q' => $term]))->assertOk()
            ->assertViewHas('items', fn ($items) => $items->isEmpty());
        $this->actingAs($manager)->get(route('loans.available', ['q' => $term]))->assertOk()
            ->assertViewHas('assets', fn ($page) => $page->total() === 0);
    }

    // Ordinary text still finds by part of a name, a code or a tag.
    $part = mb_substr('อุปกรณ์ให้ยืมทดสอบ', 3, 6);
    $this->actingAs($manager)->get(route('loans.assets.index', ['q' => $part]))->assertOk()->assertViewHas('assets', fn ($page) => $page->total() === 2);
    $this->actingAs($manager)->get(route('loans.assets.index', ['q' => substr($unit->asset_tag, 3)]))->assertOk()->assertViewHas('assets', fn ($page) => $page->total() === 1);
    $this->actingAs($manager)->get(route('loans.assets.create', ['q' => $spare->item_code]))->assertOk()->assertViewHas('items', fn ($items) => $items->pluck('id')->all() === [$spare->id]);
    $this->actingAs($manager)->get(route('loans.available', ['q' => $part]))->assertOk()->assertViewHas('assets', fn ($page) => $page->total() === 2);
    $this->actingAs($manager)->get(route('loans.available', ['q' => $pool->item->item_code]))->assertOk()->assertViewHas('assets', fn ($page) => $page->total() === 1);
});

test('the request form\'s asset box and its "on behalf of" person box treat % _ \\ literally', function () {
    [$lab, $pool] = loanBranch();
    $clerk = navUser('auditor', ['lab_id' => $lab->id, 'person_type' => 'STAFF']);
    $borrower = staffUser(['lab_id' => $lab->id, 'full_name' => 'สมหญิง ค้นหาแทน', 'username' => 'somying.onbehalf']);
    expect($clerk->can('createOnBehalf', \App\Models\Loan::class))->toBeTrue();

    foreach (LOAN_WILD_TERMS as $term) {
        $this->actingAs($clerk)->get(route('loans.create', ['q' => $term]))->assertOk()
            ->assertViewHas('assets', fn ($assets) => $assets->isEmpty());
    }
    // The person box: usernames here contain `_` (user_...) and `.`, so a single `_` is not a "matches nothing" term.
    foreach (['%', '%%', '__', '\\', 'สม%', '%แทน', 'somying_onbehalf'] as $term) {
        $this->actingAs($clerk)->get(route('loans.create', ['bq' => $term]))->assertOk()
            ->assertViewHas('candidates', fn ($people) => $people->isEmpty());
    }

    // Ordinary text still finds.
    $this->actingAs($clerk)->get(route('loans.create', ['q' => mb_substr('อุปกรณ์ให้ยืมทดสอบ', 3, 6)]))->assertOk()
        ->assertViewHas('assets', fn ($assets) => $assets->pluck('id')->all() === [$pool->id]);
    $this->actingAs($clerk)->get(route('loans.create', ['bq' => 'ค้นหาแ']))->assertOk()
        ->assertViewHas('candidates', fn ($people) => $people->pluck('id')->all() === [$borrower->id]);
    $this->actingAs($clerk)->get(route('loans.create', ['bq' => 'ying.onb']))->assertOk()
        ->assertViewHas('candidates', fn ($people) => $people->pluck('id')->all() === [$borrower->id]);
});

test('step 5 fix, the other side: downloading a file of anything other than a loan incident through /attachments/... writes no loan audit row', function () {
    \Illuminate\Support\Facades\Storage::fake('attachments');
    $item = makeItem();
    $manager = navUser('auditor');
    $attachment = \App\Models\Attachment::create([
        'owner_type' => $item->getMorphClass(), 'owner_id' => $item->id, 'doc_type' => 'SDS', 'original_name' => 'sds.pdf',
        'stored_name' => 'x-'.bin2hex(random_bytes(4)).'.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10,
        'sha256' => str_repeat('a', 64), 'version' => 1, 'uploaded_by' => $manager->id,
    ]);
    \Illuminate\Support\Facades\Storage::disk('attachments')->put($attachment->stored_name, 'PDF');

    $this->actingAs($manager)->get(route('attachments.download', $attachment))->assertOk();

    expect(\App\Models\AuditLog::where('action', 'LOAN_INCIDENT_FILE_DOWNLOAD')->count())->toBe(0);
});
