<?php

declare(strict_types=1);

namespace App\Domain\Attachments\Services;

use App\Domain\Attachments\Contracts\VirusScanner;
use App\Domain\Attachments\Exceptions\InfectedFileException;
use App\Models\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * FR-MD-02 (multiple SDS versions, latest shown by default) + SEC-FU-01..07.
 * The only place attachments get written — mirrors LedgerService's "one writer" rule.
 */
final class AttachmentUploadService
{
    public function __construct(private readonly VirusScanner $scanner)
    {
    }

    public function upload(
        UploadedFile $file,
        Model $owner,
        string $docType,
        int $uploadedBy,
        ?string $revisedDate = null,
    ): Attachment {
        // SEC-FU-04: scan before anything is written permanently.
        if (config('attachments.virus_scan.enabled') && ! $this->scanner->isClean($file->getRealPath())) {
            throw new InfectedFileException();
        }

        // SEC-FU-09/path traversal: always a fresh UUID name, never the client's filename.
        $storedName = (string) Str::uuid().'.'.$file->getClientOriginalExtension();
        $disk = (string) config('attachments.disk');

        $ownerType = $owner->getMorphClass();
        $nextVersion = 1 + (int) Attachment::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $owner->getKey())
            ->where('doc_type', $docType)
            ->max('version');

        Storage::disk($disk)->putFileAs('', $file, $storedName);

        return Attachment::create([
            'owner_type' => $ownerType,
            'owner_id' => $owner->getKey(),
            'doc_type' => $docType,
            'original_name' => $file->getClientOriginalName(),
            'stored_name' => $storedName,
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()), // SEC-FU-07
            'version' => $nextVersion,
            'revised_date' => $revisedDate,
            'uploaded_by' => $uploadedBy,
        ]);
    }
}
