<?php

namespace App\Mail;

use App\Support\DeploymentIdentity;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $resetUrl,
        public int $expiresInMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: DeploymentIdentity::mailSubject('Reset your Tan90 ERP password'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.auth.password-reset');
    }
}
