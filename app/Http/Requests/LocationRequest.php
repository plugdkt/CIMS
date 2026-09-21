<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Location;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * FR-MD-04: 4 informational levels (BUILDING/ROOM/CABINET/SHELF). User-requested
 * 2026-09-21: strict level-nesting (a CABINET's parent had to be a ROOM, etc.) is
 * removed — a location may parent to *any* other location in the same branch, or
 * have no parent at all ("ไม่ต้องผูกมัดกันครับ" — no forced root/hierarchy). Each
 * branch's tree is now fully independent: `lab_id` is required, and a `parent_id`
 * must belong to that same branch — a location can never cross into another lab's
 * tree.
 */
final class LocationRequest extends FormRequest
{
    private const LEVEL_TYPES = ['BUILDING', 'ROOM', 'CABINET', 'SHELF'];

    public function authorize(): bool
    {
        $user = $this->user();
        $location = $this->route('location');

        if ($user === null) {
            return false;
        }

        return $location instanceof Location
            ? $user->can('update', $location)
            : $user->can('create', Location::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $location = $this->route('location');
        $locationId = $location instanceof Location ? $location->id : null;

        $parentExists = Rule::exists('locations', 'id');
        if ($locationId !== null) {
            $parentExists = $parentExists->whereNot('id', $locationId);
        }

        return [
            'code' => ['required', 'string', 'max:32', Rule::unique('locations', 'code')->ignore($locationId)],
            'name' => ['required', 'string', 'max:128'],
            'level_type' => ['required', Rule::in(self::LEVEL_TYPES)],
            'storage_class' => ['nullable', 'string', 'max:64'],
            'lab_id' => ['required', 'integer', Rule::exists('labs', 'id')],
            'parent_id' => ['nullable', 'integer', $parentExists],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $user = $this->user();
            $submittedLabId = $this->input('lab_id') !== null ? (int) $this->input('lab_id') : null;

            if ($user !== null && $user->isBranchManager() && $submittedLabId !== $user->lab_id) {
                $validator->errors()->add('lab_id', __('locations.validation.lab_must_match_own'));
            }

            $parentId = $this->input('parent_id');
            if ($parentId === null) {
                return;
            }

            $parent = Location::query()->whereKey($parentId)->first();
            if ($parent !== null && $parent->lab_id !== $submittedLabId) {
                $validator->errors()->add('parent_id', __('locations.validation.parent_must_match_own_lab'));
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.required' => __('locations.validation.code_required'),
            'code.unique' => __('locations.validation.code_unique'),
            'name.required' => __('locations.validation.name_required'),
            'level_type.required' => __('locations.validation.level_type_required'),
            'lab_id.required' => __('locations.validation.lab_required'),
            'lab_id.exists' => __('locations.validation.lab_invalid'),
        ];
    }
}
