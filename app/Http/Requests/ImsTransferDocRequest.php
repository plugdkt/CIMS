<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Inventory\Services\ImsBatchTransferService;
use App\Domain\Inventory\Services\WorkingStockDestinations;
use App\Domain\Shared\ItemType;
use App\Models\ImsLot;
use App\Models\Location;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * ITM-2610-062: the paper-requisition move form (one document number + many rows). Shared by the review step and
 * the final save. Every row is authorised on its own (`Gate transfer` on the lot) BEFORE anything is written; a row
 * for a kind the user does not look after makes the whole request a 403 (the page never posts such rows: it shows them
 * read-only). A paper document may list several kinds; each manager moves only their own.
 *
 * `rows.*.qty` is a number of WHOLE PACKS (CEO: no part-packs). It becomes base units = packs x the lot's pack size;
 * a lot with no recorded pack size counts one pack as one base unit.
 */
abstract class ImsTransferDocRequest extends FormRequest
{
    /** @var list<array{lot: ImsLot, packs: string, qty: numeric-string, location: Location}>|null */
    private ?array $resolvedRows = null;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('transferAny', ImsLot::class);
    }

    /** A failed check goes back to the fill-in page (the review page is itself the result of a POST, so "back" would be a 405). */
    protected function getRedirectUrl(): string
    {
        return route('ims.transfers.create');
    }

    private function stripThousands(string $value): string
    {
        return preg_match('/^\d{1,3}(,\d{3})+$/', $value) === 1 ? str_replace(',', '', $value) : $value;
    }

    protected function prepareForValidation(): void
    {
        $rows = [];
        foreach ((array) $this->input('rows', []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rows[] = [
                'lot' => is_string($row['lot'] ?? null) ? trim($row['lot']) : '',
                // Only a correct thousands format ("1,000") loses its commas; "1,5" stays as typed and is refused.
                'qty' => is_string($row['qty'] ?? null) ? $this->stripThousands(trim($row['qty'])) : '',
                'location' => is_string($row['location'] ?? null) ? trim($row['location']) : '',
            ];
        }

        $this->merge([
            'ims_doc_no' => is_string($this->input('ims_doc_no')) ? trim($this->input('ims_doc_no')) : $this->input('ims_doc_no'),
            'rows' => $rows,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ims_doc_no' => ['required', 'string', 'max:64'],
            'remark' => ['nullable', 'string', 'max:500'],
            'rows' => ['required', 'array', 'min:1', 'max:100'],
            'rows.*.lot' => ['required', 'string', 'size:26'],
            // Whole packs only (positive integer string, never a float).
            'rows.*.qty' => ['required', 'string', 'regex:/^[1-9]\d{0,8}$/'],
            'rows.*.location' => ['required', 'string', 'size:26'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'ims_doc_no' => (string) __('ims_transfer.field_doc_no'),
            'rows' => (string) __('ims_transfer.rows_label'),
            'rows.*.lot' => (string) __('ims_transfer.col_lot'),
            'rows.*.qty' => (string) __('ims_transfer.col_qty'),
            'rows.*.location' => (string) __('ims_transfer.col_location'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $this->resolveRows($v);
        });
    }

    /**
     * The rows as models. Only valid after validation passed.
     *
     * @return list<array{lot: ImsLot, packs: string, qty: numeric-string, location: Location}>  qty = base units
     */
    public function rows(): array
    {
        return $this->resolvedRows ?? [];
    }

    private function resolveRows(Validator $v): void
    {
        $user = $this->user();
        if ($user === null) {
            return;
        }

        /** @var list<array{lot: string, qty: numeric-string, location: string}> $input */
        $input = array_values((array) $this->input('rows'));

        $lots = ImsLot::query()
            ->with(['item.category', 'item.baseUnit'])
            ->whereIn('ulid', array_column($input, 'lot'))
            ->get()
            ->keyBy('ulid');
        $locations = Location::query()->whereIn('ulid', array_column($input, 'location'))->get()->keyBy('ulid');

        $destinations = app(WorkingStockDestinations::class);
        $rows = [];
        /** @var array<int, numeric-string> $asked */
        $asked = [];

        foreach ($input as $i => $row) {
            $lot = $lots->get($row['lot']);
            $location = $locations->get($row['location']);

            if ($lot === null) {
                $v->errors()->add("rows.$i.lot", (string) __('ims_transfer.error.lot_not_found'));

                continue;
            }

            // A lot emptied (by someone else) while the form was open is a normal "not enough" message, not a 403.
            $emptied = bccomp($lot->qty_remaining_base, '0', 6) <= 0 && $user->lab_id === $lot->lab_id && $user->can('ims.manage');
            if (! Gate::forUser($user)->allows('transfer', $lot)) {
                if (! $emptied || $lot->item === null || ! $user->managesItemType(ItemType::forItem($lot->item))) {
                    throw new AuthorizationException();
                }
                $v->errors()->add("rows.$i.qty", (string) __('ims_transfer.error.lot_empty'));

                continue;
            }

            if (
                $location === null
                || $location->lab_id !== $lot->lab_id
                || ($lot->item !== null && ! $destinations->allows($location, (int) $lot->lab_id, ItemType::forItem($lot->item)))
            ) {
                $v->errors()->add("rows.$i.location", (string) __('ims.error.location_other_branch'));

                continue;
            }

            // Whole packs -> base units (a lot with no pack size: 1 pack = 1 base unit). BCMath only.
            $pack = $lot->pack_size_base;
            /** @var numeric-string $size */
            $size = ($pack !== null && bccomp($pack, '0', 6) > 0) ? $pack : '1';
            $base = bcmul($row['qty'], $size, 6);

            // The same lot may appear on several rows; together they cannot exceed what it holds.
            $asked[$lot->id] = bcadd($asked[$lot->id] ?? '0', $base, 6);
            if (bccomp($asked[$lot->id], $lot->qty_remaining_base, 6) > 0) {
                $whole = bcdiv($lot->qty_remaining_base, $size, 0);
                $v->errors()->add("rows.$i.qty", (string) __('ims_transfer.error.exceeds', ['remaining' => $whole]));

                continue;
            }

            $rows[] = ['lot' => $lot, 'packs' => $row['qty'], 'qty' => $base, 'location' => $location];
        }

        // Ceiling on containers per document, checked before any transaction.
        $containers = 0;
        foreach ($rows as $r) {
            $containers += ImsBatchTransferService::containerCount($r['lot'], $r['qty']);
        }
        if ($containers > ImsBatchTransferService::MAX_CONTAINERS_PER_DOC) {
            $v->errors()->add('rows', (string) __('ims_transfer.error.too_many_containers', [
                'max' => ImsBatchTransferService::MAX_CONTAINERS_PER_DOC,
                'count' => $containers,
            ]));
        }

        if ($v->errors()->isEmpty()) {
            $this->resolvedRows = $rows;
        }
    }
}
