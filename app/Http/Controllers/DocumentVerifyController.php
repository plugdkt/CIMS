<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\Requisition;
use Illuminate\Contracts\View\View;

/**
 * §7.2 `GET /verify/{ulid}` — public, no login. Only doc_no/date/status, never personal
 * data (spec: "ห้ามแสดงข้อมูลส่วนบุคคล") — this exists purely so a printed F-01's QR code
 * lets anyone confirm the document is genuine, not to expose who requested what.
 *
 * ITM-2610-051 step 3 (FR-LN-25): the printed loan slip uses the same page. A ULID that is not a requisition is
 * looked up among the loans; the answer has the same three facts and nothing else (no borrower, no branch, no
 * items, no dates beyond the pick-up date). A DRAFT loan is not a document yet and answers 404 like an unknown ULID.
 */
final class DocumentVerifyController extends Controller
{
    public function show(string $ulid): View
    {
        $requisition = Requisition::where('ulid', $ulid)->first();
        if ($requisition !== null) {
            return view('verify.show', [
                'docNo' => $requisition->doc_no,
                'docDate' => $requisition->doc_date,
                'status' => $requisition->status,
            ]);
        }

        $loan = Loan::where('ulid', $ulid)->where('status', '!=', 'DRAFT')->firstOrFail();

        return view('verify.show', [
            'docNo' => $loan->doc_no,
            'docDate' => $loan->loan_date,
            'status' => $loan->status,
            // The plain status word: the "waiting for the advisor" wording would tell a stranger the borrower is a student.
            'statusLabel' => (string) __('loans.loan_status_'.strtolower($loan->status)),
        ]);
    }
}
