<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Apps\Models\AppKey;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Support\CompanyScope;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Campaigns\Enums\CampaignStatusEnum;
use Kanvas\Guild\Campaigns\Models\Campaign;
use Kanvas\Guild\Customers\Enums\ContactValidationStatusEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\CRM\SalesAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreatePersonTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\FindPersonTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetBatchHistoryTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetCleanupReportTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetCustomerStatsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ListBouncesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Templates\GetTemplateTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Templates\ListTemplatesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Support\AgentReadAccessCompanies;
use Kanvas\Intelligence\Agents\Support\CrossCompanyReadLedger;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Templates\Models\Templates;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\Tool;
use Tests\Intelligence\Agents\Concerns\BuildsCrossCompanyScopes;
use Tests\TestCase;

/**
 * An agent granted several companies reads across them in one query, never past them, and never
 * writes anywhere but its home company.
 */
class CrossCompanyReadScopeTest extends TestCase
{
    use BuildsCrossCompanyScopes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->human = Users::factory()->create();
    }

    public function testAGrantOutsideTheAppIsRefused(): void
    {
        $home = $this->company();
        $agent = $this->agent($home);

        $this->expectException(ValidationException::class);

        AgentReadAccessCompanies::assertGrantable($agent, $this->human, [999999999]);
    }

    public function testAGrantNeedsTheActorToBeAMemberOfEachCompany(): void
    {
        $home = $this->company();
        $other = $this->company(member: false);
        $agent = $this->agent($home);

        try {
            AgentReadAccessCompanies::assertGrantable($agent, $this->human, [(string) $other->getId()]);
            $this->fail('a non-member granted a company');
        } catch (ValidationException) {
        }

        $other->associateUserApp($this->human, $this->kanvasApp, 1);

        $this->assertSame([$other->getId()], AgentReadAccessCompanies::assertGrantable($agent, $this->human, [(string) $other->getId()]));
    }

    public function testTheEffectiveScopeIsTheGrantIntersectedWithMembershipPlusHome(): void
    {
        $home = $this->company();
        $member = $this->company();
        $stranger = $this->company(member: false);
        $agent = $this->agent($home, [$member->getId(), $stranger->getId()]);

        $scope = AgentReadAccessCompanies::scopeFor($agent, $home, $this->human);

        $this->assertEqualsCanonicalizing([$home->getId(), $member->getId()], $scope->ids);
        $this->assertTrue($scope->isCrossCompany());
        $this->assertSame(['tenant_company_id' => $member->getId(), 'tenant_company' => $member->name], $scope->tenantLabel($member->getId()));
    }

    public function testACustomerFacingAgentAndAnAiIdentityCollapseToHome(): void
    {
        $home = $this->company();
        $member = $this->company();

        $customerFacing = $this->agent($home, [$member->getId()], SalesAgent::class);
        $this->assertSame([$home->getId()], AgentReadAccessCompanies::scopeFor($customerFacing, $home, $this->human)->ids);

        $internal = $this->agent($home, [$member->getId()]);
        $this->assertSame([$home->getId()], AgentReadAccessCompanies::scopeFor($internal, $home, $internal->user)->ids);
        $this->assertEqualsCanonicalizing([$home->getId(), $member->getId()], AgentReadAccessCompanies::scopeFor($internal, $home, $this->human)->ids);
    }

    public function testAScopeIsNeverWidenedByABoundAppKey(): void
    {
        [$home, $member, $stranger] = [$this->company(), $this->company(), $this->company(member: false)];
        $ids = [
            $this->person($home, 'Ana')->getId(),
            $this->person($member, 'Ana')->getId(),
            $this->person($stranger, 'Ana')->getId(),
        ];
        $scope = $this->scope($home, $member);

        app()->instance(AppKey::class, $this->kanvasApp->keys()->first());

        try {
            $read = fn (mixed $company): array => People::query()
                ->fromApp($this->kanvasApp)
                ->fromCompany($company)
                ->whereIn('id', $ids)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $this->assertEqualsCanonicalizing([$ids[0], $ids[1], $ids[2]], $read($home), 'a bare company under an app key is the pre-existing app-wide read');
            $this->assertEqualsCanonicalizing([$ids[0], $ids[1]], $read($scope), 'the scope must win over the app key');
            $this->assertSame([$ids[0]], $read(CompanyScope::homeOnly($this->kanvasApp, $home)), 'a one-company scope must also win');
        } finally {
            app()->forgetInstance(AppKey::class);
        }
    }

    public function testFindPersonReadsAcrossTheScopeAndLabelsEveryRow(): void
    {
        [$home, $member, $stranger] = [$this->company(), $this->company(), $this->company(member: false)];
        $this->person($home, 'Zelda');
        $this->person($member, 'Zelda');
        $this->person($stranger, 'Zelda');

        $result = $this->scoped(new FindPersonTool(), $home, $this->scope($home, $member))
            ->__invoke('Zelda Scope');

        $this->assertSame(2, $result['count']);
        $this->assertEqualsCanonicalizing([$home->name, $member->name], array_column($result['people'], 'tenant_company'));
        $this->assertEqualsCanonicalizing([$home->getId(), $member->getId()], array_column($result['people'], 'tenant_company_id'));
    }

    public function testASingleCompanyScopeAddsNoLabels(): void
    {
        $home = $this->company();
        $this->person($home, 'Yara');

        $result = $this->scoped(new FindPersonTool(), $home, CompanyScope::homeOnly($this->kanvasApp, $home))
            ->__invoke('Yara Scope');

        $this->assertSame(1, $result['count']);
        $this->assertArrayNotHasKey('tenant_company', $result['people'][0]);
    }

    public function testAWriteToolKeepsTheHomeCompany(): void
    {
        [$home, $member] = [$this->company(), $this->company()];
        $email = 'noreply+scope-' . uniqid() . '@kanvas.dev';

        $result = $this->scoped(new CreatePersonTool(), $home, $this->scope($home, $member))
            ->__invoke(firstname: 'Scoped', lastname: 'Writer', email: $email);

        $this->assertArrayHasKey('person_id', $result, json_encode($result));
        $this->assertSame($home->getId(), (int) People::query()->findOrFail($result['person_id'])->companies_id);
    }

    public function testBatchHistoryListsAndResolvesAcrossTheScopeOnly(): void
    {
        [$home, $member, $stranger] = [$this->company(), $this->company(), $this->company(member: false)];
        $this->campaign($home);
        $foreign = $this->campaign($member);
        $outside = $this->campaign($stranger);
        $tool = $this->scoped(new GetBatchHistoryTool(), $home, $this->scope($home, $member));

        $list = $tool();
        $this->assertSame(2, $list['count']);
        $this->assertEqualsCanonicalizing([$home->name, $member->name], array_column($list['campaigns'], 'tenant_company'));
        $this->assertSame([$home->name, $member->name], $list['scope']['tenant_companies']);

        $this->assertSame('success', $tool(campaign_id: $foreign->getId())['status']);
        $this->assertSame('error', $tool(campaign_id: $outside->getId())['status']);
    }

    public function testTemplatesAreVisibleAcrossTheScopeButEditableOnlyAtHome(): void
    {
        [$home, $member, $stranger] = [$this->company(), $this->company(), $this->company(member: false)];
        $prefix = 'scope-' . uniqid();
        $own = $this->template($home, $prefix . '-home');
        $theirs = $this->template($member, $prefix . '-member');
        $this->template($stranger, $prefix . '-stranger');
        $scope = $this->scope($home, $member);

        $rows = $this->scoped(new ListTemplatesTool(), $home, $scope)->__invoke($prefix)['templates'];
        $byId = array_column($rows, null, 'template_id');

        $this->assertEqualsCanonicalizing([$own->getId(), $theirs->getId()], array_keys($byId));
        $this->assertTrue($byId[$own->getId()]['editable_here']);
        $this->assertFalse($byId[$theirs->getId()]['editable_here']);
        $this->assertTrue($byId[$theirs->getId()]['owned'], 'ownership is by user and stays true; editable_here is what says no');

        $detail = $this->scoped(new GetTemplateTool(), $home, $scope)->__invoke($theirs->getId());
        $this->assertSame($member->name, $detail['tenant_company']);
        $this->assertFalse($detail['editable_here']);
    }

    public function testBouncesCarryTheTenantPerRowAndKeepTheEmployer(): void
    {
        [$home, $member] = [$this->company(), $this->company()];

        foreach ([$home, $member] as $company) {
            $contact = $this->person($company, 'Bounce')->contacts()->create([
                'contacts_types_id' => 1,
                'value' => 'noreply+bounce-' . uniqid() . '@kanvas.dev',
                'weight' => 0,
            ]);
            $contact->validation_status = ContactValidationStatusEnum::HARD_BOUNCE->value;
            $contact->saveOrFail();
        }

        $result = $this->scoped(new ListBouncesTool(), $home, $this->scope($home, $member))
            ->__invoke(limit: 50);

        $ours = array_values(array_filter($result['bounces'], fn (array $row): bool => $row['person'] === 'Bounce Scope'));

        $this->assertCount(2, $ours);
        $this->assertEqualsCanonicalizing([$home->name, $member->name], array_column($ours, 'crm'));
        $this->assertEqualsCanonicalizing([$home->getId(), $member->getId()], array_column($ours, 'tenant_company_id'));
        $this->assertSame(['—', '—'], array_column($ours, 'company'), 'company stays the employer, never the tenant');
    }

    public function testTheCleanupReportSplitsByTenantCompanyAndKeepsTheEmployerSplitOut(): void
    {
        [$home, $member] = [$this->company(), $this->company()];
        $this->person($home, 'Clean');
        $this->person($member, 'Clean');
        $this->person($member, 'Cleaner');

        $report = $this->scoped(new GetCleanupReportTool(), $home, $this->scope($home, $member))
            ->__invoke();

        $this->assertSame(3, $report['totalPeople']);
        $this->assertSame($home->name, $report['company']);
        $this->assertArrayNotHasKey('byCompany', $report);

        $byTenant = array_column($report['byTenantCompany'], 'totalPeople', 'tenant_company_id');
        $this->assertCount(2, $byTenant);
        $this->assertSame(1, $byTenant[$home->getId()]);
        $this->assertSame(2, $byTenant[$member->getId()]);
        $this->assertSame([$home->name, $member->name], $report['scope']['tenant_companies']);
    }

    public function testACrossCompanyTurnIsLedgeredOnce(): void
    {
        [$home, $member] = [$this->company(), $this->company()];
        $agent = $this->agent($home, [$member->getId()]);
        $events = fn (): int => Event::query()
            ->where('event_type', CrossCompanyReadLedger::EVENT_TYPE)
            ->where('source_entity_id', $agent->getId())
            ->count();

        $turn = AgentReadAccessCompanies::scopeFor($agent, $home, $this->human);
        $tool = $this->scoped(new FindPersonTool(), $home, $turn);
        $tool('nobody');
        $tool('nobody again');
        $this->scoped(new GetCustomerStatsTool(), $home, $turn)->__invoke();
        $this->assertSame(1, $events(), 'one event per turn, however many tools and calls');

        $nextTurn = AgentReadAccessCompanies::scopeFor($agent, $home, $this->human);
        $this->scoped(new FindPersonTool(), $home, $nextTurn)->__invoke('nobody');
        $this->assertSame(2, $events(), 'a new turn builds a new scope and records again');

        $event = Event::query()->where('event_type', CrossCompanyReadLedger::EVENT_TYPE)->where('source_entity_id', $agent->getId())->latest('id')->first();
        $this->assertSame('find_person', $event->payload['first_tool']);
        $this->assertEqualsCanonicalizing([$home->getId(), $member->getId()], $event->payload['companies']);
        $this->assertSame($this->human->getId(), (int) $event->actor_id);
    }

    public function testWithContextKeepsTheAgentInSlotFourWhenAScopeIsPassed(): void
    {
        $home = $this->company();
        $agent = $this->agent($home);
        $scope = CompanyScope::homeOnly($this->kanvasApp, $home);

        $probe = new class () extends Tool {
            use HasKanvasContext;

            protected string $name = 'probe';
            protected ?string $description = 'probe';

            public function agentOrNull(): ?Agent
            {
                return $this->contextAgent();
            }

            public function scope(): CompanyScope
            {
                return $this->readCompanyScope();
            }

            public function __invoke(): array
            {
                return [];
            }
        };

        $probe->withContext($this->kanvasApp, $home, $this->human);
        $this->assertNull($probe->agentOrNull());
        $this->assertSame([$home->getId()], $probe->scope()->ids, 'no scope falls back to home');

        $probe->withContext(
            $this->kanvasApp,
            $home,
            $this->human,
            $agent
        );
        $this->assertSame($agent->getId(), $probe->agentOrNull()?->getId());

        $probe->withContext(
            $this->kanvasApp,
            $home,
            $this->human,
            $agent,
            $scope
        );
        $this->assertSame($agent->getId(), $probe->agentOrNull()?->getId());
        $this->assertSame($scope, $probe->scope());
    }

    private function campaign(Companies $company): Campaign
    {
        $campaign = new Campaign();
        $campaign->apps_id = $this->kanvasApp->getId();
        $campaign->companies_id = $company->getId();
        $campaign->users_id = $this->human->getId();
        $campaign->channel = 'sms';
        $campaign->message = 'Body';
        $campaign->status = CampaignStatusEnum::SENT->value;
        $campaign->total_recipients = 1;
        $campaign->sent_count = 1;
        $campaign->failed_count = 0;
        $campaign->saveOrFail();

        return $campaign;
    }

    private function template(Companies $company, string $name): Templates
    {
        return Templates::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $this->human->getId(),
            'name' => $name,
            'template' => '<p>scope</p>',
            'parent_template_id' => 0,
            'is_system' => false,
        ]);
    }
}
