<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Inventory\Regions\Models\Regions;

class RegionMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     *
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        $regionHeaderKey = AppEnums::KANVAS_APP_REGION_HEADER->getValue();
        if ($request->hasHeader($regionHeaderKey)) {
            $app = app(Apps::class);
            $region = Regions::getByUuid($request->header($regionHeaderKey), $app);

            app()->scoped(Regions::class, function () use ($region) {
                return $region;
            });
        }

        return $next($request);
    }
}
