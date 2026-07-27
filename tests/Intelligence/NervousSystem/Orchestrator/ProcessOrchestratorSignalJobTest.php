<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem\Orchestrator;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Orchestrator\Routing\Approval\ProjectRoutingApprovalHandler;
use Kanvas\NervousSystem\Orchestrator\Webhooks\ProcessOrchestratorSignalJob;
use Kanvas\NervousSystem\Project\Actions\AddProjectMemberAction;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Enums\ProjectMemberRoleEnum;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForProjectJob;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Actions\CreateReceiverWebhookAction;
use Kanvas\Workflow\DataTransferObject\ReceiverWebhook as ReceiverWebhookData;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Kanvas\Workflow\Models\WorkflowAction;
use Laravel\Ai\StructuredAnonymousAgent;
use Tests\TestCase;

class ProcessOrchestratorSignalJobTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'social', 'workflow'];

    /**
     * @return array{0: Apps, 1: Companies, 2: Users}
     */
    private function context(): array
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        return [$app, $user->getCurrentCompany(), $user];
    }

    private function makeProject(Apps $app, Companies $company, Users $owner, string $title): Project
    {
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $owner->getId(), 'is_active' => true]);

        return new CreateProjectAction(
            ProjectData::from($app, $owner, $company, [
                'title' => $title,
                'objective' => "Deliver {$title}",
                'agent_id' => $agent->id,
            ]),
        )->execute();
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function orchestratorReceiver(Apps $app, Companies $company, Users $user, array $configuration): ReceiverWebhook
    {
        $action = WorkflowAction::firstOrCreate(
            ['model_name' => ProcessOrchestratorSignalJob::class],
            ['name' => 'Orchestrator Signal Ingest'],
        );

        return new CreateReceiverWebhookAction(
            new ReceiverWebhookData(
                app: $app,
                company: $company,
                user: $user,
                action: $action,
                name: 'Orchestrator ingest',
                description: 'Unlabeled signal routing',
                configuration: $configuration,
                is_active: true,
                run_async: true,
            ),
        )->execute();
    }

    private function callFor(ReceiverWebhook $receiver, array $payload): ReceiverWebhookCall
    {
        return ReceiverWebhookCall::create([
            'receiver_webhooks_id' => $receiver->getId(),
            'uuid' => Str::uuid()->toString(),
            'url' => '/receiver/' . $receiver->uuid,
            'payload' => $payload,
            'raw_payload' => json_encode($payload),
            'status' => 'pending',
        ]);
    }

    public function testForwardsToProjectWhenAttendeeIsAMember(): void
    {
        Bus::fake([WakeAgentForProjectJob::class]);

        [$app, $company, $user] = $this->context();
        $acme = $this->makeProject($app, $company, $user, 'Acme');
        $member = Users::factory()->create(['email' => 'greg@acme.io']);
        new AddProjectMemberAction(project: $acme, role: ProjectMemberRoleEnum::CONTRIBUTOR, user: $member)->execute();

        $receiver = $this->orchestratorReceiver($app, $company, $user, ['signal_source' => 'read_ai']);

        $payload = [
            'session_id' => 'sess_fwd',
            'title' => 'Acme rollout',
            'participants' => [['name' => 'Greg', 'email' => 'greg@acme.io']],
            'transcript' => ['speaker_blocks' => [['speaker' => ['name' => 'Greg'], 'words' => 'Ship Friday.']]],
        ];

        // No LLM fake — a sole deterministic member match forwards without the classifier.
        $result = new ProcessOrchestratorSignalJob($this->callFor($receiver, $payload))->execute();

        $this->assertSame('routed', $result['status']);
        $this->assertSame((int) $acme->id, (int) $result['project_id']);

        $this->assertDatabaseHas('nervous_system_events', [
            'event_type' => 'orchestrator.signal.routed',
            'source_entity_id' => $acme->id,
        ], 'intelligence');
    }

    public function testFansOutAcrossProjectsWhenSignalCoversDistinctTopics(): void
    {
        Bus::fake([WakeAgentForProjectJob::class]);

        [$app, $company, $user] = $this->context();
        $acme = $this->makeProject($app, $company, $user, 'Acme');
        $beta = $this->makeProject($app, $company, $user, 'Beta');

        $receiver = $this->orchestratorReceiver($app, $company, $user, ['signal_source' => 'read_ai']);

        // Two candidate projects → the job segments the signal (1 call), then classifies each segment.
        StructuredAnonymousAgent::fake([
            ['segments' => [
                ['title' => 'Acme launch', 'content' => 'Acme rollout ships Friday; design owns the banner.'],
                ['title' => 'Beta pricing', 'content' => 'Beta pricing tiers need finance sign-off next week.'],
            ]],
            ['project_id' => (int) $acme->id, 'confidence' => 0.9, 'reason' => 'Acme topic'],
            ['project_id' => (int) $beta->id, 'confidence' => 0.9, 'reason' => 'Beta topic'],
        ]);

        // Content must clear the segmenter's min-length gate.
        $longWords = str_repeat('We covered two separate initiatives across the leadership sync today. ', 40);

        $payload = [
            'session_id' => 'sess_fanout',
            'title' => 'Leadership sync',
            'participants' => [['name' => 'Exec', 'email' => 'exec@nomatch.io']],
            'transcript' => ['speaker_blocks' => [['speaker' => ['name' => 'Exec'], 'words' => $longWords]]],
        ];

        $result = new ProcessOrchestratorSignalJob($this->callFor($receiver, $payload))->execute();

        $this->assertSame('fan_out', $result['status']);
        $this->assertSame(2, $result['segments']);
        $this->assertSame(2, $result['projects']);

        // Each project received its own topic.
        $this->assertDatabaseHas('nervous_system_events', [
            'event_type' => 'orchestrator.signal.routed',
            'source_entity_id' => $acme->id,
        ], 'intelligence');
        $this->assertDatabaseHas('nervous_system_events', [
            'event_type' => 'orchestrator.signal.routed',
            'source_entity_id' => $beta->id,
        ], 'intelligence');
    }

    public function testFallsBackToAnotherAdapterWhenConfiguredSourceCannotParse(): void
    {
        Bus::fake([WakeAgentForProjectJob::class]);

        [$app, $company, $user] = $this->context();
        $acme = $this->makeProject($app, $company, $user, 'Acme');

        // Receiver pinned to read_ai, but the payload is a plain transcript → read_ai yields empty,
        // so the best-effort probe falls back to the plain adapter instead of ignoring the signal.
        $receiver = $this->orchestratorReceiver($app, $company, $user, ['signal_source' => 'read_ai']);

        StructuredAnonymousAgent::fake([[
            'project_id' => (int) $acme->id,
            'confidence' => 0.9,
            'reason' => 'Acme',
        ]]);

        $payload = [
            'type' => 'transcript',
            'transcript' => "Acme sync\nFri, Jul 24, 2026\n\n0:05 - Greg\nLet's ship the Acme rollout Friday.\n",
        ];

        $result = new ProcessOrchestratorSignalJob($this->callFor($receiver, $payload))->execute();

        $this->assertSame('routed', $result['status']);
        $this->assertSame((int) $acme->id, (int) $result['project_id']);
    }

    public function testDropsWhenClassifierReturnsNoProject(): void
    {
        [$app, $company, $user] = $this->context();
        $this->makeProject($app, $company, $user, 'Acme');

        $receiver = $this->orchestratorReceiver($app, $company, $user, ['signal_source' => 'read_ai']);

        // No member overlap → classifier runs; it decides no project fits.
        StructuredAnonymousAgent::fake([[
            'project_id' => 0,
            'confidence' => 0.0,
            'reason' => 'Internal FYI, no project.',
        ]]);

        $payload = [
            'session_id' => 'sess_drop',
            'title' => 'Team lunch',
            'participants' => [['name' => 'Someone', 'email' => 'someone@ourco.com']],
            'transcript' => ['speaker_blocks' => [['speaker' => ['name' => 'X'], 'words' => 'Lunch at noon.']]],
        ];

        $result = new ProcessOrchestratorSignalJob($this->callFor($receiver, $payload))->execute();

        $this->assertSame('dropped', $result['status']);
        // The drop result carries the "why" so a human reading it knows the reason.
        $this->assertSame('Internal FYI, no project.', $result['reason']);
        $this->assertDatabaseHas('nervous_system_events', [
            'event_type' => 'orchestrator.signal.dropped',
        ], 'intelligence');
    }

    public function testPostsProcessedSignalFeedLineToInbox(): void
    {
        Bus::fake([WakeAgentForProjectJob::class]);

        [$app, $company, $user] = $this->context();
        $acme = $this->makeProject($app, $company, $user, 'Acme');
        $inbox = $this->makeProject($app, $company, $user, 'Inbox');
        $member = Users::factory()->create(['email' => 'greg@acme.io']);
        new AddProjectMemberAction(project: $acme, role: ProjectMemberRoleEnum::CONTRIBUTOR, user: $member)->execute();

        $receiver = $this->orchestratorReceiver($app, $company, $user, [
            'signal_source' => 'read_ai',
            'inbox_project_id' => (int) $inbox->id,
            'orchestrator_agent_id' => (int) $inbox->agent_id,
        ]);

        // Sole member match forwards to Acme (no LLM); the Inbox should get a one-line "routed" feed entry.
        $payload = [
            'session_id' => 'sess_feed',
            'title' => 'Acme rollout',
            'participants' => [['name' => 'Greg', 'email' => 'greg@acme.io']],
            'transcript' => ['speaker_blocks' => [['speaker' => ['name' => 'Greg'], 'words' => 'Ship Friday.']]],
        ];

        $result = new ProcessOrchestratorSignalJob($this->callFor($receiver, $payload))->execute();
        $this->assertSame('routed', $result['status']);

        $feed = Message::query()
            ->whereHas('channels', fn ($q) => $q->where('channels.id', $inbox->defaultChannel->getId()))
            ->get()
            ->first(fn (Message $m) => str_contains((string) ($m->message['content'] ?? ''), 'routed to Acme'));

        $this->assertNotNull($feed, 'a processed-signal feed line should land in the Inbox channel');
        $this->assertStringContainsString('📥', (string) $feed->message['content']);
    }

    public function testApprovalEscalatesToInboxAsLockedRequest(): void
    {
        [$app, $company, $user] = $this->context();
        $target = $this->makeProject($app, $company, $user, 'Acme');
        $inbox = $this->makeProject($app, $company, $user, 'Inbox');

        $receiver = $this->orchestratorReceiver($app, $company, $user, [
            'signal_source' => 'read_ai',
            'inbox_project_id' => (int) $inbox->id,
            'orchestrator_agent_id' => (int) $inbox->agent_id,
        ]);

        // No member overlap → classifier runs; mid-confidence → APPROVAL suggesting $target.
        StructuredAnonymousAgent::fake([[
            'project_id' => (int) $target->id,
            'confidence' => 0.55,
            'reason' => 'probably Acme',
        ]]);

        $payload = [
            'session_id' => 'sess_appr',
            'title' => 'Ambiguous sync',
            'participants' => [['name' => 'Someone', 'email' => 'someone@nowhere.io']],
            'transcript' => ['speaker_blocks' => [['speaker' => ['name' => 'X'], 'words' => 'Some discussion.']]],
        ];

        $result = new ProcessOrchestratorSignalJob($this->callFor($receiver, $payload))->execute();

        $this->assertSame('approval', $result['status']);
        $this->assertSame((int) $target->id, (int) $result['suggested_project_id']);

        // A LOCKED approval request landed in the Inbox, naming the routing handler + suggested project.
        $message = Message::query()->where('id', $result['approval_message_id'])->first();
        $this->assertNotNull($message);
        $this->assertTrue($message->isLocked());
        $this->assertSame(ProjectRoutingApprovalHandler::class, $message->message['approval']['handler']);
        $this->assertSame((int) $target->id, (int) $message->message['approval']['context']['suggested_project_id']);
    }

    public function testErrorsWhenReceiverHasNoValidSource(): void
    {
        [$app, $company, $user] = $this->context();
        $receiver = $this->orchestratorReceiver($app, $company, $user, ['signal_source' => 'bogus']);

        $result = new ProcessOrchestratorSignalJob($this->callFor($receiver, ['session_id' => 'x']))->execute();

        $this->assertSame('error', $result['status']);
    }

    public function testIgnoresEmptyContent(): void
    {
        [$app, $company, $user] = $this->context();
        $receiver = $this->orchestratorReceiver($app, $company, $user, ['signal_source' => 'read_ai']);

        // Valid source, but no transcript/summary → nothing to route.
        $result = new ProcessOrchestratorSignalJob($this->callFor($receiver, ['session_id' => 'x', 'title' => 'Empty']))->execute();

        $this->assertSame('ignored', $result['status']);
    }
}
