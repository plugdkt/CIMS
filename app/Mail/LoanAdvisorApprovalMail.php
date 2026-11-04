<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Loan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * ITM-2610-051 FR-LN-12 / WP-2.5: the advisor's emailed alternative to logging in. By design it carries only the
 * document number, what is asked for (item names and quantities) and the return date: no student name, phone or
 * code (spec section 10). The signed link opens a page that shows what the advisor needs to decide.
 */
final class LoanAdvisorApprovalMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Loan $loan,
        public readonly string $signedUrl,
        public readonly string $advisorName,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('loans.mail.advisor_subject', ['doc_no' => $this->loan->doc_no]),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.loans.advisor-approval');
    }
}
