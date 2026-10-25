<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Support\CompanyScope;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\FindPersonTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetLeadAnalyticsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetPersonTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SearchLeadsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesPersonForTool;
use Kanvas\Intelligence\Agents\Support\AgentReadAccessCompanies;
use Kanvas\Intelligence\Agents\Support\CrossCompanyReadLedger;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\Tool;
use Tests\Intelligence\Agents\Concerns\BuildsCrossCompanyScopes;
use Tests\TestCase;

/**
 * PR2 and PR3 of the cross-company read scope: narrowing to one company, leads whose "open" set is
 * per-company config, analytics summed across the scope, and by-id reads inside it.
 */
class CrossCompanyNarrowingAndLeadsTest extends TestCase
{
    use BuildsCrossCompanyScopes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->human = Users::factory()->create();
    }

    public function testNarrowingToAnAllowedCompanyReadsOnlyItAndStaysLabelled(): void
    {
        [$home, $member] = [$this->company(), $this->company()];
        $this->person($home, 'Narrow');
        $this->person($member, 'Narrow');
        $scope = $this->scope($home, $member);

        $all = $this->scoped(new FindPersonTool(), $home, $scope)->__invoke('Narrow Scope');
        $this->assertSame(2, $all['count']);

        $only = $this->scoped(new FindPersonTool(), $home, $scope)->__invoke('Narrow Scope', tenant_company_id: $member->getId());
        $this->assertSame(1, $only['count']);
        $this->assertSame($member->getId(), $only['people'][0]['tenant_company_id']);
        $this->assertSame($member->name, $only['people'][0]['tenant_company']);
    }

    public function testNarrowingToACompanyOutsideTheScopeIsRefused(): void
    {
        [$home, $member, $stranger] = [$this->company(), $this->company(), $this->company(member: false)];
        $this->person($stranger, 'Hidden');

        $result = $this->scoped(new FindPersonTool(), $home, $this->scope($home, $member))
            ->__invoke('Hidden Scope', tenant_company_id: $stranger->getId());

        $this->assertSame('error', $result['status']);
        $this->assertArrayNotHasKey('people', $result);
        $this->assertStringContainsString($member->name, $result['message'], 'the refusal lists the companies the model may use');
    }

    public function testTheTenantCompanyArgumentIsOnlyDeclaredOnACrossCompanyScope(): void
    {
        [$home, $member] = [$this->company(), $this->company()];

        $probe = new class () extends FindPersonTool {
            public function declared(): array
            {
                return array_map(fn ($property) => $property->getName(), $this->properties());
            }
        };

        $probe->withContext($this->kanvasApp, $home, $this->human, scope: CompanyScope::homeOnly($this->kanvasApp, $home));
        $this->assertNotContains('tenant_company_id', $probe->declared(), 'single-company agents keep their exact schema');

        $probe->withContext($this->kanvasApp, $home, $this->human, scope: $this->scope($home, $member));
        $this->assertContains('tenant_company_id', $probe->declared());
    }

    public function testANarrowedReadOfAnotherCompanyIsLedgeredWithTheNarrowing(): void
    {
        [$home, $member] = [$this->company(), $this->company()];
        $agent = $this->agent($home, [$member->getId()]);
        $scope = AgentReadAccessCompanies::scopeFor($agent, $home, $this->human);

        $this->scoped(new FindPersonTool(), $home, $scope)->__invoke('nobody', tenant_company_id: $member->getId());

        $event = Event::query()
            ->where('event_type', CrossCompanyReadLedger::EVENT_TYPE)
            ->where('source_entity_id', $agent->getId())
            ->latest('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame([$member->getId()], $event->payload['companies']);
        $this->assertTrue($event->payload['narrowed']);
    }

    public function testOpenLeadsFollowEachCompanysOwnOpenStatusConfig(): void
    {
        [$home, $member] = [$this->company(), $this->company()];
        $custom = LeadStatus::create([
            'name' => 'Scope Custom ' . uniqid(),
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => 0,
            'is_default' => 0,
        ]);
        // Only the member company treats the custom status as open.
        $member->set(LeadConfigurationEnum::OPEN_LEADS_STATUS_IDS->value, [$custom->getId()]);

        $atHome = $this->lead($home, 'Scope Lead Home', (int) $custom->getId());
        $atMember = $this->lead($member, 'Scope Lead Member', (int) $custom->getId());
        $scope = $this->scope($home, $member);

        $open = $this->scoped(new SearchLeadsTool(), $home, $scope)->__invoke(query: 'Scope Lead', status: 'open');
        $this->assertSame([$atMember->getId()], array_column($open['leads'], 'lead_id'), 'open at the member, closed at home');
        $this->assertSame($member->name, $open['leads'][0]['tenant_company']);

        $closed = $this->scoped(new SearchLeadsTool(), $home, $scope)->__invoke(query: 'Scope Lead', status: 'closed');
        $this->assertSame([$atHome->getId()], array_column($closed['leads'], 'lead_id'));

        $all = $this->scoped(new SearchLeadsTool(), $home, $scope)->__invoke(query: 'Scope Lead', status: 'all');
        $this->assertEqualsCanonicalizing([$atHome->getId(), $atMember->getId()], array_column($all['leads'], 'lead_id'));
    }

    public function testLeadAnalyticsSumAcrossTheScopeAndSayWhichCompanies(): void
    {
        [$home, $member, $stranger] = [$this->company(), $this->company(), $this->company(member: false)];
        $this->lead($home, 'Analytics Home', 1);
        $this->lead($member, 'Analytics Member', 1);
        $this->lead($stranger, 'Analytics Stranger', 1);

        $result = $this->scoped(new GetLeadAnalyticsTool(), $home, $this->scope($home, $member))->__invoke(timeframe: 'last_30_days');

        $this->assertSame('success', $result['status']);
        $this->assertSame(2, (int) $result['total']);
        $this->assertSame([$home->name, $member->name], $result['scope']['tenant_companies']);
    }

    public function testAWriteResolverNamesTheOwningCompanyInsteadOfInventingAMissingRecord(): void
    {
        [$home, $member, $stranger] = [$this->company(), $this->company(), $this->company(member: false)];
        $theirs = $this->lead($member, 'Theirs', 1);
        $outside = $this->lead($stranger, 'Outside', 1);
        $scope = $this->scope($home, $member);

        $probe = new class () extends Tool {
            use ResolvesLeadForTool;
            use ResolvesPersonForTool;

            protected string $name = 'probe';
            protected ?string $description = 'probe';

            public function lead(int $id): Lead|array
            {
                return $this->resolveLeadOrError($id);
            }

            public function person(int $id): People|array
            {
                return $this->resolvePersonOrError($id);
            }

            public function __invoke(): array
            {
                return [];
            }
        };
        $this->scoped($probe, $home, $scope);

        $refused = $probe->lead($theirs->getId());
        $this->assertSame('error', $refused['status']);
        $this->assertStringContainsString($member->name, $refused['message']);
        $this->assertStringContainsString($home->name, $refused['message']);
        $this->assertStringNotContainsString('create_lead', $refused['message'], 'must not steer the model to a duplicate');

        $unknown = $probe->lead($outside->getId());
        $this->assertStringContainsString('does not exist', $unknown['message'], 'outside the scope it is still an unknown id');

        $person = $this->person($member, 'Theirs');
        $this->assertStringContainsString($member->name, $probe->person($person->getId())['message']);
    }

    public function testAByIdReadResolvesInsideTheScopeAndNowhereElse(): void
    {
        [$home, $member, $stranger] = [$this->company(), $this->company(), $this->company(member: false)];
        $theirs = $this->person($member, 'ById');
        $outside = $this->person($stranger, 'ById');
        $scope = $this->scope($home, $member);

        $found = $this->scoped(new GetPersonTool(), $home, $scope)->__invoke($theirs->getId());
        $this->assertSame($theirs->getId(), $found['person_id']);
        $this->assertSame($member->name, $found['tenant_company']);

        $missing = $this->scoped(new GetPersonTool(), $home, $scope)->__invoke($outside->getId());
        $this->assertArrayHasKey('error', $missing);
        $this->assertArrayNotHasKey('person_id', $missing);
    }

    private function lead(Companies $company, string $title, int $statusId): Lead
    {
        return Lead::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($company->getId())
            ->withUserId($this->human->getId())
            ->create(['title' => $title, 'leads_status_id' => $statusId, 'leads_owner_id' => $this->human->getId()]);
    }
}
