<?php

declare(strict_types=1);

namespace App\Http\Controllers\Locations;

use Illuminate\Http\Request;
use Kanvas\Locations\States\Models\States;
use Kanvas\Locations\States\DataTransferObject\SingleResponseData;
use Kanvas\Locations\States\DataTransferObject\CollectionResponseData;
use Illuminate\Http\JsonResponse;
use Kanvas\Enums\HttpDefaults;
use Kanvas\Http\Controllers\BaseController;

class StatesController extends BaseController
{
    /**
     * Fetch all states of a country
     *
     * @return JsonResponse
     */
    public function index(int $countriesId): JsonResponse
    {
        $limit = HttpDefaults::RECORDS_PER_PAGE;
        $results = States::where('countries_id', $countriesId)->paginate($limit->getValue());
        $collection = CollectionResponseData::fromModelCollection($results);
        
        return response()->json($collection->formatResponse());
    }
}
