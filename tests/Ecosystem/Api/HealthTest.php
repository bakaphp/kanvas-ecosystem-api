<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Api;

use Tests\TestCase;

final class HealthTest extends TestCase
{
    /**
     * Test health check endpoint.
     */
    public function testHealEndpoint(): void
    {
        $this->get('/v1/status')->assertStatus(503);
    }
}
