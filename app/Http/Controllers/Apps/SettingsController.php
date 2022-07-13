<?php

declare(strict_types=1);

namespace Kanvas\Http\Controllers\Apps;

use Illuminate\Http\Request;
use Kanvas\Apps\Models\Settings;
use Illuminate\Http\Response;
use Kanvas\Http\Controllers\BaseController;

class SettingsController extends BaseController
{
    /**
     * Fetch all apps
     *
     * @return Response
     */
    public function index(): Response
    {
        return response(Settings::all());
    }
}
