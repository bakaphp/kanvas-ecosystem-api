<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;

class IndexController extends BaseController
{
    /**
     * Welcome to Kanvas
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        return response()->json(['Woot Kanvas Ecosystem']);
    }
}
