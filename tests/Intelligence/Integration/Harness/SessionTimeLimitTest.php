<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OpenCode\Enums\ConfigurationEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Services\SessionCostService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Intelligence\Integration\Harness\Concerns\CreatesTaskSessions;
use Tests\TestCase;

#[Group('serial')]
class SessionTimeLimitTest extends TestCase
{
    use CreatesTaskSessions;
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'social'];

    protected function tearDown(): void
    {
        app(Apps::class)->del(ConfigurationEnum::MAX_SESSION_MINUTES->value);

        parent::tearDown();
    }

    public function testDefaultsToThreeHours(): void
    {
        $app = app(Apps::class);
        $app->del(ConfigurationEnum::MAX_SESSION_MINUTES->value);

        $this->assertSame(180, new SessionCostService()->maxActiveMinutesFor($this->createTaskSession()));
    }

    public function testAppSettingOverridesTheDefault(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::MAX_SESSION_MINUTES->value, '240');

        $this->assertSame(240, new SessionCostService()->maxActiveMinutesFor($this->createTaskSession()));
    }

    public function testNonPositiveSettingFallsBackToTheDefault(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::MAX_SESSION_MINUTES->value, '0');

        $this->assertSame(180, new SessionCostService()->maxActiveMinutesFor($this->createTaskSession()));
    }

    /**
     * Each approved extension is another full block of both limits, not a top-up of whichever one was
     * hit — a run that ran out of time usually needs the budget to go with it.
     */
    public function testEachApprovedExtensionAddsAFullBlockOfTimeAndBudget(): void
    {
        $session = $this->createTaskSession(attributes: ['limit_extensions' => 2]);

        $this->assertSame(540, new SessionCostService()->maxActiveMinutesFor($session));
        $this->assertSame(30.0, new SessionCostService()->capFor($session));
    }
}
