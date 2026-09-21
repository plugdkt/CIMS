<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StockInRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return $user->can('receiving.manage')
            || $user->can('item.manage')
            || $user->can('ledger.adjust')
            || $user->hasRole('ADMIN');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'item_id' => ['required', 'integer', Rule::exists('items', 'id')],
            'tracking_type' => ['required', Rule::in(['BULK', 'CONTAINER'])],
            'qty' => ['required_if:tracking_type,BULK', 'nullable', 'numeric', 'gt:0'],
            'container_count' => ['required_if:tracking_type,CONTAINER', 'nullable', 'integer', 'min:1'],
            'qty_per_container' => ['required_if:tracking_type,CONTAINER', 'nullable', 'numeric', 'gt:0'],
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
            'location_id' => ['required', 'integer', Rule::exists('locations', 'id')],
            'lot_no' => ['nullable', 'string', 'max:64'],
            'expiry_date' => ['nullable', 'date'],
            'remark' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var User $user */
            $user = $this->user();
            if (! $user->isBranchManager()) {
                return;
            }

            $location = Location::find((int) $this->input('location_id'));
            if ($location !== null && $location->lab_id !== $user->lab_id) {
                $validator->errors()->add('location_id', __('stock.validation.location_must_match_own_lab'));
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'item_id.required' => __('stock.validation.item_required'),
            'item_id.exists' => __('stock.validation.item_not_found'),
            'qty.required_if' => __('stock.validation.qty_required'),
            'qty.gt' => __('stock.validation.qty_gt_zero'),
            'container_count.required_if' => __('stock.validation.container_count_required'),
            'container_count.min' => __('stock.validation.container_count_min'),
            'qty_per_container.required_if' => __('stock.validation.qty_per_container_required'),
            'qty_per_container.gt' => __('stock.validation.qty_gt_zero'),
            'unit_id.required' => __('stock.validation.unit_required'),
            'location_id.required' => __('stock.validation.location_required'),
            'expiry_date.date' => __('stock.validation.expiry_date_invalid'),
        ];
    }
}
