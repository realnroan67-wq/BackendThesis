<?php

use App\Exceptions\InquiryException;
use App\Exceptions\OnboardingException;
use App\Http\Middleware\OnboardingResponseHeaders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->prepend(OnboardingResponseHeaders::class);
        $middleware->trimStrings(except: [fn (Request $request): bool => $request->is('api/v1/inquiries', 'register', 'api/v1/provider-applications', 'api/v1/admin/provider-applications/*', 'api/v1/admin/password-confirmation')]);
        $middleware->convertEmptyStringsToNull(except: [fn (Request $request): bool => $request->is('api/v1/inquiries', 'register', 'api/v1/provider-applications', 'api/v1/admin/provider-applications/*', 'api/v1/admin/password-confirmation')]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (OnboardingResponseHeaders::matches($request)) {
                $status = match (true) {
                    $exception instanceof AuthenticationException => 401,
                    $exception instanceof AuthorizationException => 403,
                    $exception instanceof ValidationException => 422,
                    $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                    default => 500,
                };
                $codes = [401 => 'UNAUTHENTICATED', 403 => 'FORBIDDEN', 404 => 'NOT_FOUND', 405 => 'METHOD_NOT_ALLOWED', 419 => 'CSRF_TOKEN_MISMATCH', 422 => 'VALIDATION_ERROR', 429 => 'RATE_LIMITED'];
                $messages = [401 => 'Unauthenticated.', 403 => 'Action forbidden.', 404 => 'Resource unavailable.', 405 => 'Method not allowed.', 419 => 'CSRF token mismatch.', 422 => 'The registration details are invalid.', 429 => 'Too many requests.'];
                $body = ['message' => $exception instanceof OnboardingException ? $exception->getMessage() : ($messages[$status] ?? 'Registration is temporarily unavailable.'),
                    'code' => $exception instanceof OnboardingException ? $exception->errorCode : ($codes[$status] ?? 'REGISTRATION_UNAVAILABLE')];
                if ($exception instanceof ValidationException) {
                    $body['errors'] = array_intersect_key($exception->errors(), array_flip(['payload', 'name', 'email', 'password', 'password_confirmation', 'account_type', 'business_name', 'business_location', 'application_message', 'decision', 'public_reason', 'status', 'page', 'per_page']));
                }
                $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];

                return response()->json($body, $status, $headers)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
            }

            if ($request->is('api/v1/properties', 'api/v1/properties/*')) {
                $status = match (true) {
                    $exception instanceof ValidationException => 422,
                    $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                    default => 500,
                };
                $codes = [404 => 'PROPERTY_NOT_FOUND', 405 => 'METHOD_NOT_ALLOWED', 422 => 'VALIDATION_ERROR'];
                $messages = [404 => 'Property not found.', 405 => 'Method not allowed.', 422 => 'The discovery parameters are invalid.'];
                $body = ['message' => $messages[$status] ?? 'Unable to load properties.', 'code' => $codes[$status] ?? 'SERVER_ERROR'];
                if ($status === 422 && $exception instanceof ValidationException) {
                    $body['errors'] = $exception->errors();
                }
                $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];

                return response()->json($body, $status, $headers)->header('Cache-Control', 'no-store');
            }
            if (! $request->is('api/v1/inquiries', 'api/v1/inquiries/*', 'api/v1/provider/inquiries', 'api/v1/provider/inquiries/*')) {
                return null;
            }
            $status = match (true) {
                $exception instanceof AuthenticationException => 401,
                $exception instanceof AuthorizationException => 403,
                $exception instanceof ValidationException => 422,
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                default => 500,
            };
            if ($status === 419 && (! $request->hasSession() || ! $request->session()->has(Auth::guard('web')->getName()))) {
                $status = 401;
            }
            $codes = [401 => 'UNAUTHENTICATED', 403 => 'FORBIDDEN', 404 => 'INQUIRY_NOT_FOUND', 405 => 'METHOD_NOT_ALLOWED', 419 => 'CSRF_TOKEN_MISMATCH', 422 => 'VALIDATION_ERROR', 429 => 'RATE_LIMITED'];
            $code = $exception instanceof InquiryException ? $exception->errorCode : ($codes[$status] ?? 'SERVER_ERROR');
            $messages = [401 => 'Unauthenticated.', 403 => 'Action forbidden.', 404 => 'Resource unavailable.', 405 => 'Method not allowed.', 419 => 'CSRF token mismatch.', 422 => 'The inquiry details are invalid.', 429 => 'Too many requests.'];
            $body = ['message' => $exception instanceof InquiryException ? $exception->getMessage() : ($messages[$status] ?? 'Unable to process the inquiry.'), 'code' => $code];
            if ($status === 422) {
                $body['errors'] = $exception instanceof ValidationException ? $exception->errors() : ['payload' => ['The inquiry details are invalid.']];
            }
            $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];

            return response()->json($body, $status, $headers)->header('Cache-Control', 'no-store');
        });
        $exceptions->dontReportWhen(fn (Throwable $exception): bool => OnboardingResponseHeaders::matches(request()));
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'login', 'logout', 'sanctum/csrf-cookie') || $request->expectsJson(),
        );
    })->create();
