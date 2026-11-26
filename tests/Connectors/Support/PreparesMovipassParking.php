<?php

declare(strict_types=1);

namespace Tests\Connectors\Support;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Handlers\MovipassHandler;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\Event\Concerns\BuildsWalkInParking;

trait PreparesMovipassParking
{
    use BuildsWalkInParking;
    use HasIntegrationCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareMovipassParking();
    }

    private function prepareMovipassParking(): void
    {
        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Movipass integration tests are skipped in CI');
        }

        $this->prepareWalkInContext();
        $this->setIntegration(
            $this->apps,
            IntegrationsEnum::MOVIPASS,
            MovipassHandler::class,
            $this->company,
            $this->user
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
