<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;
use Kanvas\Connectors\Google\Services\MapStaticApiService;
use Kanvas\Connectors\SightEngine\Services\ContentModerationService;

class IndexController extends BaseController
{
    /**
     * Welcome to Kanvas
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        // $imageUrl = "https://s3.amazonaws.com/mc-canvas/8cCzR0Oq1wkwwA68JM5OwLST5JaOGX3YpPTYnozq.jpg";
        $imageContentModerationService = (new ContentModerationService())->scanText("Im naked, come and contact me");

        $imageUrl = MapStaticApiService::getImageFromCoordinates(18.463449, -66.117866);

        return response()->json($imageUrl);
    }
}
