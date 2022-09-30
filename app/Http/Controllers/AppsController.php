<?php

namespace Kanvas\Http\Controllers;

use Illuminate\Http\Request;
use Kanvas\AppsGroup\Models\Apps;

class AppsController extends Controller
{
    /**
     * Fetch all apps
     *
     * @return Response
     */
    public function index()
    {
        return Apps::all();
    }
}
