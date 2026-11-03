<?php

declare(strict_types=1);

namespace App\Http\Requests\Loan;

use App\Models\LoanAsset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters of the register page, the "available to lend" page and the register form's item search.
 * Which `ability` applies depends on the route (the three pages share these query parameters).
 */
final class LoanAssetListRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        return match ($this->route()?->getName()) {
            'loans.available' => $user->can('browseAvailable', LoanAsset::class),
            'loans.assets.create' => $user->can('registerAsset', LoanAsset::class),
            default => $user->can('browseRegistry', LoanAsset::class),
        };
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'RETIRED'])],
            // Only ADMIN picks a branch (validated for ADMIN alone); for everyone else the value is not
            // validated or used at all, the controller works on their own branch.
            'lab_id' => Rule::when(
                $this->user()?->hasRole('ADMIN') === true,
                ['nullable', 'integer', Rule::exists('labs', 'id')],
                [],
            ),
        ];
    }
}
