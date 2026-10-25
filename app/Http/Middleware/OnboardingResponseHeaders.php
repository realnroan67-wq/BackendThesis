<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OnboardingResponseHeaders
{
    public static function matches(Request $request): bool
    {
        return $request->is('register', 'email/verification-notification', 'api/v1/auth/email/verify/*', 'api/v1/onboarding', 'api/v1/provider-applications', 'api/v1/admin/provider-applications', 'api/v1/admin/provider-applications/*', 'api/v1/admin/password-confirmation');
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (self::matches($request)) {
            $response->headers->set('Cache-Control', 'no-store');
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }

        return $response;
    }
}
