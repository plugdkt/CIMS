<?php

use App\Domain\Attachments\Contracts\VirusScanner;
use App\Domain\Loan\Exceptions\LoanDomainException;
use App\Domain\Loan\Services\LoanApprovalService;
use App\Domain\Loan\Services\LoanCirculationService;
use App\Domain\Loan\Services\LoanIncidentService;
use App\Domain\Loan\Services\LoanNotifier;
use App\Mail\NotificationMail;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Lab;
use App\Models\Loan;
use App\Models\LoanAsset;
use App\Models\LoanIncident;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 * ITM-2610-051 step 4 (WP-4.6): WHO reaches the damage / loss cases, their files, and what leaks to whom.
 *
 * The rules as the code has them (they win over the first spec; the ones still waiting for the CEO are in tests named
 * [CONFIRM], kept apart so that only those flip):
 *  - recording an incident = taking the return = the counter of the loan's branch (LoanCounterAccessTest);
 *  - recording its RESULT = an active holder of `loan.asset_approve` of the loan's own branch who is NOT the recorder,
 *    NOT the borrower and NOT the person who typed the loan in. [CONFIRM] An ADMIN-only account cannot (B6 said ADMIN may);
 *  - seeing the incident page and its files = the branch's staff (counter, approvers, managers) and ADMIN (every branch),
 *    NEVER the borrower or the on-behalf creator, whatever else they are [CONFIRM for the on-behalf creator];
 *  - attaching a file = the counter of the branch or whoever may record the result, only while PENDING;
 *  - `value_snapshot` is shown to holders of `loan.asset_manage` only; the borrower's document page shows kind /
 *    quantity / state and nothing else; notices carry the document number and a date only.
 *
 * What an incident / a result WRITES is in LoanIncidentTest.
 */

beforeEach(function () {
    Mail::fake();
    Storage::fake('attachments');
    incidentScanner(clean: true);
});

function incidentScanner(bool $clean = true): void
{
    app()->instance(VirusScanner::class, new class ($clean) implements VirusScanner {
        public function __construct(private readonly bool $clean)
        {
        }

        public function isClean(string $absolutePath): bool
        {
            return $this->clean;
        }
    });
}

const REPAIR_OK = ['resolution' => 'REPAIRED', 'condition_after' => 'GOOD', 'resolution_note' => 'ซ่อมแล้วใช้งานได้ตามปกติ'];

/**
 * A branch, a STAFF borrower with 3 pieces out, and one PENDING incident (1 piece) recorded by a scientist at the counter.
 *
 * @return array{\App\Models\Lab, \App\Models\LoanAsset, \App\Models\User, \App\Models\User, \App\Models\Loan, \App\Models\LoanIncident}
 */
function incidentCase(string $kind = 'DAMAGED', array $borrowerAttrs = []): array
{
    [$lab, $asset] = loanBranch();
    $borrower = staffUser(array_merge(['lab_id' => $lab->id], $borrowerAttrs));
    $recorder = scientistUser(['lab_id' => $lab->id]);
    $loan = handedOutLoan($borrower, $asset, 3);
    $incident = recordLoanIncident($loan, $recorder, $kind, 1);

    return [$lab, $asset, $borrower, $recorder, $loan, $incident];
}

/** A real stored file of the incident (row + bytes on the faked disk). */
function storedIncidentFile(LoanIncident $incident, User $uploader, string $docType = 'LOAN_DOC', string $name = 'memo.pdf', string $bytes = 'FILE-BYTES'): Attachment
{
    $attachment = attachLoanIncidentFile($incident, $uploader, $docType, $name);
    Storage::disk('attachments')->put($attachment->stored_name, $bytes);

    return $attachment;
}

function incidentUpload($test, User $user, LoanIncident $incident, string $docType, UploadedFile $file)
{
    return $test->actingAs($user)->post(route('loans.incidents.files.store', $incident), ['doc_type' => $docType, 'file' => $file]);
}

function incidentViewer(string $kind, Lab $lab, Lab $other): User
{
    return match ($kind) {
        'scientist of the branch' => scientistUser(['lab_id' => $lab->id]),
        'AUDITOR of the branch' => auditorUser(['lab_id' => $lab->id]),
        'LAB_MANAGER of the branch' => labManagerUser(['lab_id' => $lab->id]),
        'ADMIN + SCIENTIST of the branch' => navUser('admin+scientist', ['lab_id' => $lab->id]),
        'ADMIN + AUDITOR of the branch' => navUser('admin+auditor', ['lab_id' => $lab->id]),
        'STAFF + SCIENTIST of the branch' => navUser('staff+scientist', ['lab_id' => $lab->id]),
        'ADMIN alone, same branch' => adminUser(['lab_id' => $lab->id]),
        'ADMIN alone, another branch' => adminUser(['lab_id' => $other->id]),
        'ADMIN alone, no branch' => adminUser(['lab_id' => null]),
        'ADMIN + SCIENTIST of another branch' => navUser('admin+scientist', ['lab_id' => $other->id]),
        'ADMIN + AUDITOR with no branch' => navUser('admin+auditor', ['lab_id' => null]),
        'scientist of another branch' => scientistUser(['lab_id' => $other->id]),
        'AUDITOR of another branch' => auditorUser(['lab_id' => $other->id]),
        'LAB_MANAGER of another branch' => labManagerUser(['lab_id' => $other->id]),
        'scientist with no branch' => scientistUser(['lab_id' => null]),
        'LAB_MANAGER with no branch' => labManagerUser(['lab_id' => null]),
        'STAFF of the same branch' => staffUser(['lab_id' => $lab->id]),
        'ADVISOR of the same branch' => navUser('advisor', ['lab_id' => $lab->id]),
        'STUDENT of the same branch' => studentUser(['lab_id' => $lab->id]),
    };
}

/**
 * One person against every incident route and ability.
 *
 * @param  array{list: bool, sees: bool, acts: bool}  $may  list = opens the list page; sees = the incident page and its files;
 *                                                         acts = records the result and attaches files
 */
function assertIncidentAccess($test, string $kind, array $may): void
{
    [$lab, $asset, , $recorder, $loan, $incident] = incidentCase();
    $file = storedIncidentFile($incident, $recorder, 'PHOTO', 'photo.png');
    $user = incidentViewer($kind, $lab, makeLab());
    $before = loanIncidentState($loan);

    // The list.
    $list = $test->actingAs($user)->get(route('loans.incidents.index'));
    $list->assertStatus($may['list'] ? 200 : 403);
    expect($user->can('browseIncidents', LoanIncident::class))->toBe($may['list']);
    if ($may['list']) {
        $may['sees'] ? $list->assertSee($loan->doc_no) : $list->assertDontSee($loan->doc_no);
    }

    // The incident page and its files, by both download routes.
    $see = $may['sees'] ? 200 : 403;
    $test->actingAs($user)->get(route('loans.incidents.show', $incident))->assertStatus($see);
    $test->actingAs($user)->get(route('loans.incidents.files.show', ['loan_incident' => $incident, 'attachment' => $file]))->assertStatus($see);
    $test->actingAs($user)->get(route('attachments.download', $file))->assertStatus($see);
    expect($user->can('view', $incident))->toBe($may['sees']);
    // Step 5: both routes leave a trace — one row per route, told apart by `via` (the generic route says so; the
    // incident's own route has no `via`). A refused request leaves none.
    $downloads = loanIncidentAudit('LOAN_INCIDENT_FILE_DOWNLOAD', $incident)->where('user_id', $user->id);
    expect($downloads->filter(fn ($row) => ! isset($row->new_value['via']))->count())->toBe($may['sees'] ? 1 : 0);
    expect($downloads->filter(fn ($row) => ($row->new_value['via'] ?? null) === 'attachments.download')->count())->toBe($may['sees'] ? 1 : 0);
    expect($downloads->count())->toBe($may['sees'] ? 2 : 0);
    foreach ($downloads as $row) {
        expect($row->new_value['attachment'])->toBe($file->ulid)->and($row->new_value['doc_type'])->toBe('PHOTO');
    }

    // Attaching a file.
    expect($user->can('attachFile', $incident))->toBe($may['acts']);
    $upload = incidentUpload($test, $user, $incident, 'PHOTO', UploadedFile::fake()->create('more.jpg', 20, 'image/jpeg'));
    $files = Attachment::where('owner_type', 'LoanIncident')->where('owner_id', $incident->id)->count();
    if ($may['acts']) {
        $upload->assertSessionHasNoErrors()->assertRedirect();
        expect($files)->toBe(2);
    } else {
        $upload->assertForbidden();
        expect($files)->toBe(1);
    }

    // Recording the result: the page, the service and the ledger agree.
    expect($user->can('resolveIncident', $incident))->toBe($may['acts']);
    if ($may['acts']) {
        loanResolvePost($test, $user, $incident, REPAIR_OK)->assertRedirect(route('loans.incidents.show', $incident))->assertSessionHasNoErrors();
        expect($incident->fresh()->resolution)->toBe('REPAIRED')->and($incident->fresh()->resolved_by)->toBe($user->id);
        expect(loanIncidentAudit('LOAN_INCIDENT_RESOLVE', $incident)[0]->new_value['resolver_is_admin'])->toBe($user->hasRole('ADMIN'));

        return;
    }

    loanResolvePost($test, $user, $incident, REPAIR_OK)->assertForbidden();
    expect(fn () => app(LoanIncidentService::class)->resolve($user->fresh(), $incident, REPAIR_OK))
        ->toThrow(LoanDomainException::class, __('loans.error.resolver_not_eligible'));
    $incident->setRelation('line', $loan->lines()->firstOrFail());
    expect(fn () => loanService()->repairDone($user->fresh(), $asset->fresh(), $loan->fresh(), $incident, 'GOOD', REPAIR_OK['resolution_note']))
        ->toThrow(LoanDomainException::class, __('loans.error.resolver_not_eligible'));
    expect(fn () => loanService()->writeOff($user->fresh(), $asset->fresh(), $loan->fresh(), $incident, 'อว 7301/0123', REPAIR_OK['resolution_note']))
        ->toThrow(LoanDomainException::class, __('loans.error.resolver_not_eligible'));

    expect(loanIncidentState($loan))->toBe($before);
    expect($incident->fresh()->isPending())->toBeTrue();
}

