<?php

declare(strict_types=1);

namespace App\Http\Controllers\Locations;

use Illuminate\Http\Request;
use Kanvas\Locations\Countries\Models\Countries;
use Illuminate\Http\JsonResponse;
use DateTimeZone;
use Kanvas\Http\Controllers\BaseController;

class TimezonesController extends BaseController
{
    /**
     * Fetch all countries
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        return response()->json(DateTimeZone::listIdentifiers());
    }
}
