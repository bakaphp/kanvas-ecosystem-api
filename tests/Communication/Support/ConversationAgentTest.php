<?php

declare(strict_types=1);

namespace Tests\Communication\Support;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Communication\Messages\Enums\ConversationAgentEnum;
use Kanvas\Communication\Messages\Exceptions\ConversationAgentNotFoundException;
use Kanvas\Communication\Support\AutoReplyPolicy;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\TestCase;

final class ConversationAgentTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'intelligence'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentCompany()->del(ConversationAgentEnum::SETTING->value);
        // Agents the shared test company already has would answer for it.
        Agent::query()
            ->fromApp($this->currentApp())
            ->fromCompany($this->currentCompany())
            ->update(['is_deleted' => 1]);
    }

    /**
     * Company settings outlive the test transaction (Redis and the ecosystem connection).
     */
    protected function tearDown(): void
    {
        $this->currentCompany()->del(ConversationAgentEnum::SETTING->value);

        parent::tearDown();
    }

    public function testTheCompanySettingNamesTheAgent(): void
    {
        $this->agentNamed(ConversationAgentEnum::DEFAULT_NAME->value);
        $chosen = $this->agentNamed('Rita');
        $this->currentCompany()->set(ConversationAgentEnum::SETTING->value, $chosen->getId());

        $this->assertSame($chosen->getId(), $this->resolve()->getId());
    }

    public function testWithoutTheSettingTheCompanysSallyAnswers(): void
    {
        $this->agentNamed('Rita');
        $sally = $this->agentNamed(ConversationAgentEnum::DEFAULT_NAME->value);

        $this->assertSame($sally->getId(), $this->resolve()->getId());
    }

    public function testASettingNamingAnotherCompanysAgentIsAFault(): void
    {
        $this->agentNamed(ConversationAgentEnum::DEFAULT_NAME->value);
        $this->currentCompany()->set(ConversationAgentEnum::SETTING->value, PHP_INT_MAX);

        $this->expectException(ConversationAgentNotFoundException::class);

        $this->resolve();
    }

    public function testACompanyWithNoAgentIsAFault(): void
    {
        $this->expectException(ConversationAgentNotFoundException::class);

        $this->resolve();
    }

    private function resolve(): Agent
    {
        return new AutoReplyPolicy()->conversationAgent($this->currentApp(), $this->currentCompany());
    }

    private function agentNamed(string $name): Agent
    {
        $agentType = AgentType::factory()->withAppId($this->currentApp()->getId())->create(['provider' => 'neuron']);

        return Agent::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId($this->currentCompany()->getId())
            ->create(['agent_type_id' => $agentType->getId(), 'name' => $name]);
    }
}
