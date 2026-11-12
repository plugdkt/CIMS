<?php

declare(strict_types=1);

namespace App\Http\Requests\Loan;

use App\Models\LoanIncident;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /loans/incidents/{loan_incident}/resolve (step 4, FR-LN-24): the result of a damage / loss case that the
 * management decided outside the system. Shape only; which result goes with which kind, the evidence (reference,
 * note, attached letter) and who may record it are LoanIncidentService's rules.
 *
 * `receiver_id` (REPLACED only) is the internal id of a colleague chosen from the page's own list (the counter staff
 * of the branch); it is not a URL identifier, and the ledger checks that person again.
 */
final class LoanIncidentResolveRequest extends FormRequest
{
    public function authorize(): bool
    {
        $incident = $this->route('loan_incident');

        return $this->user() !== null && $incident instanceof LoanIncident && $this->user()->can('resolveIncident', $incident);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'resolution' => ['required', Rule::in(LoanIncident::RESOLUTIONS)],
            'disposition' => ['nullable', Rule::in(LoanIncident::DISPOSITIONS)],
            'condition_after' => ['nullable', Rule::in(['GOOD', 'FAIR'])],
            'external_ref' => ['nullable', 'string', 'max:255'],
            'resolution_note' => ['required', 'string', 'min:10', 'max:500'],
            'receiver_id' => ['nullable', 'integer', 'min:1', 'required_if:resolution,REPLACED'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'resolution.required' => __('loans.error.incident_resolution_invalid'),
            'resolution.in' => __('loans.error.incident_resolution_invalid'),
            'disposition.in' => __('loans.error.incident_resolution_invalid'),
            'condition_after.in' => __('loans.error.incident_condition_after'),
            'resolution_note.required' => __('loans.error.incident_note_required'),
            'resolution_note.min' => __('loans.error.incident_note_required'),
            'receiver_id.required_if' => __('loans.error.replace_receiver_invalid'),
            'receiver_id.integer' => __('loans.error.replace_receiver_invalid'),
        ];
    }
}
