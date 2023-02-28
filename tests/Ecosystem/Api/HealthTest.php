<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Apps;

use Kanvas\Apps\Models\Apps;
use Tests\TestCase;

final class HealthTest extends TestCase
{
    /**
     * Test health check endpoint.
     *
     * @return void
     */
    public function testHealEndpoint(): void
    {
        $this->get('/v1/status')->assertStatus(503);
    }
}
