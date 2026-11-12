<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Attachments\Exceptions\InfectedFileException;
use App\Domain\Attachments\Services\AttachmentUploadService;
use App\Http\Requests\UploadAttachmentRequest;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\LoanIncident;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AttachmentController extends Controller
{
    public function store(UploadAttachmentRequest $request, Item $item, AttachmentUploadService $uploads): RedirectResponse
    {
        $userId = $request->user()?->id;
        abort_if($userId === null, 401);

        try {
            $uploads->upload(
                file: $request->file('file'),
                owner: $item,
                docType: $request->string('doc_type')->toString(),
                uploadedBy: $userId,
                revisedDate: $request->input('revised_date'),
            );
        } catch (InfectedFileException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return back()->with('status', __('attachments.uploaded'));
    }

    /** SEC-AZ-06: authorization checked here, on every request — never a direct/public URL. */
    public function download(Attachment $attachment): StreamedResponse
    {
        $owner = $attachment->owner;
        Gate::authorize('view', $owner);

        // ITM-2610-051: a file of a loan incident is audited here exactly like on its own route
        // (LoanIncidentController::file), so this generic URL is not a way to read one without a trace.
        if ($owner instanceof LoanIncident) {
            $user = auth()->user();
            AuditLog::record(
                action: 'LOAN_INCIDENT_FILE_DOWNLOAD',
                userId: $user?->id,
                username: $user?->username,
                entityType: LoanIncident::class,
                entityId: $owner->id,
                newValue: ['attachment' => $attachment->ulid, 'doc_type' => $attachment->doc_type, 'via' => 'attachments.download'],
            );
        }

        return Storage::disk('attachments')->download($attachment->stored_name, $attachment->original_name);
    }
}
