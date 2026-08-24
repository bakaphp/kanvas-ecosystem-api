<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Api;

use App\Http\Kernel;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class BroadcastAuthRouteTest extends TestCase
{
    public function testBroadcastingAuthRouteIsNotRegistered(): void
    {
        $this->assertNull(
            Route::getRoutes()->getByName('broadcasting.auth'),
            'Broadcast::routes() must not be called — it defaults to the `web` middleware group, which this app does not define.',
        );

        $response = $this->post('/broadcasting/auth');

        $response->assertStatus(404);
        $this->assertNotSame(500, $response->getStatusCode());
    }

    public function testNoRouteDependsOnTheMissingWebMiddlewareGroup(): void
    {
        $groups = app(Kernel::class)->getMiddlewareGroups();

        $offenders = [];

        foreach (Route::getRoutes() as $route) {
            if (in_array('web', $route->gatherMiddleware(), true) && ! isset($groups['web'])) {
                $offenders[] = $route->uri();
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'These routes reference the undefined `web` middleware group and will 500 with "Target class [web] does not exist": ' . implode(', ', $offenders),
        );
    }
}
