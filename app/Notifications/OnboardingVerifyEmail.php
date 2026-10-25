<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\URL;

/** Generate at delivery time from the configured backend origin, never a request Host or client URL. */
class OnboardingVerifyEmail extends VerifyEmail
{
    protected function verificationUrl(mixed $notifiable): string
    {
        $origin = rtrim(config('app.url'), '/');
        $parts = parse_url($origin);
        if (! isset($parts['host'], $parts['scheme']) || ! in_array($parts['scheme'], ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || ! empty($parts['path']) || isset($parts['query']) || isset($parts['fragment'])
            || (app()->environment('production') && $parts['scheme'] !== 'https')) {
            throw new \RuntimeException('Invalid verification origin.');
        }
        $url = clone app('url');
        $url->forceRootUrl($origin);
        $url->forceScheme($parts['scheme']);

        return $url->temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $notifiable->getKey(), 'hash' => sha1($notifiable->getEmailForVerification()),
        ]);
    }
}
