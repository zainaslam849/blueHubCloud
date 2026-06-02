<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

class MailService
{
    /**
     * Send a mailable using the SMTP settings stored in app_settings.
     * Falls back to the default Laravel mailer if settings are not configured.
     */
    public function send(string $to, Mailable $mailable): void
    {
        $settings = AppSetting::query()->first();

        if ($settings && $settings->hasSmtp()) {
            $this->sendViaDynamicSmtp($settings, $to, $mailable);
        } else {
            Mail::to($to)->send($mailable);
        }
    }

    private function sendViaDynamicSmtp(AppSetting $settings, string $to, Mailable $mailable): void
    {
        $fromAddress = $settings->smtp_from_address ?? $settings->smtp_username;
        $fromName    = $settings->smtp_from_name ?? config('app.name');

        // Temporarily override the mail config for this request
        Config::set('mail.mailers.dynamic_smtp', [
            'transport'  => 'smtp',
            'host'       => $settings->smtp_host,
            'port'       => $settings->smtp_port ?? 587,
            'encryption' => $settings->smtp_encryption ?? 'tls',
            'username'   => $settings->smtp_username,
            'password'   => $settings->smtp_password,
            'timeout'    => 15,
        ]);

        Config::set('mail.from.address', $fromAddress);
        Config::set('mail.from.name', $fromName);

        Mail::mailer('dynamic_smtp')->to($to)->send($mailable);
    }
}
