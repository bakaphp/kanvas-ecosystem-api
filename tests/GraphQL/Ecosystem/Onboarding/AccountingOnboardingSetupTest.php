<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Onboarding;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Onboarding\DataTransferObject\SectionConfig;
use Kanvas\Companies\Onboarding\Jobs\ProvisionOnboardingItemsJob;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Companies\Onboarding\Models\OnboardingSection;
use Kanvas\Companies\Onboarding\Services\OnboardingProvisioningItemsService;
use Kanvas\Companies\Onboarding\Services\OnboardingStateService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\Scribe\Setup\Actions\SyncAccountingSetupPlanAction;
use Kanvas\Scribe\Setup\Services\AccountingSetupStateService;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * Picking AP/AR in onboarding sets accounting up as the run's last item (plan §13.3), shows the e-CF slot
 * only to Dominican companies, and closes setup tasks as their requirement turns ready.
 */
final class AccountingOnboardingSetupTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem', 'intelligence', 'accounting', 'workflow', 'crm', 'action_engine'];

    private string $prefix;
    private Apps $kanvasApp;
    private Users $user;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.mailgun.domain', 'mg.kanvas.test');
        config()->set('services.mailgun.accounting_inbox_domain', null);
        $this->prefix = 'acc' . substr(uniqid(), -6) . '_';
        $this->kanvasApp = app(Apps::class);
        $this->user = auth()->user();

        $requirements = [
            ['slug' => $this->prefix . 'approver', 'label' => 'Approver', 'shared' => true, 'options' => [['label' => 'Me', 'mode' => 'kanvas_provided']]],
            [
                'slug' => $this->prefix . 'ecf',
                'label' => 'e-CF',
                'shared' => true,
                'countries' => ['DO'],
                'options' => [['label' => 'Set it up with us', 'mode' => 'request_setup']],
            ],
            [
                'slug' => $this->prefix . 'inbox',
                'label' => 'Inbox',
                'options' => [['label' => 'Kanvas email', 'mode' => 'kanvas_provided', 'address_from' => 'accounting_inbox']],
            ],
        ];
        $steps = [
            ['title' => $this->prefix . 'Confirm the approver', 'readiness' => 'approver'],
            ['title' => $this->prefix . 'Connect e-CF', 'readiness' => 'ecf_provider', 'countries' => ['DO']],
            $this->prefix . 'Go live',
        ];
        $this->section(
            'ap',
            1,
            $requirements,
            $steps
        );
        $this->section(
            'ar',
            2,
            [],
            []
        );
    }

    public function test_the_setup_runs_once_as_the_last_item_and_lands_ready(): void
    {
        $company = $this->company('US');
        $row = $this->select($company, ['ap', 'ar']);

        $items = new OnboardingProvisioningItemsService()->forRow($row);
        $setups = array_values(array_filter($items, fn (array $item): bool => $item['kind'] === 'setup'));
        $this->assertCount(1, $setups);
        $this->assertSame('setup_accounting', end($items)['slug']);

        new ProvisionOnboardingItemsJob($this->kanvasApp, $company, $this->user)->handle();

        $row->refresh();
        $this->assertSame('ready', $row->dataGet('provisioning.setup_accounting.status'), (string) $row->dataGet('provisioning.setup_accounting.error'));
        $this->assertSame($this->user->getId(), new AccountingSetupStateService()->defaultApprover($company)?->getId());

        $state = new OnboardingStateService()->state($row);
        $this->assertContains('setup_accounting', array_column(array_column($state->items, 'template'), 'slug'));
        $inbox = array_column($state->requirements, null, 'slug')[$this->prefix . 'inbox'];
        $this->assertStringEndsWith('@mg.kanvas.test', (string) $inbox['inbound_address']);
    }

    public function test_the_ecf_slot_and_step_only_apply_to_a_dominican_company(): void
    {
        $us = $this->requirementSlugs($this->select($this->company('US'), ['ap']));
        $do = $this->requirementSlugs($this->select($this->company('DO'), ['ap']));

        $this->assertNotContains($this->prefix . 'ecf', $us);
        $this->assertContains($this->prefix . 'ecf', $do);

        $config = $this->sectionRow('ap')->config();
        $this->assertNotContains($this->prefix . 'Connect e-CF', $config->setupStepsFor('US'));
        $this->assertContains($this->prefix . 'Connect e-CF', $config->setupStepsFor('DO'));
        $this->assertNotContains($this->prefix . 'Connect e-CF', $config->setupStepsFor(null));
        $this->assertCount(3, $config->setupSteps);
    }

    public function test_a_setup_task_closes_when_its_requirement_turns_ready(): void
    {
        $company = $this->company('US');
        $row = $this->select($company, ['ap']);
        $plan = new CreatePlanAction(
            new PlanData(
                app: $this->kanvasApp,
                company: $company,
                title: 'Set up AP',
                planType: 'onboarding',
                user: $this->user,
                status: PlanStatusEnum::ACTIVE,
            ),
            [
                new TaskData(plan: null, title: $this->prefix . 'Confirm the approver', sequence: 1),
                new TaskData(plan: null, title: $this->prefix . 'Go live', sequence: 2),
            ],
        )->execute();
        $row->dataSet('setup_plan_id', $plan->getId());

        $this->assertSame(0, new SyncAccountingSetupPlanAction($row)->execute());

        new AccountingSetupStateService()->setDefaultApprover($company, $this->user);
        $this->assertSame(1, new SyncAccountingSetupPlanAction($row)->execute());

        $statuses = $plan->tasks()->get()->mapWithKeys(fn ($task): array => [$task->title => $task->status instanceof TaskStatusEnum ? $task->status->value : $task->status]);
        $this->assertSame('done', $statuses[$this->prefix . 'Confirm the approver']);
        $this->assertNotSame('done', $statuses[$this->prefix . 'Go live']);
    }

    public function test_a_section_naming_an_unknown_setup_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->section(
            'bad_setup',
            9,
            [],
            [],
            'payroll'
        );
    }

    public function test_a_requirement_with_a_bad_country_code_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->section(
            'bad_country',
            9,
            [['slug' => $this->prefix . 'x', 'countries' => ['DOM'], 'options' => []]],
            []
        );
    }

    public function test_the_shipped_catalog_still_validates(): void
    {
        $catalog = json_decode((string) file_get_contents(base_path('database/seeders/data/onboarding/global.json')), true);
        $sections = array_column($catalog['sections'], null, 'slug');

        foreach (['accounts_payable', 'accounts_receivable'] as $slug) {
            $config = SectionConfig::fromArray($sections[$slug]['config']);
            $this->assertSame('accounting', $config->setup);
            $this->assertContains('approver', $config->requestedRequirementSlugs());
        }

        $ecf = SectionConfig::fromArray($sections['accounts_payable']['config'])->requirementBySlug('ecf_provider');
        $this->assertSame(['DO'], $ecf['countries']);
    }

    private function company(string $country): Companies
    {
        $company = Companies::factory()->create([
            'users_id' => $this->user->getId(),
            'is_active' => 1,
            'country_code' => $country,
            'name' => 'Onboarded ' . Str::random(8),
        ]);
        $company->associateApp($this->kanvasApp);

        return $company;
    }

    /**
     * @param list<string> $bases
     */
    private function select(Companies $company, array $bases): CompanyOnboarding
    {
        $row = new OnboardingStateService()->forCompany($this->kanvasApp, $company, $this->user);
        $row->dataSet('areas_selected', array_map(fn (string $base): array => ['slug' => $this->prefix . $base], $bases));

        return $row;
    }

    /**
     * @return list<string>
     */
    private function requirementSlugs(CompanyOnboarding $row): array
    {
        return array_column(new OnboardingStateService()->state($row->refresh())->requirements, 'slug');
    }

    private function sectionRow(string $base): OnboardingSection
    {
        return OnboardingSection::query()->where('apps_id', $this->kanvasApp->getId())->where('slug', $this->prefix . $base)->firstOrFail();
    }

    /**
     * @param list<array<string, mixed>> $requirements
     * @param list<mixed> $steps
     */
    private function section(
        string $base,
        int $order,
        array $requirements,
        array $steps,
        string $setup = 'accounting',
    ): OnboardingSection {
        $requested = array_column($requirements, 'slug');

        return OnboardingSection::query()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'slug' => $this->prefix . $base,
            'label' => 'Label ' . $base,
            'title' => 'Title ' . $base,
            'description' => 'Description',
            'is_available' => true,
            'display_order' => $order,
            'is_active' => true,
            'config' => [
                'agents' => [],
                'modules' => [['slug' => $this->prefix . $base . '_module', 'name' => 'Module', 'description' => 'x', 'requirements' => $requested]],
                'requirements' => $requirements,
                'capabilities' => [],
                'setup_steps' => $steps,
                'setup' => $setup,
            ],
        ]);
    }
}
