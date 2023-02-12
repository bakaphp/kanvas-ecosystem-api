<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;

class IndexController extends BaseController
{
    /**
     * Index.
     *
     * @method GET
     * @url /
     *
     * @return Response
     */
    public function index(): JsonResponse
    {
        return response()->json(['Woot Kanvas Ecosystem']);
    }

    /**
     * Show the status of the different services.
     *
     * @method GET
     * @url /status
     *
     * @return Response
     */
    public function status(): JsonResponse
    {
        return response()->json(['Ok']);
    }
}
