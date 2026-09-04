<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Requisition;
use Illuminate\Contracts\View\View;

/**
 * §7.2 `GET /verify/{ulid}` — public, no login. Only doc_no/date/status, never personal
 * data (spec: "ห้ามแสดงข้อมูลส่วนบุคคล") — this exists purely so a printed F-01's QR code
 * lets anyone confirm the document is genuine, not to expose who requested what.
 */
final class DocumentVerifyController extends Controller
{
    public function show(string $ulid): View
    {
        $requisition = Requisition::where('ulid', $ulid)->firstOrFail();

        return view('verify.show', [
            'docNo' => $requisition->doc_no,
            'docDate' => $requisition->doc_date,
            'status' => $requisition->status,
        ]);
    }
}
