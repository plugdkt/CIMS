<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Location;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** FR-MD-04: 4-level hierarchy (BUILDING > ROOM > CABINET > SHELF). */
final class LocationRequest extends FormRequest
{
    /** The only level allowed directly above each level — inferred from the 4-level
     *  hierarchy FR-MD-04 calls for; spec's §5.2 DDL has no CHECK constraint for it. */
    private const REQUIRED_PARENT_LEVEL = [
        'BUILDING' => null,
        'ROOM' => 'BUILDING',
        'CABINET' => 'ROOM',
        'SHELF' => 'CABINET',
    ];

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
            'level_type' => ['required', Rule::in(array_keys(self::REQUIRED_PARENT_LEVEL))],
            'storage_class' => ['nullable', 'string', 'max:64'],
            'lab_id' => ['nullable', 'integer', Rule::exists('labs', 'id')],
            'parent_id' => ['nullable', 'integer', $parentExists],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $user = $this->user();
            if ($user !== null && $user->hasRole('LAB_MANAGER')) {
                $submittedLabId = $this->input('lab_id') !== null ? (int) $this->input('lab_id') : null;
                if ($submittedLabId !== $user->lab_id) {
                    $validator->errors()->add('lab_id', __('locations.validation.lab_must_match_own'));
                }
            }

            $levelType = $this->input('level_type');
            $parentId = $this->input('parent_id');
            $requiredParentLevel = self::REQUIRED_PARENT_LEVEL[$levelType] ?? null;

            if ($requiredParentLevel === null) {
                if ($parentId !== null) {
                    $validator->errors()->add('parent_id', __('locations.validation.building_no_parent'));
                }

                return;
            }

            if ($parentId === null) {
                $validator->errors()->add('parent_id', __('locations.validation.parent_required'));

                return;
            }

            $parent = Location::query()->whereKey($parentId)->first();

            if ($parent !== null && $parent->level_type !== $requiredParentLevel) {
                $validator->errors()->add('parent_id', __('locations.validation.parent_level_mismatch', [
                    'level' => $requiredParentLevel,
                ]));
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
        ];
    }
}
