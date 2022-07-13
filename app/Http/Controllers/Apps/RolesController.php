<?php

declare(strict_types=1);

namespace Kanvas\Http\Controllers\Apps;

use Illuminate\Http\Request;
use Kanvas\Apps\Models\Roles;
use Illuminate\Http\Response;
use Kanvas\Http\Controllers\BaseController;

class RolesController extends BaseController
{
    /**
     * Fetch all apps
     *
     * @return Response
     */
    public function index(): Response
    {
        return response(Roles::all());
    }
}
