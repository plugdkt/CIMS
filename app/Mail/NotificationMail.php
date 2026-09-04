<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * FR-NT-01..06: one generic email shape for every notification type — mirrors the
 * `notifications` table's own generic (type/title/body/link_url) columns, so there is no
 * need for a separate Mailable class per notification type.
 */
final class NotificationMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $title,
        public readonly ?string $body,
        public readonly ?string $linkUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.notification');
    }
}
