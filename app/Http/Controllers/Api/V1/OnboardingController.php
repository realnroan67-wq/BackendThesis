<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AccountEligibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = User::query()->findOrFail($request->user()->id);
        $application = $user->providerApplication;

        return response()->json(['data' => [
            'id' => $user->id, 'onboarding_origin' => $user->onboarding_origin,
            'email_verified' => $user->hasVerifiedEmail(),
            'can_access_business_area' => AccountEligibility::allows($user, UserRole::BUSINESS_USER),
            'can_access_provider_area' => AccountEligibility::allows($user, UserRole::PROPERTY_PROVIDER),
            'application' => $application === null ? null : [
                'id' => $application->id, 'status' => $application->status,
                'submitted_at' => $application->submitted_at?->toIso8601String(),
                'decided_at' => $application->decided_at?->toIso8601String(), 'public_reason' => $application->public_reason,
            ],
        ]]);
    }
}
