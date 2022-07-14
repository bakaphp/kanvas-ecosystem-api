<?php

declare(strict_types=1);

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\BaseController;
use Illuminate\Http\Response;
use Kanvas\Apps\Models\Settings;

class SettingsController extends BaseController
{
    /**
     * Fetch all apps.
     *
     * @return Response
     */
    public function index() : Response
    {
        return response(Settings::all());
    }
}
