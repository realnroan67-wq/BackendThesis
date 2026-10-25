<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListPropertiesRequest;
use App\Http\Resources\PropertyResource;
use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/** Public discovery is separate from provider-only property management policies. */
class PropertyController extends Controller
{
    private const COLUMNS = ['id', 'title', 'location', 'address', 'address_is_public', 'property_type', 'transaction_type', 'price_amount', 'price_currency', 'price_period', 'area_sqm', 'description', 'features', 'accepts_inquiries'];

    public function index(ListPropertiesRequest $request): JsonResponse
    {
        $parameters = $request->validated();
        $perPage = (int) ($parameters['per_page'] ?? 12);
        $page = (int) ($parameters['page'] ?? 1);
        $properties = Property::query()->publiclyVisible()->with('publicImages:property_id,storage_path,caption,position');
        if (isset($parameters['search'])) {
            $term = $parameters['search'];
            $properties->where(function (Builder $query) use ($term): void {
                foreach (['title', 'location', 'description'] as $column) {
                    $query->orWhereRaw("strpos(lower(coalesce({$column}, '')), lower(?)) > 0", [$term]);
                }
                $query->orWhere(function (Builder $address) use ($term): void {
                    $address->where('address_is_public', true)
                        ->whereRaw("strpos(lower(coalesce(address, '')), lower(?)) > 0", [$term]);
                });
            });
        }
        foreach (['property_type', 'transaction_type'] as $field) {
            if (isset($parameters[$field])) {
                $properties->where($field, $parameters[$field]);
            }
        }
        if (isset($parameters['max_price'])) {
            $properties->where('price_amount', '<=', $parameters['max_price']);
        }
        $sort = $parameters['sort'] ?? 'recommended';
        if (in_array($sort, ['price-low', 'price-high'], true)) {
            $direction = $sort === 'price-low' ? 'ASC' : 'DESC';
            $properties->orderByRaw("price_amount {$direction} NULLS LAST");
        } elseif ($sort === 'name') {
            $properties->orderByRaw('lower(title) ASC');
        }
        $properties = $properties->orderBy('id')
            ->paginate($perPage, self::COLUMNS, 'page', $page)
            ->appends(array_merge($parameters, ['per_page' => $perPage]));

        return PropertyResource::collection($properties)->response()->header('Cache-Control', 'no-store');
    }

    public function show(string $id): JsonResponse
    {
        abort_unless(preg_match('/\A[1-9][0-9]{0,15}\z/', $id) === 1
            && (strlen($id) < 16 || strcmp($id, '9007199254740991') <= 0), 404);
        $property = Property::query()->publiclyVisible()
            ->with('publicImages:property_id,storage_path,caption,position')->select(self::COLUMNS)->findOrFail($id);

        return (new PropertyResource($property))->response()->header('Cache-Control', 'no-store');
    }
}