/** A loan typed in by `$clerk` for `$borrower`, approved and handed out by other people of the branch (deactivated again). */
function onBehalfOutLoan(User $borrower, User $clerk, LoanAsset $asset, int $qty = 2): Loan
{
    $loan = submittedLoan($borrower, $asset, $qty, $clerk);
    $approver = scientistUser(['lab_id' => $loan->lab_id]);
    $loan = app(LoanApprovalService::class)->managerDecide($loan, $approver->fresh(), 'APPROVE')->fresh();
    $out = app(LoanCirculationService::class)->handOut($approver->fresh(), $loan, loanConditions($loan))->fresh();
    $approver->update(['is_active' => false]);

    return $out;
}

/** Every text of the notices (in the system and by email) sent so far, joined. */
function incidentNoticeText(): string
{
    $mails = Mail::queued(NotificationMail::class)->merge(Mail::sent(NotificationMail::class));

    return Notification::all()->map(fn ($n) => $n->title.' '.$n->body.' '.$n->link_url)->implode(' ')
        .' '.$mails->map(fn (NotificationMail $m) => $m->title.' '.$m->body.' '.$m->linkUrl)->implode(' ');
}

// --- every role against every route ---------------------------------------------------------------------------------

test('the staff of the loan\'s own branch see the incident, attach files and record its result (anyone but the recorder / the parties)', function (string $kind) {
    assertIncidentAccess($this, $kind, ['list' => true, 'sees' => true, 'acts' => true]);
})->with([
    'scientist of the branch', 'AUDITOR of the branch', 'LAB_MANAGER of the branch',
    'ADMIN + SCIENTIST of the branch', 'ADMIN + AUDITOR of the branch', 'STAFF + SCIENTIST of the branch',
]);

test('[CONFIRM] an ADMIN-only account reads every branch\'s incidents and files but cannot record a result or attach a file — also when ADMIN holds a staff role of ANOTHER branch', function (string $kind) {
    assertIncidentAccess($this, $kind, ['list' => true, 'sees' => true, 'acts' => false]);
})->with([
    'ADMIN alone, same branch', 'ADMIN alone, another branch', 'ADMIN alone, no branch',
    'ADMIN + SCIENTIST of another branch', 'ADMIN + AUDITOR with no branch',
]);

test('IDOR: the staff of ANOTHER branch open their own list (without this incident) and get 403 on the incident, its files, the upload and the result', function (string $kind) {
    assertIncidentAccess($this, $kind, ['list' => true, 'sees' => false, 'acts' => false]);
})->with(['scientist of another branch', 'AUDITOR of another branch', 'LAB_MANAGER of another branch']);

test('staff without a branch and people who do not work the loans reach nothing', function (string $kind) {
    assertIncidentAccess($this, $kind, ['list' => false, 'sees' => false, 'acts' => false]);
})->with([
    'scientist with no branch', 'LAB_MANAGER with no branch',
    'STAFF of the same branch', 'ADVISOR of the same branch', 'STUDENT of the same branch',
]);

test('a guest is sent to the login page by every incident route', function () {
    [, , , $recorder, , $incident] = incidentCase();
    $file = storedIncidentFile($incident, $recorder);

    $this->get(route('loans.incidents.index'))->assertRedirect(route('login'));
    $this->get(route('loans.incidents.show', $incident))->assertRedirect(route('login'));
    $this->get(route('loans.incidents.files.show', ['loan_incident' => $incident, 'attachment' => $file]))->assertRedirect(route('login'));
    $this->get(route('attachments.download', $file))->assertRedirect(route('login'));
    $this->post(route('loans.incidents.resolve', $incident), REPAIR_OK)->assertRedirect(route('login'));
    $this->post(route('loans.incidents.files.store', $incident), ['doc_type' => 'PHOTO'])->assertRedirect(route('login'));

    expect($incident->fresh()->isPending())->toBeTrue();
    expect(Attachment::where('owner_type', 'LoanIncident')->count())->toBe(1);
});

test('an account that was deactivated loses every incident ability, whatever its role', function (string $kind) {
    [$lab, , , , $loan, $incident] = incidentCase();
    $user = incidentViewer($kind, $lab, makeLab());
    $user->update(['is_active' => false]);
    $user = $user->fresh();

    expect($user->can('view', $incident))->toBeFalse();
    expect($user->can('attachFile', $incident))->toBeFalse();
    expect($user->can('resolveIncident', $incident))->toBeFalse();
    expect(fn () => app(LoanIncidentService::class)->resolve($user, $incident, REPAIR_OK))->toThrow(LoanDomainException::class, __('loans.error.resolver_not_eligible'));
    expect($incident->fresh()->isPending())->toBeTrue()->and($loan->fresh()->status)->toBe('PARTIALLY_RETURNED');
})->with(['scientist of the branch', 'AUDITOR of the branch', 'ADMIN alone, same branch']);

// --- separation of duties on the result -------------------------------------------------------------------------------

test('the person who recorded the incident cannot record its result — page, service and ledger — but still sees it and may attach files', function (string $kind) {
    [$lab, $asset] = loanBranch();
    $borrower = staffUser(['lab_id' => $lab->id]);
    $recorder = navUser($kind, ['lab_id' => $lab->id]);
    $loan = handedOutLoan($borrower, $asset, 2);
    $incident = recordLoanIncident($loan, $recorder, 'LOST', 1);
    attachLoanIncidentFile($incident, $recorder);
    $before = loanIncidentState($loan);
    $message = __('loans.error.resolver_is_recorder');

    expect($recorder->can('resolveIncident', $incident))->toBeFalse();
    foreach ([loanResolveData('WAIVED'), loanResolveData('COMPENSATED'), loanResolveData('REPLACED', ['receiver_id' => scientistUser(['lab_id' => $lab->id])->id])] as $payload) {
        loanResolvePost($this, $recorder, $incident, $payload)->assertForbidden();
        expect(fn () => app(LoanIncidentService::class)->resolve($recorder->fresh(), $incident, $payload))->toThrow(LoanDomainException::class, $message);
    }
    $incident->setRelation('line', $loan->lines()->firstOrFail());
    expect(fn () => loanService()->writeOff($recorder->fresh(), $asset->fresh(), $loan->fresh(), $incident, 'อว 7301/0123', 'ผู้บริหารพิจารณาตามบันทึกข้อความแล้ว'))->toThrow(LoanDomainException::class, $message);
    expect(loanIncidentState($loan))->toBe($before);

    // They keep the page (with a sentence saying why there is no form) and the upload.
    expect($recorder->can('view', $incident))->toBeTrue()->and($recorder->can('attachFile', $incident))->toBeTrue();
    $this->actingAs($recorder)->get(route('loans.incidents.show', $incident))->assertOk()
        ->assertSee($message)->assertDontSee(route('loans.incidents.resolve', $incident), false)
        ->assertSee(route('loans.incidents.files.store', $incident), false);
    $this->actingAs($recorder)->get(route('loans.incidents.index'))->assertOk()->assertSee($loan->doc_no);
})->with(['scientist', 'auditor', 'lab_manager', 'admin+scientist']);

