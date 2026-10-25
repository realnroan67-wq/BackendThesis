<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CurrentUserController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('view', $request->user());

        return (new UserResource($request->user()))->response()->header('Cache-Control', 'no-store');
    }
}
