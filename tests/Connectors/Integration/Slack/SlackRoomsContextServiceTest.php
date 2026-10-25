<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Slack;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Slack\Services\SlackRoomsContextService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Users\Models\Users;
use Tests\Stubs\Intelligence\SalesNeuronAgentStub;
use Tests\TestCase;

final class SlackRoomsContextServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'social', 'intelligence'];

    private Apps $kanvasApp;
    private Companies $company;
    private Users $human;
    private Users $agentUser;
    private Agent $agent;
    private string $teamId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->human = auth()->user();
        $this->company = $this->human->getCurrentCompany();
        $this->agentUser = Users::factory()->create();
        $this->teamId = 't' . strtolower(Str::random(8));

        $agentType = AgentType::factory()
            ->withAppId($this->kanvasApp->getId())
            ->create(['provider' => 'neuron', 'handler' => SalesNeuronAgentStub::class]);

        $this->agent = Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create([
                'user_id' => $this->agentUser->getId(),
                'agent_type_id' => $agentType->getId(),
            ]);
    }

    public function testListsTheAgentsRoomsWithTheirIdsAndLeavesOutTheCurrentConversation(): void
    {
        $dm = $this->slackChannel('d' . strtolower(Str::random(8)), $this->human, 'Slack DM');
        $logs = $this->slackChannel('c0b4p1ch1me', $this->agentUser, '#agents-logs');
        $ops = $this->slackChannel('c0ps' . strtolower(Str::random(6)), $this->agentUser, '#ops');

        $context = new SlackRoomsContextService()->render($this->agent, $dm);

        $this->assertNotNull($context);
        $this->assertStringContainsString('#agents-logs (channel_id ' . $logs->getId() . ', Slack C0B4P1CH1ME)', $context);
        $this->assertStringContainsString('#ops (channel_id ' . $ops->getId(), $context);
        $this->assertStringContainsString('read_channel_window', $context);
        $this->assertStringNotContainsString('Slack DM', $context);
    }

    public function testARoomInAnotherWorkspaceOrOwnedBySomeoneElseIsNotListed(): void
    {
        $dm = $this->slackChannel('d' . strtolower(Str::random(8)), $this->human, 'Slack DM');
        $this->slackChannel('c' . strtolower(Str::random(8)), $this->human, '#listener-copy');

        $this->slackChannel(
            'c' . strtolower(Str::random(8)),
            $this->agentUser,
            '#elsewhere',
            teamId: 't' . strtolower(Str::random(8)),
        );

        $this->assertNull(new SlackRoomsContextService()->render($this->agent, $dm));
    }

    private function slackChannel(
        string $slackId,
        Users $owner,
        string $name,
        ?string $teamId = null,
    ): Channel {
        return Channel::create([
            'name' => $name,
            'slug' => 'slack-' . ($teamId ?? $this->teamId) . '-' . $slackId,
            'description' => $name,
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => $owner->getId(),
            'entity_namespace' => Users::class,
            'entity_id' => $owner->getId(),
        ]);
    }
}
