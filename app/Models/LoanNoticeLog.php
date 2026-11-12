<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * ITM-2610-051 step 5 (NFR-LN-05): one scheduled loan notice that was claimed for sending — the kind, the person,
 * the subject and the day. The table's UNIQUE key (`uq_loan_notice`) is what stops a second send; rows are written
 * by LoanDailyNotifier::claim() with INSERT IGNORE, never from a request.
 *
 * @property int $id
 * @property \Illuminate\Support\Carbon $notice_date
 * @property string $type
 * @property int $user_id
 * @property string $subject
 */
#[Fillable(['notice_date', 'type', 'user_id', 'subject', 'created_at'])]
final class LoanNoticeLog extends Model
{
    protected $table = 'loan_notice_log';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'notice_date' => 'date',
            'created_at' => 'datetime',
        ];
    }
}
