<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WelcomeUserMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $userName,
        public readonly string $userEmail,
        public readonly string $plainPassword,
        public readonly string $loginUrl,
        public readonly string $appName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Welcome to {$this->appName} — Your account is ready");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.welcome-user');
    }
}
