<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Memory;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Intelligence\Knowledge\Enums\KnowledgeConfigurationEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Traits\StubsTypesenseCredentials;

/**
 * Serial: app settings (Redis).
 */
#[Group('serial')]
class MemoryRecallInstructionsTest extends TestCase
{
    use DatabaseTransactions;
    use StubsTypesenseCredentials;

    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    private Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->enableAgentMemoryFor($this->kanvasApp);
    }

    protected function tearDown(): void
    {
        $this->restoreAgentMemoryFor($this->kanvasApp);

        parent::tearDown();
    }

    public function testARememberingAgentIsToldToAnswerFromRecallBeforeReReadingTheLedger(): void
    {
        $this->assertStringContainsString(
            'answer from those entries; call read_my_ledger or search again only when they do not cover it',
            $this->instructions()
        );
    }

    public function testTheRecallLineIsAbsentWhenTheAppOptedOut(): void
    {
        $this->kanvasApp->set(KnowledgeConfigurationEnum::AGENT_MEMORY_ENABLED->value, 0);

        $this->assertStringNotContainsString('EXTRA-CONTEXT', $this->instructions());
    }

    private function instructions(): string
    {
        $user = auth()->user();
        $agent = Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create(['user_id' => $user->getId()]);

        $handler = new SystemUserAgent();
        $handler->setConfiguration(agent: $agent, entity: $user, user: $user);

        return $handler->instructions();
    }
}
