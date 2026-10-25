<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\RegistrationService;
use Illuminate\Http\JsonResponse;

class RegistrationController extends Controller
{
    public function __invoke(RegisterRequest $request, RegistrationService $service): JsonResponse
    {
        $service->register($request->payload());

        return response()->json([
            'message' => 'If this request can be processed, check your email or sign in to continue.',
            'code' => 'REGISTRATION_REQUEST_ACCEPTED',
        ], 202);
    }
}
