<?php

declare(strict_types=1);

namespace App\Http\Controllers\Locations;

use Illuminate\Http\Request;
use Kanvas\Locations\Countries\Models\Countries;
use Kanvas\Locations\Countries\DataTransferObject\SingleResponseData;
use Kanvas\Locations\Countries\DataTransferObject\CollectionResponseData;
use Illuminate\Http\JsonResponse;
use Kanvas\Http\Controllers\BaseController;
use Kanvas\Enums\HttpDefaults;

class CountriesController extends BaseController
{
    /**
     * Fetch all countries
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $limit = HttpDefaults::RECORDS_PER_PAGE;
        $results = Countries::paginate($limit->getValue());
        $collection = CollectionResponseData::fromModelCollection($results);

        return response()->json($collection->formatResponse());
    }

    /**
     * Fetch specific country
     *
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $country = Countries::findOrFail($id);
        $response = SingleResponseData::fromModel($country);
        return response()->json($response);
    }
}
