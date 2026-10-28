<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Onboarding;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Onboarding\Enums\OnboardingStatusEnum;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Companies\Onboarding\Models\OnboardingSection;
use Kanvas\Companies\Onboarding\Services\OnboardingStateService;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Users\Models\Users;
use Tests\TestCase;
use Tests\Traits\ReadsOnboardingGraphQL;

final class OnboardingMutationsTest extends TestCase
{
    use DatabaseTransactions;
    use ReadsOnboardingGraphQL;

    protected $connectionsToTransact = [null, 'ecosystem'];

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'm' . substr(uniqid(), -6) . '_';
        $this->section(
            'sales',
            'Respond to my leads instantly',
            available: true,
            order: 1,
        );
        $this->section(
            'crm',
            'Keep my CRM up to date',
            available: false,
            order: 2,
        );
        $this->section(
            'accounts_payable',
            'Pay my suppliers\' bills on time',
            available: true,
            order: 3,
        );
    }

    public function testCompanyOnboardingCreatesRowOnFirstRead(): void
    {
        $this->assertNull(CompanyOnboarding::forCompany(app(Apps::class), $this->currentCompany()));

        $state = $this->state();

        $this->assertSame('STARTED', $state['status']);
        $this->assertNotNull(CompanyOnboarding::forCompany(app(Apps::class), $this->currentCompany()));
        $this->assertSame($this->currentCompany()->name, $state['profile']['name']);
    }

    public function testUpdateProfileWritesCompanyAndData(): void
    {
        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation($input: OnboardingProfileInput!) {
                updateOnboardingProfile(input: $input) { status profile { website offering job_role } }
            }
        ', ['input' => ['website' => 'acme-' . $this->prefix . '.com', 'offering' => 'Office furniture', 'job_role' => 'Founder']]);

        $data = $this->graphQLData($response, 'updateOnboardingProfile');
        $this->assertSame('PROFILED', $data['status']);
        $this->assertSame('https://acme-' . $this->prefix . '.com', $data['profile']['website']);
        $this->assertSame('Office furniture', $data['profile']['offering']);
        $this->assertSame('Founder', $data['profile']['job_role']);
        $this->assertSame('https://acme-' . $this->prefix . '.com', $this->currentCompany()->refresh()->website);
    }

    public function testUpdateProfileRejectsPrivateWebsite(): void
    {
        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation($input: OnboardingProfileInput!) {
                updateOnboardingProfile(input: $input) { status }
            }
        ', ['input' => ['website' => 'http://127.0.0.1/admin']]);

        $this->assertNull($response->json('data.updateOnboardingProfile'));
        $this->assertNotEmpty($response->json('errors'));
    }

    public function testSelectAreasRecordsShownSelectedAndSoon(): void
    {
        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation($areas: [String!]!, $first: String) {
                selectOnboardingAreas(areas: $areas, first_area: $first) {
                    status
                    first_area
                    areas { slug selected available }
                    items { template { slug kind } area status }
                }
            }
        ', ['areas' => [$this->slug('sales'), $this->slug('crm')], 'first' => $this->slug('sales')]);

        $data = $this->graphQLData($response, 'selectOnboardingAreas');
        $this->assertSame('AREAS_SELECTED', $data['status']);
        $this->assertSame($this->slug('sales'), $data['first_area']);

        $areas = collect($data['areas'])->keyBy('slug');
        $this->assertTrue($areas[$this->slug('sales')]['selected']);
        $this->assertTrue($areas[$this->slug('crm')]['selected']);
        $this->assertFalse($areas[$this->slug('crm')]['available']);
        $this->assertFalse($areas[$this->slug('accounts_payable')]['selected']);

        $this->assertSame(['sally', 'approvals_like'], array_column(array_column($data['items'], 'template'), 'slug'));
        $this->assertSame('WAITING', $data['items'][0]['status']);

        $row = CompanyOnboarding::forCompany(app(Apps::class), $this->currentCompany());
        $selected = collect($row->dataGet('areas_selected'))->keyBy('slug');
        $this->assertSame('Label ' . $this->slug('crm'), $selected[$this->slug('crm')]['label']);
        $this->assertContains($this->slug('accounts_payable'), $row->dataGet('areas_shown'));

        $event = Event::query()
            ->where('event_type', 'onboarding.areas.selected')
            ->where('source_entity_id', $row->getId())
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($event);
        $this->assertSame([$this->slug('crm')], $event->payload['soon']);
    }

    public function testSelectAreasRejectsUnknownSlug(): void
    {
        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation($areas: [String!]!) { selectOnboardingAreas(areas: $areas) { status } }
        ', ['areas' => ['no_such_area_' . $this->prefix]]);

        $this->assertNull($response->json('data.selectOnboardingAreas'));
        $this->assertStringContainsString('no_such_area_' . $this->prefix, (string) $response->json('errors.0.message'));
    }

    public function testFirstAreaMustBeSelected(): void
    {
        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation($areas: [String!]!, $first: String) { selectOnboardingAreas(areas: $areas, first_area: $first) { status } }
        ', ['areas' => [$this->slug('sales')], 'first' => $this->slug('crm')]);

        $this->assertNull($response->json('data.selectOnboardingAreas'));
        $this->assertNotEmpty($response->json('errors'));
    }

    public function testSetStepOnlyWritesCurrentStep(): void
    {
        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation { setOnboardingStep(step: "areas") { status current_step } }
        ');

        $data = $this->graphQLData($response, 'setOnboardingStep');
        $this->assertSame('STARTED', $data['status']);
        $this->assertSame('areas', $data['current_step']);
    }

    public function testSkipMarksSkippedAndFlipsWelcomeOnce(): void
    {
        /** @var Users $user */
        $user = auth()->user();
        $user->welcome = 0;
        $user->saveOrFail();

        $first = $this->graphQLData($this->graphQL('mutation { skipOnboarding { status } }'), 'skipOnboarding');
        $this->assertSame('SKIPPED', $first['status']);
        $this->assertTrue((bool) $user->refresh()->welcome);

        $this->graphQL('mutation { skipOnboarding { status } }')->assertSuccessful();

        $row = CompanyOnboarding::forCompany(app(Apps::class), $this->currentCompany());
        $this->assertNotNull($row->skipped_at);
        $this->assertSame(1, Event::query()
            ->where('event_type', 'onboarding.skipped')
            ->where('source_entity_id', $row->getId())
            ->count());
    }

    public function testCompanyBGetsItsOwnRow(): void
    {
        $rowA = $this->graphQLData($this->graphQL('query { companyOnboarding { id } }'), 'companyOnboarding');

        $companyB = Companies::factory()->create(['users_id' => auth()->user()->getId()]);
        $rowB = new OnboardingStateService()->forCompany(app(Apps::class), $companyB, auth()->user());

        $this->assertNotSame((int) $rowA['id'], $rowB->getId());
        $this->assertSame($companyB->getId(), (int) $rowB->companies_id);
    }

    private function state(): array
    {
        return $this->graphQLData($this->graphQL(/** @lang GraphQL */ '
            query { companyOnboarding { id status current_step profile { name } research { status } areas { slug } } }
        '), 'companyOnboarding');
    }

    private function slug(string $base): string
    {
        return $this->prefix . $base;
    }

    private function section(string $base, string $title, bool $available, int $order): OnboardingSection
    {
        $agents = $base === 'sales'
            ? [['slug' => 'sally', 'name' => 'Sally', 'description' => 'Sales', 'source_agent' => 'Sally', 'agent_type' => 'Sales Agent', 'requirements' => []]]
            : [];
        $modules = $base === 'sales'
            ? [['slug' => 'approvals_like', 'name' => 'Approvals', 'description' => 'Approve things']]
            : [];

        return OnboardingSection::query()->create([
            'apps_id' => app(Apps::class)->getId(),
            'slug' => $this->slug($base),
            'label' => 'Label ' . $this->slug($base),
            'title' => $title,
            'description' => 'Description',
            'is_available' => $available,
            'display_order' => $order,
            'is_active' => true,
            'config' => ['agents' => $agents, 'modules' => $modules, 'requirements' => [], 'capabilities' => ['One'], 'setup_steps' => ['Step one']],
        ]);
    }

    public function testSkipIsRefusedFromCompleted(): void
    {
        $row = new OnboardingStateService()->forCompany(app(Apps::class), $this->currentCompany(), auth()->user());
        $row->transitionTo(OnboardingStatusEnum::COMPLETED);

        $response = $this->graphQL('mutation { skipOnboarding { status } }');

        $this->assertNull($response->json('data.skipOnboarding'));
        $this->assertSame('completed', $row->refresh()->status);
        $this->assertNull($row->skipped_at);
    }

    public function testSelectAreasRejectsAnEmptySelection(): void
    {
        $response = $this->graphQL('mutation { selectOnboardingAreas(areas: []) { status } }');

        $this->assertNull($response->json('data.selectOnboardingAreas'));
        $this->assertNotEmpty($response->json('errors'));
    }

    public function testProvisionIsRefusedBeforeAreasAreSelected(): void
    {
        $response = $this->graphQL('mutation { provisionOnboardingItems { status } }');

        $this->assertNull($response->json('data.provisionOnboardingItems'));
        $this->assertStringContainsStringIgnoringCase('area', (string) $response->json('errors.0.message'));
        $this->assertSame('started', CompanyOnboarding::forCompany(app(Apps::class), $this->currentCompany())->status);
    }

    public function testSetStepRejectsAStepLongerThanTheColumn(): void
    {
        $response = $this->graphQL('mutation { setOnboardingStep(step: "' . str_repeat('x', 33) . '") { status } }');

        $this->assertNull($response->json('data.setOnboardingStep'));
        $this->assertNotEmpty($response->json('errors'));
    }
}
