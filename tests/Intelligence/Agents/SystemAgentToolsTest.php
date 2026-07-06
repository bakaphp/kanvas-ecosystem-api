<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\ReadMyLedgerTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\ReadUserActivityTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\WhoIsUserTool;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
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

    private function emitOnEntity(Apps $app, Companies $company, int $actorId, Model $entity, string $eventType): void
    {
        new AppendEventAction(
            new EventData(
                app: $app,
                company: $company,
                sourceDomain: 'NervousSystem',
                eventType: $eventType,
                status: EventStatusEnum::INFO,
                sourceEntityType: $entity::class,
                sourceEntityId: (int) $entity->getKey(),
                actorType: 'User',
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

    public function testWhoIsUserResolvesATeammateByHandle(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $handle = 'kaioken-' . $user->getId();
        UsersAssociatedApps::where('users_id', $user->getId())
            ->where('apps_id', $app->getId())
            ->update(['displayname' => $handle]);

        // A leading @ must be tolerated — that's how people type a handle.
        $result = new WhoIsUserTool($app, $company, null)->__invoke(handle: '@' . $handle);

        $this->assertSame($user->getId(), $result['id']);
        $this->assertSame($user->email, $result['email']);
    }

    public function testWhoIsUserRejectsUnknownHandle(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $result = new WhoIsUserTool($app, $company, null)->__invoke(handle: 'nobody-xyz-404');

        $this->assertSame('error', $result['status']);
    }

    public function testReadUserActivityScopesToThisRecordAndUser(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();
        $otherLead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();
        $alice = Users::factory()->create();
        $bob = Users::factory()->create();

        $type = 'acttest-' . substr(uniqid(), -8) . '.';

        $this->emitOnEntity($app, $company, $alice->getId(), $lead, $type . 'noted');
        $this->emitOnEntity($app, $company, $alice->getId(), $lead, $type . 'called');
        $this->emitOnEntity($app, $company, $alice->getId(), $otherLead, $type . 'noted');
        $this->emitOnEntity($app, $company, $bob->getId(), $lead, $type . 'noted');

        $result = new ReadUserActivityTool($app, $company, $lead)->__invoke(user_id: $alice->getId());

        $this->assertSame(2, $result['count'], 'Only Alice actions on THIS lead — not other leads, not other users');
    }

    public function testReadUserActivityRequiresARecordInScope(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $result = new ReadUserActivityTool($app, $company, null)->__invoke(user_id: 1);

        $this->assertSame('error', $result['status']);
    }
}
