<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Apps;

use Kanvas\Apps\Models\Apps;
use Tests\TestCase;

final class EcosystemTest extends TestCase
{
    /**
     * Test hello of ecosystem.
     *
     * @return void
     */
    public function testHealEndpoint(): void
    {
        $this->get('/v1')->assertStatus(200);
    }
}