test('the borrower never records the result of an incident on their own loan, even as an approver of the branch — and never opens it or its files', function (string $kind) {
    [$lab, $asset] = loanBranch();
    $borrower = navUser($kind, ['lab_id' => $lab->id, 'person_type' => 'STAFF']);
    $recorder = scientistUser(['lab_id' => $lab->id]);
    $loan = handedOutLoan($borrower, $asset, 2);
    $incident = recordLoanIncident($loan, $recorder, 'DAMAGED', 1);
    $file = storedIncidentFile($incident, $recorder);
    $before = loanIncidentState($loan);

    // The result.
    expect($borrower->can('resolveIncident', $incident))->toBeFalse();
    loanResolvePost($this, $borrower, $incident, REPAIR_OK)->assertForbidden();
    expect(fn () => app(LoanIncidentService::class)->resolve($borrower->fresh(), $incident, REPAIR_OK))
        ->toThrow(LoanDomainException::class, __($borrower->hasPermission('loan.asset_approve') ? 'loans.error.resolver_is_party' : 'loans.error.resolver_not_eligible'));
    expect(loanIncidentState($loan))->toBe($before);

    // The page and the files, by both routes: 403 whatever role they hold (ADMIN included).
    expect($borrower->can('view', $incident))->toBeFalse();
    $this->actingAs($borrower)->get(route('loans.incidents.show', $incident))->assertForbidden();
    $this->actingAs($borrower)->get(route('loans.incidents.files.show', ['loan_incident' => $incident, 'attachment' => $file]))->assertForbidden();
    $this->actingAs($borrower)->get(route('attachments.download', $file))->assertForbidden();
    expect(AuditLog::where('action', 'LOAN_INCIDENT_FILE_DOWNLOAD')->count())->toBe(0);

    // Their own case is not in the list they may otherwise open as staff.
    if ($borrower->can('browseIncidents', LoanIncident::class)) {
        $this->actingAs($borrower)->get(route('loans.incidents.index'))->assertOk()->assertDontSee($loan->doc_no);
    } else {
        $this->actingAs($borrower)->get(route('loans.incidents.index'))->assertForbidden();
    }

    // Still closed to them after the result is recorded by somebody else.
    app(LoanIncidentService::class)->resolve(auditorUser(['lab_id' => $lab->id]), $incident, REPAIR_OK);
    $this->actingAs($borrower)->get(route('loans.incidents.show', $incident))->assertForbidden();
    $this->actingAs($borrower)->get(route('attachments.download', $file))->assertForbidden();
})->with(['staff', 'scientist', 'auditor', 'lab_manager', 'admin', 'admin+scientist', 'staff+scientist']);

test('[CONFIRM] the person who typed the loan in for the borrower is treated like the borrower: no result, no incident page, no files', function (string $kind) {
    [$lab, $asset] = loanBranch();
    $borrower = staffUser(['lab_id' => $lab->id]);
    $clerk = navUser($kind, ['lab_id' => $lab->id]);
    $recorder = scientistUser(['lab_id' => $lab->id]);
    $loan = onBehalfOutLoan($borrower, $clerk, $asset, 2);
    expect($loan->created_by)->toBe($clerk->id)->and($loan->borrower_id)->toBe($borrower->id);
    $incident = recordLoanIncident($loan, $recorder, 'DAMAGED', 1);
    $file = storedIncidentFile($incident, $recorder);
    $before = loanIncidentState($loan);

    expect($clerk->can('resolveIncident', $incident))->toBeFalse()->and($clerk->can('view', $incident))->toBeFalse();
    loanResolvePost($this, $clerk, $incident, REPAIR_OK)->assertForbidden();
    expect(fn () => app(LoanIncidentService::class)->resolve($clerk->fresh(), $incident, REPAIR_OK))->toThrow(LoanDomainException::class, __('loans.error.resolver_is_party'));
    expect(loanIncidentState($loan))->toBe($before);

    $this->actingAs($clerk)->get(route('loans.incidents.show', $incident))->assertForbidden();
    $this->actingAs($clerk)->get(route('loans.incidents.files.show', ['loan_incident' => $incident, 'attachment' => $file]))->assertForbidden();
    $this->actingAs($clerk)->get(route('attachments.download', $file))->assertForbidden();
    $this->actingAs($clerk)->get(route('loans.incidents.index'))->assertOk()->assertDontSee($loan->doc_no);

    // The borrower of that loan is shut out as well, and a colleague who is neither records the result.
    $this->actingAs($borrower)->get(route('loans.incidents.show', $incident))->assertForbidden();
    $colleague = auditorUser(['lab_id' => $lab->id]);
    loanResolvePost($this, $colleague, $incident, REPAIR_OK)->assertSessionHasNoErrors();
    expect($incident->fresh()->resolved_by)->toBe($colleague->id);
})->with(['scientist', 'auditor', 'lab_manager']);

test('[CONFIRM] a counter person who is the borrower / typed the loan in may still HAND IN a file for the incident they recorded, without ever seeing one', function (string $role) {
    [$lab, $asset] = loanBranch();
    if ($role === 'borrower') {
        $me = scientistUser(['lab_id' => $lab->id, 'person_type' => 'STAFF', 'profile_completed_at' => now()]);
        $loan = handedOutLoan($me, $asset, 2);
    } else {
        $me = scientistUser(['lab_id' => $lab->id]);
        $loan = onBehalfOutLoan(staffUser(['lab_id' => $lab->id]), $me, $asset, 2);
    }
    // They take their own loan back at the counter (allowed and audited as SELF for the borrower, step 3).
    $incident = recordLoanIncident($loan, $me, 'DAMAGED', 1);
    expect($incident->recorded_by)->toBe($me->id);
    expect(loanIncidentAudit('LOAN_INCIDENT_RECORD', $incident)[0]->new_value['recorder_is_borrower'])->toBe($role === 'borrower');

    expect($me->can('attachFile', $incident))->toBeTrue()->and($me->can('view', $incident))->toBeFalse();
    incidentUpload($this, $me, $incident, 'PHOTO', UploadedFile::fake()->create('photo.png', 30, 'image/png'))->assertSessionHasNoErrors()->assertRedirect();
    $file = Attachment::where('owner_type', 'LoanIncident')->where('owner_id', $incident->id)->sole();

    // The document page offers them the upload form, not the link to the incident; the file itself stays closed.
    $this->actingAs($me)->get(route('loans.show', $loan))->assertOk()
        ->assertSee(route('loans.incidents.files.store', $incident), false)
        ->assertDontSee('href="'.route('loans.incidents.show', $incident).'"', false)
        ->assertDontSee($file->original_name);
    $this->actingAs($me)->get(route('loans.incidents.files.show', ['loan_incident' => $incident, 'attachment' => $file]))->assertForbidden();
    $this->actingAs($me)->get(route('attachments.download', $file))->assertForbidden();
    $this->actingAs($me)->get(route('loans.incidents.show', $incident))->assertForbidden();
})->with(['borrower', 'on-behalf creator']);

test('the result form takes the result fields only: who resolved, the value, the quantity, the kind and the recorder sent along are ignored', function () {
    [$lab, $asset, , $recorder, $loan, $incident] = incidentCase();
    DB::table('loan_assets')->where('id', $asset->id)->update(['replacement_value' => '700.00']);
    $resolver = auditorUser(['lab_id' => $lab->id]);
    $someone = scientistUser(['lab_id' => $lab->id]);

    loanResolvePost($this, $resolver, $incident, array_merge(REPAIR_OK, [
        'resolved_by' => $someone->id, 'recorded_by' => $someone->id, 'value_snapshot' => '1.00', 'qty' => '3', 'kind' => 'LOST',
        'loan_id' => 999, 'loan_line_id' => 999, 'description' => 'แก้คำอธิบายผ่านฟอร์มบันทึกผล', 'resolved_at' => '2000-01-01 00:00:00', 'ulid' => 'X',
    ]))->assertSessionHasNoErrors();

    $done = $incident->fresh();
    expect([$done->resolved_by, $done->recorded_by, $done->value_snapshot, $done->qty, $done->kind, $done->loan_id, $done->description, $done->ulid])
        ->toBe([$resolver->id, $recorder->id, '700.00', '1.000000', 'DAMAGED', $loan->id, 'หน้าจอแตกร้าวใช้งานต่อไม่ได้', $incident->ulid]);
    expect($done->resolved_at->isToday())->toBeTrue();
});

