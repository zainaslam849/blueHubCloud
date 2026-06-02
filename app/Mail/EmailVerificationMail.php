<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $userName,
        public readonly string $verificationCode,
        public readonly string $appName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Verify your email – {$this->appName}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.verify-email');
    }
}
