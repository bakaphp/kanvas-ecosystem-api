<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\AgentRuntime\Enums\AgentChannelTokenEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use NeuronAI\Tools\ToolInterface;
use Tests\TestCase;

final class SystemUserAgentSlackDmToolTest extends TestCase
{
    use DatabaseTransactions;

    public function testASlackConnectedSystemAgentGetsTheDmToolByDefault(): void
    {
        $agent = $this->makeAgent();
        $agent->set(AgentChannelTokenEnum::SLACK_BOT_TOKEN->value, 'xoxb-real-token');

        $handler = new SystemUserAgent();
        $handler->setConfiguration($agent, user: auth()->user());

        $this->assertContains('send_slack_direct_message', $this->toolNames($handler->getTools()));
    }

    public function testASystemAgentWithoutSlackDoesNotGetTheDmTool(): void
    {
        $handler = new SystemUserAgent();
        $handler->setConfiguration($this->makeAgent(), user: auth()->user());

        $this->assertNotContains('send_slack_direct_message', $this->toolNames($handler->getTools()));
    }

    public function testEverySystemAgentGetsTheEmailTeammateToolByDefault(): void
    {
        $handler = new SystemUserAgent();
        $handler->setConfiguration($this->makeAgent(), user: auth()->user());

        // Email needs no connector, so it is an unconditional baseline — present even without Slack.
        $this->assertContains('send_email_to_user', $this->toolNames($handler->getTools()));
    }

    private function makeAgent(): Agent
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $type = AgentType::factory()
            ->withAppId($app->getId())
            ->create(['provider' => 'neuron']);

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_type_id' => $type->getId(), 'user_id' => auth()->user()->getId()]);
    }

    /**
     * @param array<int, object> $tools
     * @return list<string>
     */
    private function toolNames(array $tools): array
    {
        return array_values(array_map(
            fn (ToolInterface $tool): string => $tool->getName(),
            array_filter($tools, fn (object $tool): bool => $tool instanceof ToolInterface),
        ));
    }
}
