<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Onboarding;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Onboarding\Actions\CompleteOnboardingAction;
use Kanvas\Companies\Onboarding\Actions\SelectOnboardingAreasAction;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Companies\Onboarding\Models\OnboardingSection;
use Kanvas\Companies\Onboarding\Services\OnboardingStateService;
use Kanvas\Intelligence\Agents\Jobs\WakeAgentInSessionJob;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\Users\Models\Users;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Traits\ReadsOnboardingGraphQL;

#[Group('serial')]
final class CompleteOnboardingTest extends TestCase
{
    use DatabaseTransactions;
    use ReadsOnboardingGraphQL;

    protected $connectionsToTransact = [null, 'ecosystem', 'intelligence', 'social'];

    private const string MUTATION = /** @lang GraphQL */ '
        mutation {
            completeOnboarding {
                status
                setup_session_id
                setup_plan { id title tasks { title sequence } agent { id name } }
                items { template { slug } status }
            }
        }
    ';

    private string $salesSlug;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        Agent::factory()->withAppId(0)->withCompanyId(0)->create(['name' => 'Ginny']);

        $this->salesSlug = 'c' . substr(uniqid(), -6) . '_sales';
        OnboardingSection::query()->create([
            'apps_id' => app(Apps::class)->getId(),
            'slug' => $this->salesSlug,
            'label' => 'Sales',
            'title' => 'Respond to my leads instantly',
            'description' => 'Reply to every conversation.',
            'is_available' => true,
            'display_order' => 1,
            'is_active' => true,
            'config' => [
                'agents' => [],
                'modules' => [['slug' => 'voice_like', 'name' => 'Voice', 'description' => 'Calls']],
                'requirements' => [],
                'capabilities' => ['Replies'],
                'setup_steps' => ['Connect the channel your leads write to', 'Review the first conversations together', 'Go live'],
            ],
        ]);
    }

    public function testRefusedFromStarted(): void
    {
        $response = $this->graphQL(self::MUTATION);

        $this->assertNull($response->json('data.completeOnboarding'));
        $this->assertNotEmpty($response->json('errors'));
        $this->assertSame('started', CompanyOnboarding::forCompany(app(Apps::class), $this->currentCompany())->status);
    }

    public function testCreatesPlanWithOneTaskPerSetupStepAssignedToGinny(): void
    {
        $this->selectSales();

        $response = $this->graphQL(self::MUTATION);
        $data = $response->json('data.completeOnboarding');
        $this->assertNotNull($data, json_encode($response->json()));

        $this->assertSame('COMPLETED', $data['status']);
        $this->assertNotEmpty($data['setup_session_id']);
        $this->assertSame('Set up Respond to my leads instantly', $data['setup_plan']['title']);
        $this->assertSame(
            ['Connect the channel your leads write to', 'Review the first conversations together', 'Go live'],
            array_column($data['setup_plan']['tasks'], 'title'),
        );
        $this->assertSame('Ginny', $data['setup_plan']['agent']['name']);

        $ginny = Agent::query()
            ->fromApp(app(Apps::class))
            ->fromCompany($this->currentCompany())
            ->where('name', 'Ginny')
            ->notDeleted()
            ->firstOrFail();
        $this->assertSame($ginny->getId(), (int) $data['setup_plan']['agent']['id']);

        Queue::assertPushed(WakeAgentInSessionJob::class, fn (WakeAgentInSessionJob $job): bool => $job->agent->getId() === $ginny->getId()
            && str_contains($job->instruction, 'Respond to my leads instantly'));
    }

    public function testWelcomeFlippedAndCompletedEventOnce(): void
    {
        /** @var Users $user */
        $user = auth()->user();
        $user->welcome = 0;
        $user->saveOrFail();

        $this->selectSales();
        $this->graphQL(self::MUTATION)->assertSuccessful();

        $this->assertTrue((bool) $user->refresh()->welcome);

        $row = CompanyOnboarding::forCompany(app(Apps::class), $this->currentCompany());
        $this->assertNotNull($row->completed_at);
        $this->assertSame(1, Event::query()
            ->where('event_type', 'onboarding.completed')
            ->where('source_entity_id', $row->getId())
            ->count());
    }

    public function testCompletingTwiceIsRefusedAndCreatesNoSecondPlan(): void
    {
        $since = now()->subSecond();
        $this->selectSales();
        $first = $this->graphQL(self::MUTATION)->json('data.completeOnboarding');
        $this->assertNotNull($first);

        $second = $this->graphQL(self::MUTATION);
        $this->assertNull($second->json('data.completeOnboarding'));

        $this->assertSame(1, Plan::query()
            ->fromApp(app(Apps::class))
            ->fromCompany($this->currentCompany())
            ->where('plan_type', 'onboarding')
            ->where('created_at', '>=', $since)
            ->count());
    }

    private function selectSales(): void
    {
        $row = new OnboardingStateService()->forCompany(app(Apps::class), $this->currentCompany(), auth()->user());
        new SelectOnboardingAreasAction($row, auth()->user(), [$this->salesSlug], $this->salesSlug)->execute();
    }

    public function testRetryAfterAPartialFailureReusesTheExistingPlan(): void
    {
        $since = now()->subSecond();
        $this->selectSales();
        $row = CompanyOnboarding::forCompany(app(Apps::class), $this->currentCompany());

        $ginny = Agent::factory()->withAppId(app(Apps::class)->getId())->withCompanyId($this->currentCompany()->getId())->create(['name' => 'Ginny']);
        $existing = new CreatePlanAction(new PlanData(
            app: app(Apps::class),
            company: $this->currentCompany(),
            title: 'Set up Respond to my leads instantly',
            planType: CompleteOnboardingAction::PLAN_TYPE,
            agent: $ginny,
            user: auth()->user(),
        ))->execute();
        $row->dataSet('setup_plan_id', $existing->getId());

        $data = $this->graphQL(self::MUTATION)->json('data.completeOnboarding');
        $this->assertNotNull($data);
        $this->assertSame((string) $existing->getId(), (string) $data['setup_plan']['id']);
        $this->assertSame(1, Plan::query()
            ->fromApp(app(Apps::class))
            ->fromCompany($this->currentCompany())
            ->where('plan_type', CompleteOnboardingAction::PLAN_TYPE)
            ->where('created_at', '>=', $since)
            ->count());
    }

    public function testRefusedWhileAnotherCompletionIsInFlight(): void
    {
        $this->selectSales();
        $row = CompanyOnboarding::forCompany(app(Apps::class), $this->currentCompany());

        $lock = Cache::lock('onboarding:complete:' . $row->getId(), 30);
        $this->assertTrue($lock->get());

        try {
            $response = $this->graphQL(self::MUTATION);
        } finally {
            $lock->release();
        }

        $this->assertNull($response->json('data.completeOnboarding'));
        $this->assertSame('areas_selected', $row->refresh()->status);
    }
}
