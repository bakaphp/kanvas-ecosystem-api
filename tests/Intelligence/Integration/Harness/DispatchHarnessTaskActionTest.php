<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

use Baka\Contracts\CompanyInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OpenCode\Enums\ConfigurationEnum;
use Kanvas\Connectors\OpenCode\Services\SessionAttachmentService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\DispatchHarnessTaskAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Exceptions\HarnessTransportException;
use Kanvas\Intelligence\AgentRuntime\Harness\Jobs\LaunchTaskSessionJob;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\Users\Models\Users;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The action has no seam for the harness — `HarnessFactory::forSession()` is called statically with no
 * injectable transport — so the happy path cannot be driven without a live opencode server. Instead the
 * app is pointed at an unroutable static endpoint: provisioning takes the "attach" branch (no SSH, no
 * container), the first HTTP call is refused immediately, and the durable records the action wrote
 * before that point are exactly what we assert on. The start failure is expected, not a defect.
 *
 * Serial because it writes an app/company setting, which lives in Redis + `ecosystem` and is therefore
 * shared by every paratest process.
 */
#[Group('serial')]
class DispatchHarnessTaskActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'social'];

    private const string UNROUTABLE_ENDPOINT = 'http://127.0.0.1:1';

    /** @var array<string, mixed> */
    private array $originalAppSettings = [];

    /** @var array<string, mixed> */
    private array $originalCompanySettings = [];

    protected function setUp(): void
    {
        parent::setUp();

        [$app, $company] = $this->context();

        // Settings live in Redis as well as `ecosystem`, and only the DB half is inside the test
        // transaction — so whatever this app already had has to be put back by hand.
        foreach ([ConfigurationEnum::STATIC_ENDPOINT, ConfigurationEnum::STATIC_PASSWORD] as $setting) {
            $this->originalAppSettings[$setting->value] = $app->get($setting->value);
        }

        $this->originalCompanySettings[ConfigurationEnum::MAX_CONCURRENT_SESSIONS->value]
            = $company->get(ConfigurationEnum::MAX_CONCURRENT_SESSIONS->value);
    }

    protected function tearDown(): void
    {
        [$app, $company] = $this->context();

        $this->restore($app, $this->originalAppSettings);
        $this->restore($company, $this->originalCompanySettings);

        parent::tearDown();
    }

    public function testDispatchRecordsAPlanAnInProgressTaskAndALinkedSession(): void
    {
        [$app, $company, $user] = $this->context();
        $app->set(ConfigurationEnum::STATIC_ENDPOINT->value, self::UNROUTABLE_ENDPOINT);
        $app->set(ConfigurationEnum::STATIC_PASSWORD->value, 'not-a-real-password');

        $agent = $this->makeAgent($app, $company, $user);
        $brief = 'Make the flaky harness test deterministic';

        try {
            new DispatchHarnessTaskAction(agent: $agent, task: $brief)->execute();
            $this->fail('Expected the harness start to fail against an unroutable endpoint');
        } catch (HarnessTransportException $e) {
            $this->assertStringContainsString('Harness request failed', $e->getMessage());
        } catch (ValidationException $e) {
            $this->fail('Dispatch failed before opening the session: ' . $e->getMessage());
        }

        $plan = Plan::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->where('agent_id', $agent->getId())
            ->where('plan_type', 'coding_job')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('coding_job', $plan->plan_type);
        $this->assertStringContainsString('Coding:', $plan->title);
        $this->assertSame(1, $plan->tasks()->count());

        /** @var Task $task */
        $task = $plan->tasks()->firstOrFail();
        $this->assertSame(TaskStatusEnum::IN_PROGRESS->value, $task->status);
        $this->assertSame($agent->getId(), $task->agent_id);

        $session = AgentTaskSession::query()->forTask($task->getId())->firstOrFail();
        $this->assertSame($plan->getId(), $session->plan_id);
        $this->assertSame($app->getId(), $session->apps_id);
        $this->assertSame($company->getId(), $session->companies_id);
        $this->assertSame($agent->getId(), $session->agent_id);
        $this->assertSame('opencode', $session->harness);
        $this->assertSame(self::UNROUTABLE_ENDPOINT, $session->endpoint);
        $this->assertSame('kanvas_managed', $session->credential_source);
        $this->assertNotNull($session->started_at);
        // The row has to survive a failed start — it is the only record a container may be running.
        $this->assertSame(HarnessStatusEnum::FAILED->value, $session->status);
        $this->assertNotNull($session->error_message);
    }

    public function testDispatchRefusesWhenTheCompanyIsAtItsConcurrencyCap(): void
    {
        [$app, $company, $user] = $this->context();
        $company->set(ConfigurationEnum::MAX_CONCURRENT_SESSIONS->value, 1);
        $app->set(ConfigurationEnum::STATIC_ENDPOINT->value, self::UNROUTABLE_ENDPOINT);

        $agent = $this->makeAgent($app, $company, $user);
        $this->seedLiveSession($app, $company, $agent);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/coding session\(s\) running \(limit 1\)/');

        new DispatchHarnessTaskAction(agent: $agent, task: 'Should never be recorded')->execute();
    }

    public function testDispatchRejectsAnEmptyBrief(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('A coding task needs a description');

        new DispatchHarnessTaskAction(agent: $agent, task: "   \n ")->execute();
    }

    public function testLaunchRecordsTheAttachmentsAndHandsTheirIdsToTheLaunchJob(): void
    {
        Queue::fake();

        [$app, $company, $user] = $this->context();
        $app->del(ConfigurationEnum::STATIC_ENDPOINT->value);

        $agent = $this->makeAgent($app, $company, $user);
        $design = $this->makeFilesystem($app, $company, $user);

        $task = new DispatchHarnessTaskAction(
            agent: $agent,
            task: 'Implement the attached checkout design',
            attachmentIds: [$design->getId()],
        )->execute();

        $this->assertSame([$design->getId()], $task->plan->input['attachment_ids'] ?? null);

        Queue::assertPushed(
            LaunchTaskSessionJob::class,
            fn (LaunchTaskSessionJob $job): bool => $job->attachmentIds === [$design->getId()]
        );
    }

    public function testAnUnknownAttachmentFailsTheDispatchBeforeAnythingIsRecorded(): void
    {
        Queue::fake();

        [$app, $company, $user] = $this->context();
        $app->del(ConfigurationEnum::STATIC_ENDPOINT->value);

        $agent = $this->makeAgent($app, $company, $user);

        try {
            new DispatchHarnessTaskAction(
                agent: $agent,
                task: 'Implement a design that does not exist',
                attachmentIds: [PHP_INT_MAX],
            )->execute();
            $this->fail('Expected an unknown attachment to be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('were not found in this company', $e->getMessage());
        }

        $this->assertSame(0, Plan::query()->where('agent_id', $agent->getId())->count());
        Queue::assertNotPushed(LaunchTaskSessionJob::class);
    }

    public function testAnotherCompanysFileCannotBeAttached(): void
    {
        [$app, $company, $user] = $this->context();
        $foreign = $this->makeFilesystem(
            $app,
            $company,
            $user,
            companyId: $company->getId() + 1_000_000
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('were not found in this company');

        new SessionAttachmentService($app, $company)->resolve([$foreign->getId()]);
    }

    public function testAnAttachmentThatCannotBeDownloadedFailsInsteadOfBeingSkipped(): void
    {
        [$app, $company, $user] = $this->context();
        $unreachable = $this->makeFilesystem($app, $company, $user);
        // Private address: the SSRF guard refuses it before any connection is made.
        $unreachable->url = 'http://10.0.0.1/checkout-design.png';
        $unreachable->saveOrFail();

        $service = new SessionAttachmentService($app, $company);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('could not be downloaded');

        $service->load($service->resolve([$unreachable->getId()]));
    }

    public function testAttachmentsAreRefusedInAttachModeWhereThereIsNoWorkspaceToWriteThem(): void
    {
        [$app, $company, $user] = $this->context();
        $app->set(ConfigurationEnum::STATIC_ENDPOINT->value, self::UNROUTABLE_ENDPOINT);

        $agent = $this->makeAgent($app, $company, $user);
        $design = $this->makeFilesystem($app, $company, $user);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Attachments need a launched coding workspace');

        new DispatchHarnessTaskAction(
            agent: $agent,
            task: 'Implement the attached design',
            attachmentIds: [$design->getId()],
        )->execute();
    }

    public function testAttachingMoreThanTheLimitIsRefused(): void
    {
        [$app, $company] = $this->context();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('at most ' . SessionAttachmentService::MAX_FILES . ' attachments');

        new SessionAttachmentService($app, $company)->resolve(range(1, SessionAttachmentService::MAX_FILES + 1));
    }

    public function testTerminalSessionsDoNotCountTowardsTheCap(): void
    {
        [$app, $company, $user] = $this->context();
        $company->set(ConfigurationEnum::MAX_CONCURRENT_SESSIONS->value, 1);

        $agent = $this->makeAgent($app, $company, $user);
        $this->seedLiveSession($app, $company, $agent, HarnessStatusEnum::COMPLETED);

        $live = AgentTaskSession::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->live()
            ->count();

        $this->assertSame(0, $live);
    }

    /**
     * @param array<string, mixed> $originals
     */
    private function restore(Apps|CompanyInterface $holder, array $originals): void
    {
        foreach ($originals as $key => $value) {
            $value === null ? $holder->del($key) : $holder->set($key, $value);
        }
    }

    private function seedLiveSession(
        Apps $app,
        CompanyInterface $company,
        Agent $agent,
        HarnessStatusEnum $status = HarnessStatusEnum::RUNNING
    ): AgentTaskSession {
        $session = new AgentTaskSession();
        $session->apps_id = $app->getId();
        $session->companies_id = $company->getId();
        $session->agent_id = $agent->getId();
        // No FK on the table, and the cap is counted on tenant + status alone.
        $session->task_id = 0;
        $session->harness = 'opencode';
        $session->status = $status->value;
        $session->saveOrFail();

        return $session;
    }

    private function makeFilesystem(
        Apps $app,
        CompanyInterface $company,
        Users $user,
        ?int $companyId = null
    ): Filesystem {
        $filesystem = new Filesystem();
        $filesystem->apps_id = $app->getId();
        $filesystem->companies_id = $companyId ?? $company->getId();
        $filesystem->users_id = $user->getId();
        $filesystem->name = 'checkout-design.png';
        $filesystem->path = 'files/designs/' . uniqid() . '-checkout-design.png';
        $filesystem->url = 'https://cdn.salesassist.io/files/designs/' . uniqid() . '-checkout-design.png';
        $filesystem->file_type = 'png';
        $filesystem->size = '1024';
        $filesystem->is_deleted = 0;
        $filesystem->saveOrFail();

        return $filesystem;
    }

    private function makeAgent(Apps $app, CompanyInterface $company, Users $user): Agent
    {
        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'user_id' => $user->getId(),
                'is_active' => true,
            ]);
    }

    /**
     * @return array{0: Apps, 1: CompanyInterface, 2: Users}
     */
    private function context(): array
    {
        /** @var Users $user */
        $user = auth()->user();

        return [app(Apps::class), $user->getCurrentCompany(), $user];
    }
}
