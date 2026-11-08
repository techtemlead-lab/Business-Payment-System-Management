<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;

/**
 * The one branded template every event email shares. It is sent from inside the queued
 * SendEventEmail job, so this class is deliberately not queued itself.
 */
class EventNoticeMail extends Mailable
{
    public function __construct(
        public string $noticeTitle,
        public string $noticeBody,
        public string $recipientName,
        public string $portalUrl,
        string $language,
    ) {
        $this->locale($language);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: Str::squish($this->noticeTitle));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.event-notice', text: 'mail.event-notice-text');
    }
}
