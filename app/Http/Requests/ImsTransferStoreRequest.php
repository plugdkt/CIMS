<?php

declare(strict_types=1);

namespace App\Http\Requests;

/** Step 3: same validation as the review plus the one-time token handed out by the review page (double-submit guard). */
final class ImsTransferStoreRequest extends ImsTransferDocRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...parent::rules(), 'token' => ['required', 'string', 'size:40']];
    }
}
