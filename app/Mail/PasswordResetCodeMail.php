<?php

namespace App\Mail;

use App\Support\DeploymentIdentity;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $code,
        public int $expiresInMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: DeploymentIdentity::mailSubject('Your Tan90 password reset code'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.auth.password-reset-code');
    }
}
