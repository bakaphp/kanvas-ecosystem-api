<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\ReadMyLedgerTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\WhoIsUserTool;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class SystemAgentToolsTest extends TestCase
{
    private function makeAgent(): Agent
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();
    }

    private function emit(Agent $agent, string $eventType): void
    {
        $this->emitAs($agent->app, $agent->company, 'Agent', $agent->getId(), $eventType);
    }

    private function emitAs(Apps $app, Companies $company, string $actorType, int $actorId, string $eventType): void
    {
        new AppendEventAction(
            new EventData(
                app: $app,
                company: $company,
                sourceDomain: 'NervousSystem',
                eventType: $eventType,
                status: EventStatusEnum::INFO,
                actorType: $actorType,
                actorId: $actorId,
            ),
        )->execute();
    }

    public function testReadMyLedgerReturnsOnlyThisAgentsEvents(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $mine = $this->makeAgent();
        $other = $this->makeAgent();

        $this->emit($mine, 'engagement.user.nudged');
        $this->emit($mine, 'engagement.user.nudged');
        $this->emit($other, 'engagement.user.nudged');

        $result = new ReadMyLedgerTool($app, $company, $mine)->__invoke(event_type_prefix: 'engagement.');

        $this->assertSame(2, $result['count'], 'The ledger tool must scope to the calling agent only');
    }

    public function testReadMyLedgerIncludesActionsDoneAsTheAgentsDedicatedUser(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $agentUser = Users::factory()->create();
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $agentUser->getId()]);

        $prefix = 'agenttest-' . substr(uniqid(), -8) . '.';

        // One event emitted AS the agent; one raised by a Kanvas record it created as its user.
        $this->emitAs($app, $company, 'Agent', $agent->getId(), $prefix . 'via_agent');
        $this->emitAs($app, $company, 'User', $agentUser->getId(), $prefix . 'via_user');

        $result = new ReadMyLedgerTool($app, $company, $agent)->__invoke(event_type_prefix: $prefix);

        $this->assertSame(
            2,
            $result['count'],
            'Ledger recall must cover both the agent identity and its dedicated user',
        );
    }

    public function testWhoIsUserDescribesTheCurrentUser(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $user = auth()->user();

        $result = new WhoIsUserTool($app, $company, $user)->__invoke();

        $this->assertSame($user->getId(), $result['id']);
        $this->assertSame($user->email, $result['email']);
    }

    public function testWhoIsUserRejectsUnknownUserId(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $result = new WhoIsUserTool($app, $company, null)->__invoke(user_id: 999999999);

        $this->assertSame('error', $result['status']);
    }
}
