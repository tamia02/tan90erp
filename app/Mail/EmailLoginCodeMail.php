<?php

namespace App\Mail;

use App\Support\DeploymentIdentity;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailLoginCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $code,
        public int $expiresInMinutes = 10,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: DeploymentIdentity::mailSubject('Your Tan90 ERP sign-in code'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.auth.login-code');
    }
}
