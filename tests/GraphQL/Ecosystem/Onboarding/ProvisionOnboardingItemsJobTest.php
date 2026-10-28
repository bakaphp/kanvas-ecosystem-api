<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Onboarding;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Onboarding\Actions\SelectOnboardingAreasAction;
use Kanvas\Companies\Onboarding\Enums\OnboardingStatusEnum;
use Kanvas\Companies\Onboarding\Jobs\ProvisionOnboardingItemsJob;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Companies\Onboarding\Models\OnboardingSection;
use Kanvas\Companies\Onboarding\Services\OnboardingStateService;
use Kanvas\Connectors\Mailgun\Jobs\ProvisionAgentMailboxJob;
use Kanvas\Connectors\Mailgun\Services\AgentMailboxService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Users\Models\Users;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

// Template agents live at apps_id = 0, a catalog every paratest process reads.
#[Group('serial')]
final class ProvisionOnboardingItemsJobTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem', 'intelligence'];

    private string $prefix;
    private Apps $kanvasApp;
    private Companies $company;
    private Users $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'p' . substr(uniqid(), -6) . '_';
        $this->kanvasApp = app(Apps::class);
        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();

        $this->template(0, 'Ginny');
        $this->template(0, $this->named('Sally'));
        $this->template(0, $this->named('Accounts Payable'));

        $this->section('sales', 1, [
            $this->agentItem('sally', 'Sally'),
        ], [
            ['slug' => 'approvals', 'name' => 'Approvals', 'description' => 'Approve things'],
        ]);
        $this->section('accounts_payable', 2, [
            $this->agentItem('accounts_payable', 'Accounts Payable') + ['provision_mailbox' => true],
        ]);
    }

    public function testProvisionsGinnyThenEveryItemInOrderAndMarksTeamReady(): void
    {
        $row = $this->select(['sales']);

        $this->runJob();

        $row->refresh();
        $provisioning = $row->dataGet('provisioning');
        $this->assertSame(['ginny', 'sally', 'approvals'], array_keys($provisioning));
        $this->assertSame('ready', $provisioning['ginny']['status']);
        $this->assertSame('ready', $provisioning['sally']['status']);
        $this->assertSame('ready', $provisioning['approvals']['status']);
        $this->assertNull($provisioning['approvals']['agent_id']);

        $sally = Agent::query()->whereKey($provisioning['sally']['agent_id'])->firstOrFail();
        $this->assertSame($this->company->getId(), (int) $sally->companies_id);
        $this->assertSame($this->kanvasApp->getId(), (int) $sally->apps_id);
        $this->assertSame('team_ready', $row->status);

        $event = Event::query()
            ->where('event_type', 'onboarding.provisioned')
            ->where('source_entity_id', $row->getId())
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($event);
        $this->assertSame(3, $event->payload['ready']);
        $this->assertSame(0, $event->payload['failed']);

        $items = collect(new OnboardingStateService()->state($row)->items)->keyBy(fn (array $item): string => $item['template']['slug']);
        $this->assertSame('READY', $items['sally']['status']);
        $this->assertTrue($items['sally']['ready']);
        $this->assertSame($sally->getId(), $items['sally']['agent']->getId());
    }

    public function testTemplateFromAppZeroWhenAppHasNoneAndAppWinsWhenBothExist(): void
    {
        $this->template(0, $this->named('Only Global'), 'from app 0');
        $this->template(0, $this->named('Both'), 'from app 0');
        $this->template($this->kanvasApp->getId(), $this->named('Both'), 'from the app');
        $this->section('mixed', 3, [
            $this->agentItem('only_global', 'Only Global'),
            $this->agentItem('both', 'Both'),
        ]);
        $this->select(['mixed']);

        $this->runJob();

        $this->assertSame('from app 0', $this->companyAgent('Only Global')->description);
        $this->assertSame('from the app', $this->companyAgent('Both')->description);
    }

    public function testMissingTemplateFailsOnlyThatItem(): void
    {
        $this->section('broken', 3, [
            $this->agentItem('nobody', 'Nobody'),
            $this->agentItem('sally_again', 'Sally'),
        ]);
        $row = $this->select(['broken']);

        $this->runJob();

        $provisioning = $row->refresh()->dataGet('provisioning');
        $this->assertSame('failed', $provisioning['nobody']['status']);
        $this->assertStringContainsString($this->named('Nobody'), $provisioning['nobody']['error']);
        $this->assertSame('ready', $provisioning['sally_again']['status']);
        $this->assertSame('team_ready', $row->status);

        $event = Event::query()
            ->where('event_type', 'onboarding.provisioned')
            ->where('source_entity_id', $row->getId())
            ->orderByDesc('id')
            ->firstOrFail();
        $this->assertSame(1, $event->payload['failed']);
    }

    public function testRunningTwiceCreatesNoSecondAgent(): void
    {
        $row = $this->select(['sales']);

        $this->runJob();
        $first = $row->refresh()->dataGet('provisioning.sally.agent_id');
        // Other suites leave committed company agents behind, so the count is relative, not absolute.
        $sallies = $this->companyAgents('Sally')->count();
        $ginnies = $this->companyAgents('Ginny')->count();
        $this->runJob();

        $this->assertSame($first, $row->refresh()->dataGet('provisioning.sally.agent_id'));
        $this->assertSame($sallies, $this->companyAgents('Sally')->count());
        $this->assertSame($ginnies, $this->companyAgents('Ginny')->count());
    }

    public function testDeselectedAreaLeavesItsAgentsUntouched(): void
    {
        $row = $this->select(['sales']);
        $this->runJob();
        $sally = $this->companyAgent('Sally');

        $this->select(['accounts_payable']);
        $this->runJob();

        $sally->refresh();
        $this->assertTrue((bool) $sally->is_active);
        $this->assertFalse((bool) $sally->is_deleted);

        $slugs = array_column(array_column(new OnboardingStateService()->state($row->refresh())->items, 'template'), 'slug');
        $this->assertSame(['accounts_payable'], $slugs);
        $this->assertSame('ready', $row->dataGet('provisioning.sally.status'));
    }

    public function testMailboxJobDispatchedForFlaggedAgentsOnly(): void
    {
        Queue::fake();
        $mailboxes = Mockery::mock(AgentMailboxService::class);
        $mailboxes->shouldReceive('isConfiguredFor')->andReturn(true);
        $mailboxes->shouldReceive('hasMailbox')->andReturn(false);
        $this->app->instance(AgentMailboxService::class, $mailboxes);

        $this->select(['sales', 'accounts_payable']);
        $this->runJob();

        $ap = $this->companyAgent('Accounts Payable');
        Queue::assertPushed(
            ProvisionAgentMailboxJob::class,
            fn (ProvisionAgentMailboxJob $job): bool => $job->agent->getId() === $ap->getId(),
        );
        Queue::assertNotPushed(
            ProvisionAgentMailboxJob::class,
            fn (ProvisionAgentMailboxJob $job): bool => $job->agent->getId() !== $ap->getId(),
        );
    }

    public function testJobCallsOverwriteAppService(): void
    {
        $this->select(['sales']);
        $other = clone $this->kanvasApp;
        $other->id = $this->kanvasApp->getId() + 100000;
        App::scoped(Apps::class, fn (): Apps => $other);
        $this->assertSame($other->id, app(Apps::class)->id);

        $this->runJob();

        $this->assertSame($this->kanvasApp->getId(), app(Apps::class)->getId());
    }

    public function testProvisionMutationMarksWaitingAndDispatchesTheJob(): void
    {
        Queue::fake();
        $this->select(['sales']);

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation { provisionOnboardingItems { status items { template { slug } status } } }
        ');

        $data = $response->json('data.provisionOnboardingItems');
        $this->assertNotNull($data, json_encode($response->json()));
        $this->assertSame('PROVISIONING', $data['status']);
        $this->assertSame(['WAITING', 'WAITING'], array_column($data['items'], 'status'));

        $row = CompanyOnboarding::forCompany($this->kanvasApp, $this->company);
        $this->assertSame('waiting', $row->dataGet('provisioning.ginny.status'));
        $this->assertSame('waiting', $row->dataGet('provisioning.sally.status'));

        Queue::assertPushed(
            ProvisionOnboardingItemsJob::class,
            fn (ProvisionOnboardingItemsJob $job): bool => $job->queue === 'onboarding'
                && $job->company->getId() === $this->company->getId()
                && $job->app->getId() === $this->kanvasApp->getId(),
        );
    }

    private function runJob(): void
    {
        new ProvisionOnboardingItemsJob($this->kanvasApp, $this->company, $this->user)->handle();
    }

    /**
     * @param list<string> $bases
     */
    private function select(array $bases): CompanyOnboarding
    {
        $row = new OnboardingStateService()->forCompany($this->kanvasApp, $this->company, $this->user);

        return new SelectOnboardingAreasAction($row, $this->user, array_map(fn (string $base): string => $this->prefix . $base, $bases))->execute();
    }

    private function named(string $base): string
    {
        return $base . ' ' . $this->prefix;
    }

    private function template(int $appsId, string $name, string $description = 'template'): Agent
    {
        return Agent::factory()->withAppId($appsId)->withCompanyId(0)->create(['name' => $name, 'description' => $description]);
    }

    private function companyAgent(string $base): Agent
    {
        return $this->companyAgents($base)->firstOrFail();
    }

    private function companyAgents(string $base)
    {
        return Agent::query()
            ->fromApp($this->kanvasApp)
            ->fromCompany($this->company)
            ->where('name', $base === 'Ginny' ? 'Ginny' : $this->named($base))
            ->notDeleted();
    }

    /**
     * @return array<string, mixed>
     */
    private function agentItem(string $slug, string $base): array
    {
        return [
            'slug' => $slug,
            'name' => $this->named($base),
            'description' => 'x',
            'source_agent' => $this->named($base),
            'agent_type' => 'Any',
            'requirements' => [],
        ];
    }

    /**
     * @param list<array<string, mixed>> $agents
     * @param list<array<string, mixed>> $modules
     */
    private function section(string $base, int $order, array $agents, array $modules = []): OnboardingSection
    {
        return OnboardingSection::query()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'slug' => $this->prefix . $base,
            'label' => 'Label ' . $base,
            'title' => 'Title ' . $base,
            'description' => 'Description',
            'is_available' => true,
            'display_order' => $order,
            'is_active' => true,
            'config' => ['agents' => $agents, 'modules' => $modules, 'requirements' => [], 'capabilities' => [], 'setup_steps' => []],
        ]);
    }

    public function testJobFailedHandlerMarksCreatingItemsFailedAndEndsTeamReady(): void
    {
        $row = $this->select(['sales']);
        $row->transitionTo(OnboardingStatusEnum::PROVISIONING);
        $row->dataSet('provisioning.sally', ['status' => 'creating', 'agent_id' => null, 'error' => null]);
        $row->dataSet('provisioning.ginny', ['status' => 'ready', 'agent_id' => 1, 'error' => null]);

        new ProvisionOnboardingItemsJob($this->kanvasApp, $this->company, $this->user)->failed(new RuntimeException('worker died'));

        $row->refresh();
        $this->assertSame('failed', $row->dataGet('provisioning.sally.status'));
        $this->assertStringContainsString('worker died', (string) $row->dataGet('provisioning.sally.error'));
        $this->assertSame('ready', $row->dataGet('provisioning.ginny.status'));
        $this->assertSame('team_ready', $row->status);
    }

    public function testMailboxServiceFailureStaysOnTheItemAndTheRunContinues(): void
    {
        Queue::fake();
        $mailboxes = Mockery::mock(AgentMailboxService::class);
        $mailboxes->shouldReceive('hasMailbox')->andReturn(false);
        $mailboxes->shouldReceive('isConfiguredFor')->andThrow(new RuntimeException('mailgun exploded'));
        $this->app->instance(AgentMailboxService::class, $mailboxes);

        $this->select(['accounts_payable', 'sales']);
        $this->runJob();

        $row = CompanyOnboarding::forCompany($this->kanvasApp, $this->company);
        $this->assertSame('ready', $row->dataGet('provisioning.accounts_payable.status'));
        $this->assertStringContainsString('mailgun exploded', (string) $row->dataGet('provisioning.accounts_payable.error'));
        $this->assertSame('ready', $row->dataGet('provisioning.sally.status'));
        $this->assertSame('team_ready', $row->status);
    }

    public function testProvisionMutationKeepsAnItemThatIsAlreadyCreating(): void
    {
        Queue::fake();
        $row = $this->select(['sales']);
        $row->dataSet('provisioning.sally', ['status' => 'creating', 'agent_id' => null, 'error' => null]);

        $this->graphQL('mutation { provisionOnboardingItems { status } }')->assertSuccessful();

        $this->assertSame('creating', $row->refresh()->dataGet('provisioning.sally.status'));
    }
}
