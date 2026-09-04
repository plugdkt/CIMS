<?php

declare(strict_types=1);

namespace App\Domain\Requisition\Services;

use App\Domain\Requisition\Exceptions\InvalidSignatureImageException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * FR-RQ-11: decodes and stores the receiver's canvas-drawn signature. Mirrors
 * `AttachmentUploadService`'s shape (UUID filename, never anything client-controlled;
 * SHA-256 recorded) even though this isn't a file upload — it's a base64 PNG data URL
 * from `canvas.toDataURL()`, so there's no client filename/MIME header to trust or
 * distrust in the first place; validation instead checks the PNG magic bytes directly.
 */
final class SignatureImageService
{
    private const MAX_BYTES = 512 * 1024;
    private const DISK = 'signatures';
    private const PNG_MAGIC = "\x89PNG\r\n\x1a\n";
    private const DATA_URL_PREFIX = 'data:image/png;base64,';

    /**
     * @return array{path: string, hash: string}
     */
    public function store(string $dataUrl): array
    {
        if (! str_starts_with($dataUrl, self::DATA_URL_PREFIX)) {
            throw new InvalidSignatureImageException('รูปแบบลายเซ็นไม่ถูกต้อง');
        }

        $binary = base64_decode(substr($dataUrl, strlen(self::DATA_URL_PREFIX)), true);
        if ($binary === false || $binary === '' || ! str_starts_with($binary, self::PNG_MAGIC)) {
            throw new InvalidSignatureImageException('รูปแบบลายเซ็นไม่ถูกต้อง');
        }
        if (strlen($binary) > self::MAX_BYTES) {
            throw new InvalidSignatureImageException('ไฟล์ลายเซ็นมีขนาดใหญ่เกินไป');
        }

        $storedName = Str::uuid().'.png';
        Storage::disk(self::DISK)->put($storedName, $binary);

        return [
            'path' => $storedName,
            'hash' => hash('sha256', $binary),
        ];
    }
}
