<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Api;

use Tests\TestCase;

final class EcosystemTest extends TestCase
{
    /**
     * Test hello of ecosystem.
     */
    public function testHealEndpoint(): void
    {
        $this->get('/v1')->assertStatus(200);
    }
}
