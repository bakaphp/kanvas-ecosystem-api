<?php

declare(strict_types=1);

namespace Tests\Guild\Integration;

use Tests\TestCase;

final class GuildDailyLeadsDigestCommandTest extends TestCase
{
    public function testItIgnoresUnknownAppAndCompanyFilters(): void
    {
        $this->artisan('kanvas-guild:daily-leads-digest', [
            '--app_id' => 2147483647,
            '--company_id' => 2147483647,
            '--dry-run' => true,
        ])
            ->expectsOutput('Daily leads digest: 0 processed, 0 failed.')
            ->assertSuccessful();
    }
}
