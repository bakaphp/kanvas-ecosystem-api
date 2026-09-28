<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Api;

use Tests\TestCase;

final class SanctumCsrfRouteTest extends TestCase
{
    public function testCsrfCookieRouteIsNotRegistered(): void
    {
        $this->assertFalse(
            config('sanctum.routes'),
            'sanctum.routes must be false so the csrf-cookie route is not registered.',
        );

        $response = $this->get('/sanctum/csrf-cookie');

        $response->assertStatus(404);
        $this->assertNotSame(500, $response->getStatusCode());
    }
}
