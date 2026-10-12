<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Exceptions\ImsException;
use App\Domain\Inventory\Services\ImsService;
use App\Domain\Inventory\Services\ImsReportImporter;
use App\Http\Requests\ImsReceiptLineRequest;
use App\Http\Requests\ImsReceiptLineUpdateRequest;
use App\Http\Requests\ImsReceiptRequest;
use App\Models\ImsReceipt;
use App\Models\ImsReceiptLine;
use App\Models\Item;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * IMS purchase documents: a warehouse manager records (or later has AI read) a document
 * printed from the university's warehouse system, checks every line, then confirms it —
 * only then does it become IMS stock.
 */
final class ImsReceiptController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', ImsReceipt::class);

        /** @var User $user */
        $user = $request->user();

        $receipts = ImsReceipt::query()
            ->with(['lab', 'creator'])
            ->withCount('lines')
            ->when(! $user->hasRole('ADMIN'), fn ($q) => $q->where('lab_id', $user->lab_id))
            ->latest('id')
            ->paginate(20);

        return view('ims.receipts.index', ['receipts' => $receipts]);
    }

    public function create(): View
    {
        $this->authorize('create', ImsReceipt::class);

        return view('ims.receipts.create');
    }

    public function store(ImsReceiptRequest $request, ImsReportImporter $importer): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        $pdf = $request->file('pdf');
        $path = null;
        $name = null;
        $hash = null;
        if ($pdf !== null) {
            $hash = hash_file('sha256', $pdf->getRealPath());

            // The same printout imported twice would put every pack into IMS twice.
            $alreadyImported = ImsReceipt::where('lab_id', $user->lab_id)
                ->where('source_file_hash', $hash)
                ->where('status', '!=', 'CANCELLED')
                ->exists();
            if ($alreadyImported) {
                return back()->withInput()->withErrors(['pdf' => __('ims.error.pdf_already_imported')]);
            }

            $path = $pdf->store('ims-receipts', 'local');
            $name = $pdf->getClientOriginalName();
        }

        $receipt = ImsReceipt::create([
            'lab_id' => $user->lab_id,
            'doc_no' => $data['doc_no'] ?? null,
            'fiscal_year' => $data['fiscal_year'] ?? null,
            'purchase_round' => $data['purchase_round'] ?? null,
            'source_file_path' => $path,
            'source_file_name' => $name,
            'source_file_hash' => $hash,
            'status' => 'DRAFT',
            'created_by' => $user->id,
        ]);

        if ($pdf !== null && $request->boolean('import_report')) {
            try {
                $importer->import($receipt, Storage::disk('local')->path((string) $path));
            } catch (ImsException $e) {
                $receipt->lines()->delete();
                $receipt->delete();
                Storage::disk('local')->delete((string) $path);

                return back()->withInput()->withErrors(['pdf' => $e->getMessage()]);
            }
        }

        return redirect()->route('ims.receipts.show', $receipt)->with('status', __('ims.receipt_created'));
    }

    public function show(ImsReceipt $imsReceipt): View
    {
        $this->authorize('view', $imsReceipt);

        $imsReceipt->load(['lab', 'creator', 'lines.item.packageUnit', 'lines.unit']);
        $imsReceipt->lines->each(fn ($line) => $line->setRelation('receipt', $imsReceipt));

        return view('ims.receipts.show', [
            'receipt' => $imsReceipt,
            'units' => Unit::orderBy('sort_order')->get(),
        ]);
    }

    public function storeLine(ImsReceiptLineRequest $request, ImsReceipt $imsReceipt): RedirectResponse
    {
        $data = $request->validated();
        $item = Item::where('item_code', trim((string) $data['item_code']))->first();

        if ($item === null) {
            return back()->withInput()->withErrors(['item_code' => __('ims.error.item_code_not_found')]);
        }

        $imsReceipt->lines()->create([
            'line_no' => 1 + (int) $imsReceipt->lines()->max('line_no'),
            'item_id' => $item->id,
            'item_code_raw' => $item->item_code,
            'name_raw' => $item->name_th,
            'lot_no' => $data['lot_no'] ?? null,
            'pack_qty' => $data['pack_qty'],
            'qty' => $data['qty'] ?? null,
            'unit_id' => $data['unit_id'] ?? null,
            'unit_price' => $data['unit_price'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'remark' => $data['remark'] ?? null,
        ]);

        return redirect()->route('ims.receipts.show', $imsReceipt)->with('status', __('ims.line_added'));
    }

    public function updateLine(ImsReceiptLineUpdateRequest $request, ImsReceipt $imsReceipt, ImsReceiptLine $line): RedirectResponse
    {
        abort_unless($line->ims_receipt_id === $imsReceipt->id, 404);
        abort_if($line->ims_lot_id !== null, 403);
        $data = $request->validated();

        $line->update([
            'lot_no' => $data['lot_no'] ?? null,
            'pack_qty' => $data['pack_qty'],
            'qty' => $data['qty'] ?? null,
            'unit_id' => $data['unit_id'] ?? null,
            'unit_price' => $data['unit_price'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'remark' => $data['remark'] ?? null,
        ]);

        return redirect()->route('ims.receipts.show', $imsReceipt)->with('status', __('ims.line_updated'));
    }

    public function destroyLine(ImsReceipt $imsReceipt, ImsReceiptLine $line): RedirectResponse
    {
        $this->authorize('update', $imsReceipt);
        abort_unless($line->ims_receipt_id === $imsReceipt->id, 404);
        abort_if($line->ims_lot_id !== null, 403);

        $line->delete();

        return redirect()->route('ims.receipts.show', $imsReceipt)->with('status', __('ims.line_removed'));
    }

    public function confirm(Request $request, ImsReceipt $imsReceipt, ImsService $service): RedirectResponse
    {
        $this->authorize('update', $imsReceipt);

        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*' => ['integer'],
        ], ['lines.required' => __('ims.error.none_selected'), 'lines.min' => __('ims.error.none_selected')]);
        /** @var list<int> $lineIds */
        $lineIds = array_map('intval', $data['lines']);

        try {
            $service->confirm($imsReceipt, $user, $lineIds);
        } catch (ImsException $e) {
            return back()->withErrors(['confirm' => $e->getMessage()]);
        }

        return redirect()->route('ims.receipts.show', $imsReceipt)->with(
            'status',
            $imsReceipt->fresh()?->isDraft()
                ? __('ims.receipt_partially_confirmed', ['count' => count($lineIds)])
                : __('ims.receipt_confirmed'),
        );
    }

    public function cancel(ImsReceipt $imsReceipt): RedirectResponse
    {
        $this->authorize('update', $imsReceipt);

        // Lots already created from this document can't be un-received by cancelling it.
        if ($imsReceipt->lines()->whereNotNull('ims_lot_id')->exists()) {
            return back()->withErrors(['confirm' => __('ims.error.cancel_has_confirmed')]);
        }

        $imsReceipt->status = 'CANCELLED';
        $imsReceipt->save();

        return redirect()->route('ims.receipts.index')->with('status', __('ims.receipt_cancelled'));
    }

    public function source(ImsReceipt $imsReceipt): StreamedResponse
    {
        $this->authorize('view', $imsReceipt);
        abort_if($imsReceipt->source_file_path === null, 404);

        return Storage::disk('local')->response($imsReceipt->source_file_path, $imsReceipt->source_file_name);
    }
}
