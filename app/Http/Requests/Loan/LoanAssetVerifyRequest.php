<?php

declare(strict_types=1);

namespace App\Http\Requests\Loan;

use App\Models\LoanAsset;
use Illuminate\Foundation\Http\FormRequest;

/** FR-LN-32: "check integrity" of one asset's hash chain from its page (holders of loan.verify only). */
final class LoanAssetVerifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $asset = $this->route('loan_asset');
        $user = $this->user();

        return $asset instanceof LoanAsset
            && $user?->can('verifyChain', LoanAsset::class) === true
            && $user->can('viewRegistryEntry', $asset);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
