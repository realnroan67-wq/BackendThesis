<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListInquiriesRequest;
use App\Http\Requests\StoreInquiryRequest;
use App\Http\Requests\UpdateInquiryStatusRequest;
use App\Http\Resources\InquiryResource;
use App\Models\Inquiry;
use App\Services\InquiryService;
use App\Services\InquiryStatusService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class InquiryController extends Controller
{
    /** Sender history is independent of current property ownership/publication. */
    public function index(ListInquiriesRequest $request): JsonResponse
    {
        return $this->listing($request, Inquiry::query()->visibleTo($request->user())
            ->where('business_user_id', $request->user()->getKey()));
    }

    /** Only current active grants, never historical recipient or current owner IDs. */
    public function providerIndex(ListInquiriesRequest $request): JsonResponse
    {
        return $this->listing($request, Inquiry::query()->forProvider($request->user()));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        Gate::authorize('viewAny', Inquiry::class);
        if ($request->query->all() !== []) {
            throw ValidationException::withMessages(['query' => ['Inquiry details do not support query parameters.']]);
        }
        abort_unless(preg_match('/\A[1-9][0-9]{0,15}\z/', $id) === 1
            && (strlen($id) < 16 || strcmp($id, '9007199254740991') <= 0), 404);
        $inquiry = Inquiry::query()->visibleTo($request->user())->findOrFail($id);
        abort_unless(Gate::allows('view', $inquiry), 404);

        return (new InquiryResource($inquiry))->response()->header('Cache-Control', 'no-store');
    }

    /** Authorization and filters precede both the count and the bounded page query. */
    private function listing(ListInquiriesRequest $request, Builder $query): JsonResponse
    {
        $parameters = $request->validated();
        $perPage = (int) ($parameters['per_page'] ?? 20);
        $page = (int) ($parameters['page'] ?? 1);
        if (isset($parameters['status'])) {
            $query->where('status', $parameters['status']);
        }
        $total = (clone $query)->count();
        $lastPage = $total === 0 ? 1 : intdiv($total - 1, $perPage) + 1;
        // Avoid overflowing OFFSET arithmetic for valid but out-of-range page numbers.
        $records = $page <= $lastPage
            ? $query->orderByDesc('created_at')->orderByDesc('id')->offset(($page - 1) * $perPage)->limit($perPage)->get()
            : collect();

        return response()->json([
            'data' => InquiryResource::collection($records)->resolve($request),
            'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => $lastPage],
        ])->header('Cache-Control', 'no-store');
    }

    public function updateStatus(UpdateInquiryStatusRequest $request, string $id, InquiryStatusService $service): JsonResponse
    {
        $result = $service->close($request->user(), $id, $request->validated('status'));

        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }

    public function store(StoreInquiryRequest $request, InquiryService $service): Response
    {
        $result = $service->create($request->user(), $request->payload(), $request->idempotencyKey());
        $response = response($result['body'], 201, [
            'Content-Type' => 'application/json', 'Location' => $result['location'], 'Cache-Control' => 'no-store',
        ]);
        if ($result['replayed']) {
            $response->header('Idempotency-Replayed', 'true');
        }

        return $response;
    }
}
