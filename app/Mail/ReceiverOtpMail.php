<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Requisition;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** FR-RQ-11: the receiver's OTP fallback to a drawn signature at issue time. */
final class ReceiverOtpMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Requisition $requisition,
        public readonly string $code,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('requisitions.mail_otp_subject', ['doc_no' => $this->requisition->doc_no]),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.requisitions.receiver-otp');
    }
}
