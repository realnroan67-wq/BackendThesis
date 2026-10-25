<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProviderApplicationResource;
use App\Services\ProviderApplicationService;
use App\Support\AdministratorConfirmation;
use App\Support\ProviderApplicationValidation;
use App\Support\RegistrationGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProviderApplicationController extends Controller
{
    public function store(Request $request, ProviderApplicationService $service, ProviderApplicationValidation $validation): JsonResponse
    {
        $service->authorizeApplicant($request->user());
        RegistrationGate::ensureEnabled();
        $result = $service->submit($request->user(), $validation->body($request));

        return (new ProviderApplicationResource($result['application']))->response()->setStatusCode($result['created'] ? 201 : 200);
    }

    public function index(Request $request, ProviderApplicationService $service, ProviderApplicationValidation $validation): JsonResponse
    {
        $query = $service->reviewQuery($request->user());
        $params = $validation->query($request->query());
        if (isset($params['status'])) {
            $query->where('status', $params['status']);
        }
        $page = $query->orderByDesc('submitted_at')->orderByDesc('id')->paginate($params['per_page'] ?? 20, ['*'], 'page', $params['page'] ?? 1);

        return response()->json(['data' => ProviderApplicationResource::collection($page->items())->resolve($request), 'meta' => [
            'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(),
        ]]);
    }

    public function show(Request $request, string $id, ProviderApplicationService $service): ProviderApplicationResource
    {
        return new ProviderApplicationResource($service->findForReview($request->user(), $id));
    }

    public function confirm(Request $request, AdministratorConfirmation $confirmation, ProviderApplicationValidation $validation): Response
    {
        $confirmation->authorize($request->user('web'));
        $password = $validation->password($validation->body($request));
        $confirmation->confirm($request->user('web'), $request->session(), $password);

        return response()->noContent();
    }

    public function decision(Request $request, string $id, ProviderApplicationService $service, ProviderApplicationValidation $validation): ProviderApplicationResource
    {
        app(AdministratorConfirmation::class)->ensureFresh($request->user('web'), $request->session());

        return new ProviderApplicationResource($service->decide($request->user('web'), $request->session(), $id, $validation->body($request)));
    }
}
