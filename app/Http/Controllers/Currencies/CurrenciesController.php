<?php

declare(strict_types=1);

namespace App\Http\Controllers\Currencies;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Kanvas\Currencies\DataTransferObject\CollectionResponseData;
use Kanvas\Currencies\DataTransferObject\SingleResponseData;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Enums\HttpDefaults;

class CurrenciesController extends BaseController
{
    /**
     * Fetch all countries.
     *
     * @return JsonResponse
     */
    public function index() : JsonResponse
    {
        $limit = HttpDefaults::RECORDS_PER_PAGE;
        $results = Currencies::paginate($limit->getValue());
        $collection = CollectionResponseData::fromModelCollection($results);

        return response()->json($collection->formatResponse());
    }

    /**
     * Fetch specific country.
     *
     * @return JsonResponse
     */
    public function show(int $id) : JsonResponse
    {
        $country = Currencies::findOrFail($id);
        $response = SingleResponseData::fromModel($country);
        return response()->json($response);
    }
}
