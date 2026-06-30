<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Inventory\Regions\Enums\ConfigurationEnum;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Regions\Services\RegionResolutionService;

class RegionMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $app = app(Apps::class);
        $regionHeaderKey = AppEnums::KANVAS_APP_REGION_HEADER->getValue();

        // Layer 1: X-Kanvas-Region header (existing behavior)
        if ($request->hasHeader($regionHeaderKey)) {
            $region = Regions::getByUuid($request->header($regionHeaderKey), $app);
            app()->scoped(Regions::class, fn () => $region);
        }

        // Layers 2-4: Fallback chain (only if header didn't bind and app has region config)
        if (
            ! app()->bound(Regions::class)
            && ! empty($app->get(ConfigurationEnum::REGION_COUNTRY_MAP->value))
        ) {
            $user = auth()->user();
            $region = new RegionResolutionService($app)->resolve($request, $user);
            if ($region) {
                app()->scoped(Regions::class, fn () => $region);
            }
        }

        $response = $next($request);

        // Set response header when region was auto-detected (not from request header)
        if (! $request->hasHeader($regionHeaderKey) && app()->bound(Regions::class)) {
            $response->headers->set('X-Kanvas-Region-Resolved', app(Regions::class)->uuid);
        }

        return $response;
    }
}
