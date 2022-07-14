<?php

declare(strict_types=1);

namespace App\Http\Controllers\Locations;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Kanvas\Enums\HttpDefaults;
use Kanvas\Locations\States\DataTransferObject\CollectionResponseData;
use Kanvas\Locations\States\Models\States;

class StatesController extends BaseController
{
    /**
     * Fetch all states of a country.
     *
     * @return JsonResponse
     */
    public function index(int $countriesId) : JsonResponse
    {
        $limit = HttpDefaults::RECORDS_PER_PAGE;
        $results = States::where('countries_id', $countriesId)->paginate($limit->getValue());
        $collection = CollectionResponseData::fromModelCollection($results);

        return response()->json($collection->formatResponse());
    }
}
