<?php

declare(strict_types=1);

namespace Tests\Connectors\Acumatica;

use Tests\TestCase;

class BackfillAcumaticaCommandTest extends TestCase
{
    public function test_no_targets_is_a_graceful_no_op(): void
    {
        $this->artisan('kanvas:acumatica-backfill')
            ->expectsOutputToContain('No backfill targets')
            ->assertExitCode(0);
    }

    public function test_explicit_company_requires_app_id(): void
    {
        $this->artisan('kanvas:acumatica-backfill', ['--company_id' => 999999])
            ->expectsOutputToContain('--company_id requires --app_id')
            ->assertExitCode(0);
    }
}
