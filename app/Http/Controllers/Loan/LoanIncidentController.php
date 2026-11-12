<?php

declare(strict_types=1);

namespace App\Http\Controllers\Loan;

use App\Domain\Attachments\Exceptions\InfectedFileException;
use App\Domain\Attachments\Services\AttachmentUploadService;
use App\Domain\Loan\Exceptions\LoanDomainException;
use App\Domain\Loan\Services\LoanAccess;
use App\Domain\Loan\Services\LoanIncidentService;
use App\Domain\Loan\Services\LoanNotifier;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loan\LoanIncidentFileRequest;
use App\Http\Requests\Loan\LoanIncidentResolveRequest;
use App\Http\Requests\Loan\LoanListRequest;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\LoanIncident;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ITM-2610-051 step 4 (FR-LN-23/24): the damage / loss cases of the branch — the list, one case with its files, and
 * recording its result. Thin: validation in the Form Requests, access in LoanIncidentPolicy, rules in
 * LoanIncidentService, notifications in LoanNotifier (only after the change is saved).
 *
 * An incident is recorded on the return page (LoanCounterController::receiveReturn), not here.
 * The borrower never reaches these pages (LoanIncidentPolicy::view).
 */
final class LoanIncidentController extends Controller
{
    /** The branch's incidents: the ones waiting for a result first. ADMIN sees every branch. */
    public function index(LoanListRequest $request): View
    {
        $this->authorize('browseIncidents', LoanIncident::class);

        /** @var User $user */
        $user = $request->user();
        $allBranches = $user->hasRole('ADMIN') && $user->hasPermission('loan.view_all');

        $base = LoanIncident::query()
            ->with(['loan:id,ulid,doc_no,lab_id,borrower_id,created_by', 'loan.lab', 'line.asset.item'])
            // Never one's own case (as the borrower or as the person who typed the loan in): see LoanIncidentPolicy::view.
            ->whereHas('loan', function ($q) use ($user, $allBranches) {
                $q->where('borrower_id', '!=', $user->id)->where('created_by', '!=', $user->id);
                if (! $allBranches) {
                    $q->where('lab_id', $user->lab_id);
                }
            });

        return view('loans.incidents', [
            'pending' => (clone $base)->where('resolution', 'PENDING')->orderBy('id')->limit(200)->get(),
            'resolved' => (clone $base)->where('resolution', '!=', 'PENDING')->with('resolver:id,full_name')->orderByDesc('resolved_at')->paginate(15)->withQueryString(),
        ]);
    }

    public function show(LoanIncident $loanIncident, LoanAccess $access): View
    {
        $this->authorize('view', $loanIncident);

        $loanIncident->load([
            'loan.lab', 'loan.borrower:id,full_name', 'line.asset.item', 'recorder:id,full_name', 'resolver:id,full_name',
            'attachments.uploader:id,full_name', 'movements.creator:id,full_name', 'movements.approver:id,full_name',
        ]);

        /** @var User $viewer */
        $viewer = auth()->user();
        $loan = $loanIncident->loan;
        $canResolve = $viewer->can('resolveIncident', $loanIncident);

        // REPLACED needs a second person who took the replacement in: the counter staff of the loan's branch, never
        // the borrower and never the person recording the result (the ledger checks all of this again).
        $receivers = collect();
        if ($canResolve && $loan !== null) {
            $receivers = User::query()
                ->where('is_active', true)
                ->where('lab_id', $loan->lab_id)
                ->whereNotIn('id', [$viewer->id, $loan->borrower_id])
                ->whereHas('roles.permissions', fn ($q) => $q->where('code', 'loan.handout'))
                ->with('roles.permissions')
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'lab_id', 'is_active'])
                ->filter(fn (User $u) => $access->isHandlerFor($u, (int) $loan->lab_id))
                ->values();
        }

        return view('loans.incident', [
            'incident' => $loanIncident,
            'loan' => $loan,
            'canResolve' => $canResolve,
            'canAttach' => $viewer->can('attachFile', $loanIncident),
            'receivers' => $receivers,
            // Values are for the warehouse managers and ADMIN only (spec 4.2), not for the counter's scientists.
            'canSeeValue' => $viewer->hasPermission('loan.asset_manage'),
            // Why the viewer cannot record the result (for a plain sentence on the page), null when they can.
            'resolveProblem' => $loanIncident->isPending() && $loan !== null ? $access->incidentResolverProblem($viewer, $loan, $loanIncident) : null,
        ]);
    }

    public function resolve(LoanIncidentResolveRequest $request, LoanIncident $loanIncident, LoanIncidentService $service, LoanNotifier $notifier): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{resolution: string, disposition?: string|null, condition_after?: string|null, external_ref?: string|null, resolution_note?: string|null, receiver_id?: int|string|null} $data (shape guaranteed by LoanIncidentResolveRequest) */
        $data = $request->validated();

        try {
            $incident = $service->resolve($user, $loanIncident, $data);
        } catch (LoanDomainException $e) {
            return back()->withInput()->withErrors(['incident' => $e->getMessage()]);
        }

        // After the result is saved (a failure here cannot undo it): FR-LN-N9 for a write-off.
        if ($incident->disposition === 'WRITE_OFF') {
            $notifier->writtenOff($incident);
        }

        return redirect()->route('loans.incidents.show', $incident)->with('status', __('loans.incident_resolved'));
    }

    public function storeFile(LoanIncidentFileRequest $request, LoanIncident $loanIncident, AttachmentUploadService $uploads): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $file = $request->file('file');
        abort_unless($file instanceof \Illuminate\Http\UploadedFile, 422);
        $docType = $request->string('doc_type')->toString();

        try {
            $attachment = $uploads->upload($file, $loanIncident, $docType, $user->id);
        } catch (InfectedFileException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        AuditLog::record(
            action: 'LOAN_INCIDENT_FILE_UPLOAD',
            userId: $user->id,
            username: $user->username,
            entityType: LoanIncident::class,
            entityId: $loanIncident->id,
            newValue: ['attachment' => $attachment->ulid, 'doc_type' => $docType, 'sha256' => $attachment->sha256],
        );

        // The person who attached it may be someone who cannot open the incident page (e.g. the recorder who is
        // also the borrower): send them back where they came from.
        return back()->with('status', __('loans.incident_file_uploaded'));
    }

    /** SEC-AZ-06: authorised on every request; the file must belong to THIS incident (otherwise 404). */
    public function file(LoanIncident $loanIncident, Attachment $attachment): StreamedResponse
    {
        $this->authorize('view', $loanIncident);
        abort_unless(
            $attachment->owner_type === $loanIncident->getMorphClass()
            && (int) $attachment->owner_id === $loanIncident->id
            && in_array($attachment->doc_type, LoanIncident::FILE_TYPES, true),
            404,
        );

        /** @var User $user */
        $user = auth()->user();
        AuditLog::record(
            action: 'LOAN_INCIDENT_FILE_DOWNLOAD',
            userId: $user->id,
            username: $user->username,
            entityType: LoanIncident::class,
            entityId: $loanIncident->id,
            newValue: ['attachment' => $attachment->ulid, 'doc_type' => $attachment->doc_type],
        );

        return Storage::disk('attachments')->download($attachment->stored_name, $attachment->original_name);
    }
}
