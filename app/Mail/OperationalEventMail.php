<?php

namespace App\Mail;

use App\Support\DeploymentIdentity;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OperationalEventMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $eventSubject,
        public readonly string $summary,
        public readonly string $actionUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: DeploymentIdentity::mailSubject($this->eventSubject));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.operational-event');
    }

    public static function forEvent(string $subject, string $summary, string $actionUrl): self
    {
        return new self($subject, $summary, $actionUrl);
    }
}