// --- the pages --------------------------------------------------------------------------------------------------------

test('the incident page: a resolver gets the form with the right choices and the branch\'s counter people as receivers; others get a sentence instead', function () {
    [$lab, , $borrower, $recorder, $loan, $damaged] = incidentCase('DAMAGED');
    $lost = recordLoanIncident($loan, $recorder, 'LOST', 1);
    $resolver = auditorUser(['lab_id' => $lab->id, 'full_name' => 'ผู้ดูแลคลังผู้บันทึกผล']);
    $counter = scientistUser(['lab_id' => $lab->id, 'full_name' => 'นักวิทย์ผู้รับของทดแทน']);
    $inactive = scientistUser(['lab_id' => $lab->id, 'is_active' => false]);
    $elsewhere = scientistUser(['lab_id' => makeLab()->id]);
    $admin = adminUser(['lab_id' => $lab->id]);

    $page = $this->actingAs($resolver)->get(route('loans.incidents.show', $damaged))->assertOk();
    $page->assertSee($loan->doc_no)->assertSee($damaged->description)->assertSee($borrower->full_name)->assertSee(__('loans.section_resolve'));
    $xp = htmlXPath($page->getContent());
    expect($xp->query('//form[@action="'.route('loans.incidents.resolve', $damaged).'"][@method="POST"]')->length)->toBe(1);
    $options = fn (DOMXPath $x, string $name) => collect(iterator_to_array($x->query('//select[@name="'.$name.'"]/option')))->map(fn ($o) => $o->getAttribute('value'))->filter()->values()->all();
    expect($options($xp, 'resolution'))->toBe(['REPAIRED', 'COMPENSATED', 'WAIVED', 'REPLACED']);
    expect($options($xp, 'disposition'))->toBe(['RETURN_TO_SERVICE', 'WRITE_OFF']);
    expect($options($xp, 'condition_after'))->toBe(['GOOD', 'FAIR']);
    // Receivers: the counter people of THIS branch, not the viewer, not the borrower, nobody inactive or from elsewhere, no ADMIN-only account.
    $receivers = array_map('intval', $options($xp, 'receiver_id'));
    sort($receivers);
    $expected = [$recorder->id, $counter->id];
    sort($expected);
    expect($receivers)->toBe($expected);
    expect($receivers)->not->toContain($resolver->id, $borrower->id, $inactive->id, $elsewhere->id, $admin->id);

    // A LOST piece: no "repaired", no "back in service", no condition.
    $xp = htmlXPath($this->actingAs($resolver)->get(route('loans.incidents.show', $lost))->assertOk()->getContent());
    expect($options($xp, 'resolution'))->toBe(['COMPENSATED', 'WAIVED', 'REPLACED']);
    expect($xp->query('//select[@name="disposition"]')->length)->toBe(0)->and($xp->query('//select[@name="condition_after"]')->length)->toBe(0);

    // The ADMIN-only account reads the page and is told why it has no form.
    $this->actingAs($admin)->get(route('loans.incidents.show', $damaged))->assertOk()
        ->assertSee(__('loans.error.resolver_not_eligible'))->assertDontSee(route('loans.incidents.resolve', $damaged), false)
        ->assertDontSee(route('loans.incidents.files.store', $damaged), false);

    // Once decided: no form and no upload for anybody, the result and "final" note are shown.
    loanResolvePost($this, $resolver, $damaged, REPAIR_OK)->assertSessionHasNoErrors();
    foreach ([$resolver, $counter, $recorder, $admin] as $viewer) {
        $this->actingAs($viewer)->get(route('loans.incidents.show', $damaged))->assertOk()
            ->assertSee(__('loans.incident_final_note'))->assertSee(__('loans.incident_resolution_repaired'))->assertSee('ผู้ดูแลคลังผู้บันทึกผล')
            ->assertDontSee(route('loans.incidents.resolve', $damaged), false)->assertDontSee(route('loans.incidents.files.store', $damaged), false);
    }
});

test('the list shows the branch\'s waiting and decided incidents; ADMIN sees every branch; the counter page links to it with the branch\'s own count', function () {
    [$lab, , , $recorder, $loan, $incident] = incidentCase();
    [$otherLab, $otherAsset] = loanBranch();
    $otherRecorder = scientistUser(['lab_id' => $otherLab->id]);
    $otherLoan = handedOutLoan(staffUser(['lab_id' => $otherLab->id]), $otherAsset, 2);
    recordLoanIncident($otherLoan, $otherRecorder, 'LOST', 1);
    recordLoanIncident($otherLoan, $otherRecorder, 'DAMAGED', 1);
    $resolver = auditorUser(['lab_id' => $lab->id]);
    $admin = adminUser();

    $this->actingAs($resolver)->get(route('loans.incidents.index'))->assertOk()
        ->assertSee($loan->doc_no)->assertDontSee($otherLoan->doc_no)
        ->assertViewHas('pending', fn ($rows) => $rows->pluck('id')->all() === [$incident->id])
        ->assertViewHas('resolved', fn ($rows) => $rows->total() === 0);
    $this->actingAs($admin)->get(route('loans.incidents.index'))->assertOk()->assertSee($loan->doc_no)->assertSee($otherLoan->doc_no)
        ->assertViewHas('pending', fn ($rows) => $rows->count() === 3);

    // The counter page: a link with how many wait in THIS branch (a number, no names).
    $this->actingAs($recorder)->get(route('loans.desk'))->assertOk()->assertSee(__('loans.desk_incidents_link', ['count' => 1]))->assertSee(route('loans.incidents.index'), false);
    $this->actingAs($otherRecorder)->get(route('loans.desk'))->assertOk()->assertSee(__('loans.desk_incidents_link', ['count' => 2]));

    app(LoanIncidentService::class)->resolve($resolver->fresh(), $incident, REPAIR_OK);
    $this->actingAs($resolver)->get(route('loans.incidents.index'))->assertOk()
        ->assertViewHas('pending', fn ($rows) => $rows->isEmpty())
        ->assertViewHas('resolved', fn ($rows) => collect($rows->items())->pluck('id')->all() === [$incident->id]);
    $this->actingAs($recorder)->get(route('loans.desk'))->assertOk()->assertSee(__('loans.desk_incidents_link', ['count' => 0]));
});

// --- 404: addressing ------------------------------------------------------------------------------------------------

