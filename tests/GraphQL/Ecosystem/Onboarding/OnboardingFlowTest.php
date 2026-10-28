<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Onboarding;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Onboarding\Jobs\ProvisionOnboardingItemsJob;
use Kanvas\Companies\Onboarding\Models\OnboardingSection;
use Kanvas\Intelligence\Agents\Jobs\WakeAgentInSessionJob;
use Kanvas\Intelligence\Agents\Models\Agent;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Traits\ReadsOnboardingGraphQL;

/**
 * The whole flow over the six platform cards the migration ships, the way the frontend walks it:
 * profile → areas → provisioning → one requirement → completion.
 */
#[Group('serial')]
final class OnboardingFlowTest extends TestCase
{
    use DatabaseTransactions;
    use ReadsOnboardingGraphQL;

    protected $connectionsToTransact = [null, 'ecosystem', 'intelligence', 'workflow', 'social'];

    private const array TEMPLATES = ['Ginny', 'Accounts Payable', 'Sally', 'firstMessageEngagerAgent', 'FollowUpEngagerAgent', 'voiceOutreachAgent', 'Polly'];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();

        foreach (self::TEMPLATES as $name) {
            Agent::factory()->withAppId(0)->withCompanyId(0)->create(['name' => $name]);
        }
    }

    public function testMigrationShipsTheSixPlatformCards(): void
    {
        $cards = OnboardingSection::query()->where('apps_id', 0)->orderBy('display_order')->pluck('is_available', 'slug')->all();

        $this->assertSame(
            ['accounts_payable', 'accounts_receivable', 'sales', 'marketing', 'crm', 'inventory'],
            array_keys($cards),
        );
        $this->assertFalse($cards['marketing']);
        $this->assertFalse($cards['crm']);

        $areas = $this->graphQLData($this->graphQL('query { onboardingAreas { slug title selected } }'), 'onboardingAreas');
        $this->assertContains('Pay my suppliers\' bills on time', array_column($areas, 'title'));
    }

    public function testFullFlowOverThePlatformCards(): void
    {
        $profile = $this->graphQLData($this->graphQL(/** @lang GraphQL */ '
            mutation { updateOnboardingProfile(input: { offering: "Office furniture", job_role: "Founder" }) { status } }
        '), 'updateOnboardingProfile');
        $this->assertSame('PROFILED', $profile['status']);

        $selected = $this->graphQLData($this->graphQL(/** @lang GraphQL */ '
            mutation { selectOnboardingAreas(areas: ["accounts_payable", "sales", "crm"], first_area: "accounts_payable") {
                status first_area items { template { slug kind } status } } }
        '), 'selectOnboardingAreas');
        $this->assertSame('AREAS_SELECTED', $selected['status']);
        $this->assertSame(
            ['accounts_payable', 'approvals', 'sally', 'first_message', 'follow_up', 'voice', 'sales_manager'],
            array_column(array_column($selected['items'], 'template'), 'slug'),
        );

        $provisioning = $this->graphQLData($this->graphQL('mutation { provisionOnboardingItems { status } }'), 'provisionOnboardingItems');
        $this->assertSame('PROVISIONING', $provisioning['status']);
        Queue::assertPushed(ProvisionOnboardingItemsJob::class, function (ProvisionOnboardingItemsJob $job): bool {
            $job->handle();

            return true;
        });

        $team = $this->graphQLData($this->graphQL(/** @lang GraphQL */ '
            query { companyOnboarding { status
                items { template { slug kind } status ready agent { name }
                        requirements { slug required shared status options { label mode } } }
                requirements { slug shared satisfied_by status } } }
        '), 'companyOnboarding');
        $this->assertSame('TEAM_READY', $team['status']);

        $items = collect($team['items'])->keyBy('template.slug');
        $this->assertSame('READY', $items['accounts_payable']['status']);
        $this->assertSame('Accounts Payable', $items['accounts_payable']['agent']['name']);
        $this->assertSame('READY', $items['approvals']['status']);
        $this->assertTrue($items['approvals']['ready'], 'a module with no slots is ready as soon as it is provisioned');
        $this->assertFalse($items['accounts_payable']['ready'], 'AP still needs its required slots');
        $this->assertSame('Sally', $items['sally']['agent']['name']);

        $companyAgents = Agent::query()->fromApp(app(Apps::class))->fromCompany($this->currentCompany())->notDeleted()->pluck('name')->all();
        foreach (['Ginny', 'Accounts Payable', 'Sally', 'Polly'] as $name) {
            $this->assertContains($name, $companyAgents);
        }

        $requirements = collect($team['requirements'])->keyBy('slug');
        $this->assertTrue($requirements['accounting_software']['shared']);
        $this->assertSame('accounts_payable', $requirements['accounting_software']['satisfied_by']);
        $this->assertSame('MISSING', $requirements['accounting_software']['status']);

        $resolved = $this->graphQLData($this->graphQL(/** @lang GraphQL */ '
            mutation { resolveOnboardingRequirement(input: { requirement: "accounting_software", mode: REQUEST_SETUP, integration_id: "' . $this->alegraId() . '", notes: "we use Alegra" }) {
                slug status mode } }
        '), 'resolveOnboardingRequirement');
        $this->assertSame('SETUP_REQUESTED', $resolved['status']);
        $this->assertSame('REQUEST_SETUP', $resolved['mode']);

        $completed = $this->graphQLData($this->graphQL(/** @lang GraphQL */ '
            mutation { completeOnboarding { status setup_session_id setup_plan { title tasks { title } agent { name } } } }
        '), 'completeOnboarding');
        $this->assertSame('COMPLETED', $completed['status']);
        $this->assertSame('Set up Pay my suppliers\' bills on time', $completed['setup_plan']['title']);
        $this->assertSame('Ginny', $completed['setup_plan']['agent']['name']);
        $this->assertCount(5, $completed['setup_plan']['tasks']);
        $this->assertNotEmpty($completed['setup_session_id']);
        Queue::assertPushed(WakeAgentInSessionJob::class);

        $again = $this->graphQLData($this->graphQL('query { companyOnboarding { status } }'), 'companyOnboarding');
        $this->assertSame('COMPLETED', $again['status']);
    }

    private function alegraId(): string
    {
        $section = OnboardingSection::query()->where('apps_id', 0)->where('slug', 'accounts_payable')->firstOrFail();
        $requirement = $section->config()->requirementBySlug('accounting_software');
        foreach ($requirement['options'] as $option) {
            if ($option['label'] === 'Alegra') {
                return (string) ($option['integration_id'] ?? '');
            }
        }

        return '';
    }
}
