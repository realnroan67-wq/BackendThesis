<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Development readiness only: application response plus a real database query.
 * Never expose connection settings or exceptions, including when APP_DEBUG=true.
 * Authentication and inquiry persistence are separate, unimplemented milestones.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('SELECT 1');

            return response()->json([
                'status' => 'ok',
                'service' => 'horizon-realty-api',
                'database' => 'connected',
            ])->header('Cache-Control', 'no-store');
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => 'unavailable',
                'service' => 'horizon-realty-api',
                'database' => 'unavailable',
            ], 503)->header('Cache-Control', 'no-store');
        }
    }
}