test('incidents are addressed by ULID only, and a file must belong to THIS incident: anything else answers 404 (and is not logged as a download)', function () {
    [$lab, , , $recorder, $loan, $incident] = incidentCase();
    $resolver = auditorUser(['lab_id' => $lab->id]);
    $own = storedIncidentFile($incident, $recorder, 'LOAN_DOC');
    $sibling = recordLoanIncident($loan, $recorder, 'LOST', 1);
    $siblingFile = storedIncidentFile($sibling, $recorder, 'LOAN_DOC', 'other-memo.pdf');
    $itemFile = Attachment::create([
        'owner_type' => 'Item', 'owner_id' => makeItem()->id, 'doc_type' => 'SDS', 'original_name' => 'sds.pdf', 'stored_name' => 'sds-file.pdf',
        'mime_type' => 'application/pdf', 'size_bytes' => 10, 'sha256' => str_repeat('a', 64), 'version' => 1, 'uploaded_by' => $recorder->id,
    ]);
    Storage::disk('attachments')->put('sds-file.pdf', 'SDS');
    // A file of this incident whose type is not one an incident carries (written by hand).
    $odd = storedIncidentFile($incident, $recorder, 'OTHER', 'odd.pdf');

    // The auto-increment id is never a key.
    $this->actingAs($resolver)->get('/loans/incidents/'.$incident->id)->assertNotFound();
    $this->actingAs($resolver)->post('/loans/incidents/'.$incident->id.'/resolve', REPAIR_OK)->assertNotFound();
    $this->actingAs($resolver)->get('/loans/incidents/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound();
    $this->actingAs($resolver)->get('/loans/incidents/'.$incident->ulid.'/files/'.$own->id)->assertNotFound();
    expect($incident->fresh()->isPending())->toBeTrue();

    // The viewer may open BOTH incidents, yet a file is only served under its own incident.
    $url = fn (LoanIncident $i, Attachment $a) => route('loans.incidents.files.show', ['loan_incident' => $i, 'attachment' => $a]);
    $this->actingAs($resolver)->get($url($incident, $siblingFile))->assertNotFound();
    $this->actingAs($resolver)->get($url($sibling, $own))->assertNotFound();
    $this->actingAs($resolver)->get($url($incident, $itemFile))->assertNotFound();
    $this->actingAs($resolver)->get($url($incident, $odd))->assertNotFound();
    expect(AuditLog::where('action', 'LOAN_INCIDENT_FILE_DOWNLOAD')->count())->toBe(0);

    // The right pairs are served, with the original name, and each download is logged.
    $ok = $this->actingAs($resolver)->get($url($incident, $own))->assertOk();
    expect($ok->headers->get('content-disposition'))->toContain('memo.pdf');
    $this->actingAs($resolver)->get($url($sibling, $siblingFile))->assertOk();
    $logged = AuditLog::where('action', 'LOAN_INCIDENT_FILE_DOWNLOAD')->orderBy('id')->get();
    expect($logged)->toHaveCount(2);
    expect([$logged[0]->user_id, $logged[0]->entity_id, $logged[0]->new_value])->toBe([$resolver->id, $incident->id, ['attachment' => $own->ulid, 'doc_type' => 'LOAN_DOC']]);

    // Somebody who may not see the incident gets 403 before the file is even looked at (no 404 / 200 difference to probe).
    $outsider = scientistUser(['lab_id' => makeLab()->id]);
    $this->actingAs($outsider)->get($url($incident, $own))->assertForbidden();
    $this->actingAs($outsider)->get($url($incident, $siblingFile))->assertForbidden();
});

// --- uploads ----------------------------------------------------------------------------------------------------------

test('a photo (JPG / PNG) and the outside letter (PDF / JPG / PNG) are stored under a random name, versioned per type, hashed and audited', function () {
    [$lab, , , $recorder, , $incident] = incidentCase();
    $resolver = auditorUser(['lab_id' => $lab->id]);

    incidentUpload($this, $recorder, $incident, 'PHOTO', UploadedFile::fake()->create('IMG 0001.JPG', 200, 'image/jpeg'))
        ->assertSessionHasNoErrors()->assertRedirect()->assertSessionHas('status', __('loans.incident_file_uploaded'));
    incidentUpload($this, $recorder, $incident, 'PHOTO', UploadedFile::fake()->create('side.png', 200, 'image/png'))->assertSessionHasNoErrors();
    incidentUpload($this, $resolver, $incident, 'LOAN_DOC', UploadedFile::fake()->create('บันทึกข้อความ.pdf', 300, 'application/pdf'))->assertSessionHasNoErrors();
    incidentUpload($this, $resolver, $incident, 'LOAN_DOC', UploadedFile::fake()->create('scan.jpeg', 300, 'image/jpeg'))->assertSessionHasNoErrors();

    $files = Attachment::where('owner_type', 'LoanIncident')->where('owner_id', $incident->id)->orderBy('id')->get();
    expect($files->map(fn ($f) => [$f->doc_type, $f->version, $f->original_name, $f->uploaded_by])->all())->toBe([
        ['PHOTO', 1, 'IMG 0001.JPG', $recorder->id],
        ['PHOTO', 2, 'side.png', $recorder->id],
        ['LOAN_DOC', 1, 'บันทึกข้อความ.pdf', $resolver->id],
        ['LOAN_DOC', 2, 'scan.jpeg', $resolver->id],
    ]);
    foreach ($files as $file) {
        // SEC-FU: never the client's name on disk; a UUID plus the extension.
        expect($file->stored_name)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(jpg|jpeg|png|pdf)$/i');
        expect($file->sha256)->toHaveLength(64)->and($file->ulid)->toHaveLength(26);
        Storage::disk('attachments')->assertExists($file->stored_name);
    }

    $audit = loanIncidentAudit('LOAN_INCIDENT_FILE_UPLOAD', $incident);
    expect($audit)->toHaveCount(4);
    expect([$audit[0]->user_id, $audit[0]->new_value])->toBe([$recorder->id, ['attachment' => $files[0]->ulid, 'doc_type' => 'PHOTO', 'sha256' => $files[0]->sha256]]);
    // The client's file name is not copied into the audit log.
    expect(json_encode($audit->pluck('new_value'), JSON_UNESCAPED_UNICODE))->not->toContain('บันทึกข้อความ');

    // The uploaded letter is what a write-off asks for.
    $lost = recordLoanIncident($incident->loan, $recorder, 'LOST', 1);
    loanResolvePost($this, $resolver, $lost, loanResolveData('WAIVED'))->assertSessionHasErrors(['incident' => __('loans.error.incident_doc_required')]);
    incidentUpload($this, $recorder, $lost, 'LOAN_DOC', UploadedFile::fake()->create('memo.pdf', 100, 'application/pdf'))->assertSessionHasNoErrors();
    loanResolvePost($this, $resolver, $lost, loanResolveData('WAIVED'))->assertSessionHasNoErrors();
    expect($lost->fresh()->disposition)->toBe('WRITE_OFF');
});

test('an upload of the wrong type, kind, size or content is refused and nothing is stored', function () {
    [, , , $recorder, , $incident] = incidentCase();
    $real = function (string $name, string $bytes): UploadedFile {
        // A genuine temp file: goes through the real finfo-based MIME check, like a live request (SEC-FU-02).
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    };

    $bad = [
        'a PDF as a photo' => ['PHOTO', UploadedFile::fake()->create('scan.pdf', 50, 'application/pdf'), 'file'],
        'a Word file as the letter' => ['LOAN_DOC', UploadedFile::fake()->create('memo.docx', 50, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'), 'file'],
        'an Excel file as the letter' => ['LOAN_DOC', UploadedFile::fake()->create('memo.xlsx', 50, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'), 'file'],
        'an executable' => ['PHOTO', UploadedFile::fake()->create('tool.exe', 50), 'file'],
        'an HTML page' => ['LOAN_DOC', UploadedFile::fake()->create('page.html', 5, 'text/html'), 'file'],
        'an SVG image' => ['PHOTO', UploadedFile::fake()->create('icon.svg', 5, 'image/svg+xml'), 'file'],
        'a script named .pdf' => ['LOAN_DOC', $real('shell.pdf', '<?php system($_GET["c"]); ?>'), 'file'],
        'a script named .png' => ['PHOTO', $real('shell.png', '<?php system($_GET["c"]); ?>'), 'file'],
        'a double extension' => ['PHOTO', $real('photo.png.php', '<?php echo 1; ?>'), 'file'],
        'over the size limit (10 MB)' => ['LOAN_DOC', UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf'), 'file'],
        'a type an incident does not carry: SDS' => ['SDS', UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), 'doc_type'],
        'a type an incident does not carry: INVOICE' => ['INVOICE', UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), 'doc_type'],
        'a type an incident does not carry: OTHER' => ['OTHER', UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), 'doc_type'],
        'no type' => ['', UploadedFile::fake()->create('a.png', 10, 'image/png'), 'doc_type'],
        'lower-case type' => ['photo', UploadedFile::fake()->create('a.png', 10, 'image/png'), 'doc_type'],
    ];
    foreach ($bad as $case => [$docType, $file, $errorKey]) {
        incidentUpload($this, $recorder, $incident, $docType, $file)->assertSessionHasErrors($errorKey);
        expect(Attachment::where('owner_type', 'LoanIncident')->count())->toBe(0, $case);
    }
    $this->actingAs($recorder)->post(route('loans.incidents.files.store', $incident), ['doc_type' => 'PHOTO'])->assertSessionHasErrors('file');
    $this->actingAs($recorder)->post(route('loans.incidents.files.store', $incident), ['doc_type' => 'PHOTO', 'file' => 'not-a-file'])->assertSessionHasErrors('file');

    // SEC-FU-04: an infected file is refused before anything is written.
    incidentScanner(clean: false);
    incidentUpload($this, $recorder, $incident, 'PHOTO', UploadedFile::fake()->create('photo.png', 30, 'image/png'))->assertSessionHasErrors('file');

    expect(Attachment::where('owner_type', 'LoanIncident')->count())->toBe(0);
    expect(Storage::disk('attachments')->allFiles())->toBe([]);
    expect(loanIncidentAudit('LOAN_INCIDENT_FILE_UPLOAD', $incident))->toHaveCount(0);
});

test('files can be attached only while the incident is PENDING; afterwards they stay readable for the staff and closed to the borrower', function () {
    [$lab, , $borrower, $recorder, , $incident] = incidentCase();
    $resolver = auditorUser(['lab_id' => $lab->id]);
    $manager = labManagerUser(['lab_id' => $lab->id]);
    $file = storedIncidentFile($incident, $recorder, 'PHOTO', 'photo.png');
    app(LoanIncidentService::class)->resolve($resolver->fresh(), $incident, REPAIR_OK);

    foreach ([$recorder, $resolver, $manager, adminUser()] as $user) {
        expect($user->can('attachFile', $incident->fresh()))->toBeFalse();
        incidentUpload($this, $user, $incident, 'PHOTO', UploadedFile::fake()->create('late.png', 30, 'image/png'))->assertForbidden();
    }
    expect(Attachment::where('owner_type', 'LoanIncident')->where('owner_id', $incident->id)->count())->toBe(1);

    foreach ([$recorder, $resolver, $manager] as $user) {
        $this->actingAs($user)->get(route('loans.incidents.files.show', ['loan_incident' => $incident, 'attachment' => $file]))->assertOk();
        $this->actingAs($user)->get(route('attachments.download', $file))->assertOk();
    }
    $this->actingAs($borrower)->get(route('loans.incidents.files.show', ['loan_incident' => $incident, 'attachment' => $file]))->assertForbidden();
    $this->actingAs($borrower)->get(route('attachments.download', $file))->assertForbidden();
});

// --- PDPA: what each side sees ----------------------------------------------------------------------------------------

test('PDPA: the borrower\'s document page shows that an incident exists (kind, quantity, state) and nothing of its description, reference, note, value or files', function (string $kind, array $payload) {
    [$lab, $asset] = loanBranch();
    DB::table('loan_assets')->where('id', $asset->id)->update(['replacement_value' => '4321.55']);
    $borrower = staffUser(['lab_id' => $lab->id]);
    $recorder = scientistUser(['lab_id' => $lab->id]);
    $resolver = auditorUser(['lab_id' => $lab->id]);
    $loan = handedOutLoan($borrower, $asset, 2);
    $incident = recordLoanIncident($loan, $recorder, $kind, 1, 1, 'ZZDESC รอยร้าวที่ฐานเครื่อง พบตอนรับคืน');
    $file = storedIncidentFile($incident, $recorder, 'LOAN_DOC', 'ZZFILE-memo.pdf');
    $secrets = ['ZZDESC', 'รอยร้าวที่ฐานเครื่อง', 'ZZREF', 'ZZNOTE', '4321.55', '4,321', 'ZZFILE', $file->ulid, $incident->ulid];

    $see = function (User $viewer) use ($loan) {
        return $this->actingAs($viewer)->get(route('loans.show', $loan))->assertOk();
    };

    // While it waits.
    $page = $see($borrower);
    $page->assertSee(__('loans.section_incidents'))
        ->assertSee(__('loans.incident_line', ['kind' => __('loans.incident_kind_'.strtolower($kind)), 'qty' => '1', 'status' => __('loans.incident_resolution_pending')]))
        ->assertSee(__('loans.incident_pending_loan_note'));
    foreach ($secrets as $secret) {
        $page->assertDontSee($secret);
    }
    $page->assertDontSee(route('loans.incidents.files.store', $incident), false)->assertDontSee('/loans/incidents/', false);

    // After the result.
    loanResolvePost($this, $resolver, $incident, array_merge($payload, ['external_ref' => 'ZZREF-2569/77', 'resolution_note' => 'ZZNOTE ผู้ยืมชดใช้ตามมูลค่าแล้ว']))->assertSessionHasNoErrors();
    expect($incident->fresh()->value_snapshot)->toBe('4321.55');
    $page = $see($borrower);
    $page->assertSee(__('loans.incident_line', ['kind' => __('loans.incident_kind_'.strtolower($kind)), 'qty' => '1', 'status' => __('loans.incident_resolution_'.strtolower($payload['resolution']))]));
    foreach ($secrets as $secret) {
        $page->assertDontSee($secret);
    }
    $page->assertDontSee('/loans/incidents/', false);
    // The movement history lists the incident's rows without their notes.
    $page->assertSee(__('loans.movement_'.($kind === 'LOST' ? 'mark_lost' : 'return_damaged')));

    // The same page for the branch's staff: the link to the incident and the notes of its movements.
    $staff = $see($recorder);
    $staff->assertSee(route('loans.incidents.show', $incident), false)->assertSee('ZZDESC')->assertSee('ZZNOTE');
    // The value is not on the document page for anybody.
    $staff->assertDontSee('4,321')->assertDontSee('4321.55');
    $see($resolver)->assertDontSee('4,321')->assertDontSee('4321.55');

    // The other pages the borrower can open say nothing either.
    $this->actingAs($borrower)->get(route('loans.mine'))->assertOk()->assertDontSee('ZZDESC')->assertDontSee('ZZREF')->assertDontSee('4,321');
    $this->get('/verify/'.$loan->ulid)->assertOk()->assertDontSee('ZZDESC')->assertDontSee('ZZREF')->assertDontSee('ZZNOTE')->assertDontSee('4,321');
})->with([
    'DAMAGED, compensated and written off' => ['DAMAGED', ['resolution' => 'COMPENSATED', 'disposition' => 'WRITE_OFF']],
    'LOST, waived' => ['LOST', ['resolution' => 'WAIVED']],
    'DAMAGED, compensated and kept' => ['DAMAGED', ['resolution' => 'COMPENSATED', 'disposition' => 'RETURN_TO_SERVICE', 'condition_after' => 'FAIR']],
]);

test('QA fix PDPA: the movement history of the document page hides WHO recorded / decided / took in the replacement of a case from the borrower and the on-behalf creator; the branch\'s staff still see them', function (string $owner) {
    [$lab, $asset] = loanBranch();
    $borrower = staffUser(['lab_id' => $lab->id]);
    $recorder = scientistUser(['lab_id' => $lab->id, 'full_name' => 'ZZRECORDER ผู้รับคืน']);
    $resolver = auditorUser(['lab_id' => $lab->id, 'full_name' => 'ZZRESOLVER ผู้บันทึกผล']);
    $receiver = scientistUser(['lab_id' => $lab->id, 'full_name' => 'ZZRECEIVER ผู้รับของทดแทน']);
    $clerk = scientistUser(['lab_id' => $lab->id, 'full_name' => 'ZZCLERK ผู้ทำคำขอแทน']);
    $loan = $owner === 'borrower' ? handedOutLoan($borrower, $asset, 2) : onBehalfOutLoan($borrower, $clerk, $asset, 2);
    $incident = recordLoanIncident($loan, $recorder, 'LOST', 1);
    attachLoanIncidentFile($incident, $recorder);
    loanResolvePost($this, $resolver, $incident, loanResolveData('REPLACED', ['receiver_id' => $receiver->id, 'external_ref' => 'ZZREF-2569/88']))->assertSessionHasNoErrors();
    expect(\App\Models\LoanMovement::where('incident_id', $incident->id)->orderBy('id')->pluck('movement_type')->all())->toBe(['MARK_LOST', 'WRITE_OFF', 'REPLACE_IN']);

    $viewer = $owner === 'borrower' ? $borrower : $clerk;
    $page = $this->actingAs($viewer)->get(route('loans.show', $loan))->assertOk();
    // The rows themselves are listed (what happened to the pieces), without the people and without any reference.
    $page->assertSee(__('loans.movement_write_off'))->assertSee(__('loans.movement_replace_in'));
    foreach (['ZZRECORDER', 'ZZRESOLVER', 'ZZRECEIVER', 'ZZREF'] as $secret) {
        $page->assertDontSee($secret);
    }

    // The branch's staff (who may open the case) see the people on the same page.
    $this->actingAs($resolver)->get(route('loans.show', $loan))->assertOk()
        ->assertSee('ZZRECORDER')->assertSee('ZZRESOLVER')->assertSee('ZZRECEIVER');
})->with(['borrower', 'on-behalf creator']);

test('PDPA: the reference value of an incident is shown to holders of loan.asset_manage only — not to the counter\'s scientists, and never on the list', function () {
    [$lab, $asset, , $recorder, , $incident] = incidentCase('LOST');
    DB::table('loan_assets')->where('id', $asset->id)->update(['replacement_value' => '4321.55']);
    attachLoanIncidentFile($incident, $recorder);
    $resolver = auditorUser(['lab_id' => $lab->id]);
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $labManager = labManagerUser(['lab_id' => $lab->id]);
    $admin = adminUser();
    $label = __('loans.field_value_snapshot');

    // Nothing to show before the result (the value is taken on the day the result is recorded).
    $this->actingAs($resolver)->get(route('loans.incidents.show', $incident))->assertOk()->assertDontSee($label)->assertDontSee('4,321');

    loanResolvePost($this, $resolver, $incident, loanResolveData('COMPENSATED', ['external_ref' => 'ZZREF-2569/77']))->assertSessionHasNoErrors();
    expect($incident->fresh()->value_snapshot)->toBe('4321.55');

    foreach ([$resolver, $labManager, $admin] as $viewer) {
        expect($viewer->hasPermission('loan.asset_manage'))->toBeTrue();
        $this->actingAs($viewer)->get(route('loans.incidents.show', $incident))->assertOk()->assertSee($label)->assertSee('4,321.55')->assertSee('ZZREF-2569/77');
    }
    foreach ([$scientist, $recorder] as $viewer) {
        expect($viewer->hasPermission('loan.asset_manage'))->toBeFalse();
        $this->actingAs($viewer)->get(route('loans.incidents.show', $incident))->assertOk()
            ->assertDontSee($label)->assertDontSee('4,321')->assertDontSee('4321.55')
            // They do see the rest of the case.
            ->assertSee('ZZREF-2569/77')->assertSee($incident->description);
    }

    // The list: no value, no description, no reference, for anybody.
    foreach ([$resolver, $scientist, $admin] as $viewer) {
        $this->actingAs($viewer)->get(route('loans.incidents.index'))->assertOk()
            ->assertDontSee('4,321')->assertDontSee('4321.55')->assertDontSee('ZZREF')->assertDontSee($incident->description);
    }
});

// --- notices ----------------------------------------------------------------------------------------------------------

test('a recorded incident is announced to the people of the branch who MAY record its result: in the system to all, by email to the warehouse managers, with the document number and date only', function () {
    [$lab, $asset] = loanBranch();
    $other = makeLab();
    // The borrower and the clerk who typed the loan in are approvers of the branch: they are parties, so they are not told.
    $borrower = scientistUser(['lab_id' => $lab->id, 'person_type' => 'STAFF', 'profile_completed_at' => now(), 'full_name' => 'ZZ ผู้ยืมสมมุติ', 'username' => 'zz.borrower.secret']);
    $clerk = auditorUser(['lab_id' => $lab->id]);
    $recorder = scientistUser(['lab_id' => $lab->id, 'full_name' => 'ZZ ผู้บันทึกเหตุ']);
    $told = ['scientist' => scientistUser(['lab_id' => $lab->id]), 'manager' => auditorUser(['lab_id' => $lab->id]), 'lab_manager' => labManagerUser(['lab_id' => $lab->id])];
    $silent = [
        'recorder' => $recorder, 'borrower' => $borrower, 'clerk' => $clerk,
        'admin' => adminUser(['lab_id' => $lab->id]), 'staff' => staffUser(['lab_id' => $lab->id]),
        'inactive' => scientistUser(['lab_id' => $lab->id, 'is_active' => false]),
        'other_scientist' => scientistUser(['lab_id' => $other->id]), 'other_manager' => auditorUser(['lab_id' => $other->id]), 'no_branch' => scientistUser(['lab_id' => null]),
    ];
    $loan = onBehalfOutLoan($borrower, $clerk, $asset, 3);

    loanReturnPost($this, $recorder, $loan, [1 => ['qty_damaged' => '1', 'incident_note' => 'ZZDESC ผู้ยืมทำตกจากโต๊ะ ฐานแตก']])->assertSessionHas('status', __('loans.returned_incident'));
    $incident = LoanIncident::where('loan_id', $loan->id)->sole();

    $body = __('loans.notify.incident_pending_body', ['doc_no' => $loan->doc_no, 'date' => now()->format('d/m/Y')]);
    foreach ($told as $name => $user) {
        $notice = Notification::where('user_id', $user->id)->get();
        expect($notice)->toHaveCount(1, "{$name} must be told once");
        expect([$notice[0]->type, $notice[0]->title, $notice[0]->body, $notice[0]->link_url])
            ->toBe([LoanNotifier::TYPE_INCIDENT_PENDING, __('loans.notify.incident_pending_title'), $body, route('loans.incidents.show', $incident)]);
    }
    expect(loanMailCount($told['scientist']))->toBe(0);
    expect(loanMailCount($told['manager']))->toBe(1)->and(loanMailCount($told['lab_manager']))->toBe(1);
    foreach ($silent as $name => $user) {
        expect(Notification::where('user_id', $user->id)->count())->toBe(0, "{$name} must not be told");
        expect(loanMailCount($user))->toBe(0, "{$name} must not be emailed");
    }

    // Two incidents in one return: one notice each, pointing at the list.
    Notification::query()->delete();
    loanReturnPost($this, $recorder, $loan, [1 => ['qty_damaged' => '1', 'qty_lost' => '1', 'incident_note' => 'ZZDESC ชิ้นที่สองร้าว ชิ้นที่สามหาย']])->assertSessionHasNoErrors();
    expect(Notification::where('user_id', $told['scientist']->id)->pluck('link_url')->all())->toBe([route('loans.incidents.index')]);

    // An ordinary return announces nothing.
    Notification::query()->delete();
    $plain = handedOutLoan(staffUser(['lab_id' => $lab->id]), $asset, 1);
    loanReturnPost($this, $recorder, $plain, [1 => ['qty' => '1', 'condition' => 'GOOD']])->assertSessionHasNoErrors();
    expect(Notification::count())->toBe(0);

    // PDPA: no name, no description, nothing of the borrower in any notice or email.
    $text = incidentNoticeText();
    expect($text)->toContain($loan->doc_no);
    foreach (['ZZDESC', 'ทำตกจากโต๊ะ', 'ZZ ผู้ยืมสมมุติ', 'zz.borrower.secret', 'ZZ ผู้บันทึกเหตุ', (string) $borrower->email, (string) $recorder->email] as $leak) {
        expect($text)->not->toContain($leak);
    }
});

test('FR-LN-N9: every write-off (also a replacement) tells every active ADMIN in the system and by email — document number and date only — and nobody else; a repair tells nobody', function () {
    [$lab, $asset] = loanBranch();
    DB::table('loan_assets')->where('id', $asset->id)->update(['replacement_value' => '4321.55']);
    $borrower = staffUser(['lab_id' => $lab->id, 'full_name' => 'ZZ ผู้ยืมสมมุติ', 'username' => 'zz.borrower.secret']);
    $recorder = scientistUser(['lab_id' => $lab->id, 'full_name' => 'ZZ ผู้บันทึกเหตุ']);
    $resolver = auditorUser(['lab_id' => $lab->id, 'full_name' => 'ZZ ผู้บันทึกผล']);
    $receiver = scientistUser(['lab_id' => $lab->id, 'full_name' => 'ZZ ผู้รับของทดแทน']);
    $admins = [adminUser(), adminUser(['lab_id' => $lab->id]), navUser('admin+scientist', ['lab_id' => makeLab()->id])];
    $inactiveAdmin = adminUser(['is_active' => false]);
    $manager = labManagerUser(['lab_id' => $lab->id]);
    $loan = handedOutLoan($borrower, $asset, 4);
    $repair = recordLoanIncident($loan, $recorder, 'DAMAGED', 1, 1, 'ZZDESC ฐานแตกจากการทำตก');
    $writeOff = recordLoanIncident($loan, $recorder, 'LOST', 1, 1, 'ZZDESC หายระหว่างออกภาคสนาม');
    $replace = recordLoanIncident($loan, $recorder, 'DAMAGED', 1, 1, 'ZZDESC จอแตกทั้งแผ่น');
    attachLoanIncidentFile($writeOff, $recorder);
    attachLoanIncidentFile($replace, $recorder);
    $evidence = ['external_ref' => 'ZZREF-2569/77', 'resolution_note' => 'ZZNOTE ผู้ยืมชดใช้ตามมูลค่าแล้ว'];

    // A repair: nothing left the register.
    loanResolvePost($this, $resolver, $repair, REPAIR_OK)->assertSessionHasNoErrors();
    // A refused result announces nothing either.
    loanResolvePost($this, $resolver, $writeOff, ['resolution' => 'WAIVED', 'resolution_note' => 'ZZNOTE ไม่มีเลขอ้างอิง'])->assertSessionHasErrors('incident');
    expect(Notification::count())->toBe(0);
    Mail::assertNothingQueued();
    Mail::assertNothingSent();

    loanResolvePost($this, $resolver, $writeOff, array_merge(['resolution' => 'COMPENSATED'], $evidence))->assertSessionHasNoErrors();
    $body = __('loans.notify.write_off_body', ['doc_no' => $loan->doc_no, 'date' => now()->format('d/m/Y')]);
    foreach ($admins as $admin) {
        $notice = Notification::where('user_id', $admin->id)->get();
        expect($notice)->toHaveCount(1);
        expect([$notice[0]->type, $notice[0]->title, $notice[0]->body, $notice[0]->link_url])
            ->toBe([LoanNotifier::TYPE_WRITE_OFF, __('loans.notify.write_off_title'), $body, route('loans.incidents.show', $writeOff)]);
        expect(loanMailCount($admin))->toBe(1);
    }
    foreach ([$inactiveAdmin, $manager, $resolver, $recorder, $receiver, $borrower] as $user) {
        expect(Notification::where('user_id', $user->id)->count())->toBe(0);
        expect(loanMailCount($user))->toBe(0);
    }

    // REPLACED is a write-off too: a second notice.
    loanResolvePost($this, $resolver, $replace, array_merge(['resolution' => 'REPLACED', 'receiver_id' => $receiver->id], $evidence))->assertSessionHasNoErrors();
    foreach ($admins as $admin) {
        expect(Notification::where('user_id', $admin->id)->where('type', LoanNotifier::TYPE_WRITE_OFF)->orderBy('id')->pluck('link_url')->all())
            ->toBe([route('loans.incidents.show', $writeOff), route('loans.incidents.show', $replace)]);
        expect(loanMailCount($admin))->toBe(2);
    }
    expect(Notification::count())->toBe(6);

    // PDPA: no person, no reason, no reference, no value in the notice or the email.
    $text = incidentNoticeText();
    expect($text)->toContain($loan->doc_no);
    foreach (['ZZDESC', 'ZZREF', 'ZZNOTE', '4321.55', '4,321', 'ZZ ผู้ยืมสมมุติ', 'zz.borrower.secret', 'ZZ ผู้บันทึกเหตุ', 'ZZ ผู้บันทึกผล', 'ZZ ผู้รับของทดแทน', 'ชดใช้', (string) $borrower->email, (string) $resolver->email, (string) $recorder->email] as $leak) {
        expect($text)->not->toContain($leak);
    }
});

test('FR-LN-N9: an ADMIN who is the borrower of the loan is told too, with a link to the document (the incident page is closed to them)', function () {
    [$lab, $asset] = loanBranch();
    $adminBorrower = adminUser(['lab_id' => $lab->id, 'person_type' => 'STAFF', 'profile_completed_at' => now()]);
    $otherAdmin = adminUser();
    $recorder = scientistUser(['lab_id' => $lab->id]);
    $resolver = auditorUser(['lab_id' => $lab->id]);
    $loan = handedOutLoan($adminBorrower, $asset, 1);
    $incident = recordLoanIncident($loan, $recorder, 'LOST', 1);
    attachLoanIncidentFile($incident, $recorder);

    loanResolvePost($this, $resolver, $incident, loanResolveData('WAIVED'))->assertSessionHasNoErrors();

    expect(Notification::where('user_id', $adminBorrower->id)->sole()->link_url)->toBe(route('loans.show', $loan));
    expect(Notification::where('user_id', $otherAdmin->id)->sole()->link_url)->toBe(route('loans.incidents.show', $incident));
    $this->actingAs($adminBorrower)->get(route('loans.incidents.show', $incident))->assertForbidden();
    $this->actingAs($adminBorrower)->get(route('loans.show', $loan))->assertOk();
});

test('FR-LN-N9, the other half: lowering the count (ADJUST_OUT) of an asset that was EVER lent tells every ADMIN; an asset that never left the shelf, or a count going up, tells nobody', function () {
    [$lab, $manager, $approver] = loanManagers();
    $lent = registerLoanAsset($manager, $lab, 'POOL', 10);
    $neverLent = registerLoanAsset($manager, $lab, 'POOL', 10);
    $admin = adminUser();
    $handler = scientistUser(['lab_id' => $lab->id]);
    $loan = handedOutLoan(staffUser(['lab_id' => $lab->id]), $lent, 1, $handler);
    app(LoanCirculationService::class)->receiveReturn($handler->fresh(), $loan, [1 => ['qty' => '1', 'condition' => 'GOOD']]);
    $adjust = fn (LoanAsset $asset, array $data) => $this->actingAs($manager)->post(route('loans.assets.adjust', $asset), array_merge(
        ['qty' => 1, 'note' => 'นับใหม่แล้วพบว่าจำนวนไม่ตรง', 'approved_by' => $approver->id],
        $data,
    ));
    $writeOffNotices = fn () => Notification::where('user_id', $admin->id)->where('type', LoanNotifier::TYPE_WRITE_OFF)->get();

    $adjust($neverLent, ['direction' => 'OUT'])->assertSessionHasNoErrors();
    $adjust($lent, ['direction' => 'IN'])->assertSessionHasNoErrors();
    expect($writeOffNotices())->toHaveCount(0);

    // Ever lent + going down: an outside reference is required, and a refused adjustment tells nobody.
    $adjust($lent, ['direction' => 'OUT'])->assertSessionHasErrors();
    expect($writeOffNotices())->toHaveCount(0);

    $adjust($lent, ['direction' => 'OUT', 'external_ref' => 'ZZREF-2569/88'])->assertSessionHasNoErrors();
    $notices = $writeOffNotices();
    expect($notices)->toHaveCount(1);
    expect([$notices[0]->title, $notices[0]->body, $notices[0]->link_url])
        ->toBe([__('loans.notify.adjust_out_title'), __('loans.notify.adjust_out_body', ['date' => now()->format('d/m/Y')]), route('loans.assets.show', $lent)]);
    expect(loanMailCount($admin))->toBe(1);
    expect($notices[0]->body)->not->toContain('ZZREF')->not->toContain('นับใหม่');
});

test('if telling ADMIN fails, the recorded result stands, the user is redirected as usual and a warning is logged', function () {
    [$lab, , , $recorder, , $incident] = incidentCase('LOST');
    attachLoanIncidentFile($incident, $recorder);
    $resolver = auditorUser(['lab_id' => $lab->id]);
    adminUser();
    Log::spy();
    Notification::creating(fn () => throw new RuntimeException('mail server down'));

    loanResolvePost($this, $resolver, $incident, loanResolveData('WAIVED'))
        ->assertRedirect(route('loans.incidents.show', $incident))->assertSessionHas('status', __('loans.incident_resolved'));

    expect($incident->fresh()->resolution)->toBe('WAIVED')->and($incident->fresh()->disposition)->toBe('WRITE_OFF');
    expect(Notification::count())->toBe(0);
    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains((string) $message, 'Failed to notify ADMIN about the write-off'))->once();
});

test('if announcing a recorded incident fails, the return and the incident stand', function () {
    [$lab, $asset] = loanBranch();
    $recorder = scientistUser(['lab_id' => $lab->id]);
    auditorUser(['lab_id' => $lab->id]);
    $loan = handedOutLoan(staffUser(['lab_id' => $lab->id]), $asset, 2);
    Log::spy();
    Notification::creating(fn () => throw new RuntimeException('mail server down'));

    loanReturnPost($this, $recorder, $loan, [1 => ['qty_lost' => '1', 'incident_note' => 'หายระหว่างออกภาคสนามนอกสถานที่']])
        ->assertRedirect(route('loans.show', $loan))->assertSessionHas('status', __('loans.returned_incident'));

    expect(LoanIncident::where('loan_id', $loan->id)->sole()->resolution)->toBe('PENDING');
    expect($asset->fresh()->qty_unavailable)->toBe('1.000000');
    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains((string) $message, 'Failed to notify about incidents'))->once();
});
