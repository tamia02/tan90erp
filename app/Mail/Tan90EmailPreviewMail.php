<?php

namespace App\Mail;

use App\Support\DeploymentIdentity;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class Tan90EmailPreviewMail extends Mailable
{
    use Queueable, SerializesModels;

    public function envelope(): Envelope
    {
        return new Envelope(subject: DeploymentIdentity::mailSubject('Tan90 ERP · Email design preview'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.auth.preview');
    }
}
