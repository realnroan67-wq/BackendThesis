<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\OnboardingException;
use App\Http\Controllers\Controller;
use App\Services\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function resend(Request $request, EmailVerificationService $service): JsonResponse
    {
        $service->resend($request->user('web'));

        return response()->json(['message' => 'If needed, a verification email will be requested.', 'code' => 'VERIFICATION_REQUEST_ACCEPTED'], 202);
    }

    public function verify(Request $request, string $id, string $hash, EmailVerificationService $service): JsonResponse
    {
        if (rtrim($request->getSchemeAndHttpHost(), '/') !== rtrim(config('app.url'), '/') || ! $request->hasValidSignature()) {
            throw new OnboardingException(403, 'VERIFICATION_LINK_INVALID', 'The verification link is invalid or expired.');
        }
        $service->verify($request->user('web'), $id, $hash);

        return response()->json(['message' => 'Email verified.', 'code' => 'EMAIL_VERIFIED']);
    }
}
