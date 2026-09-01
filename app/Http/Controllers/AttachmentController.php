<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Attachments\Exceptions\InfectedFileException;
use App\Domain\Attachments\Services\AttachmentUploadService;
use App\Http\Requests\UploadAttachmentRequest;
use App\Models\Attachment;
use App\Models\Item;
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
        Gate::authorize('view', $attachment->owner);

        return Storage::disk('attachments')->download($attachment->stored_name, $attachment->original_name);
    }
}
