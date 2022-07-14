<?php

declare(strict_types=1);

namespace App\Http\Controllers\Locations;

use App\Http\Controllers\BaseController;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Kanvas\Locations\Countries\Models\Countries;

class TimezonesController extends BaseController
{
    /**
     * Fetch all countries.
     *
     * @return JsonResponse
     */
    public function index() : JsonResponse
    {
        return response()->json(DateTimeZone::listIdentifiers());
    }
}
