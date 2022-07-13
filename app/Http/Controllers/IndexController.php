<?php

namespace Kanvas\Http\Controllers;

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
    public function index() : JsonResponse
    {
        return response()->json(['Woot Kanvas']);
    }

    /**
     * Show the status of the different services.
     *
     * @method GET
     * @url /status
     *
     * @return Response
     */
    public function status() : JsonResponse
    {
        return parent::status();
    }
}
