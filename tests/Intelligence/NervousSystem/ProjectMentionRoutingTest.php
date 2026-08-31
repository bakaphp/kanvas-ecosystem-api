<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Jobs\RespondToMentionJob;
use Kanvas\Intelligence\Agents\Listeners\RespondToAgentMentionListener;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\Actions\PostPlanActivityMessageAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\Actions\PostProjectMessageAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForProjectJob;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Events\MessageMentionsStoredEvent;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Users\Models\Users;
use ReflectionMethod;
use Tests\TestCase;

class ProjectMentionRoutingTest extends TestCase
{
    // Inert without the trait: declared alone, every row this test writes COMMITS. These create
    // agents on the shared auth user, and a leaked agent makes Agent::fromUser() call a human an agent.
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

    private function makeProject(Apps $app, Companies $company, Users $user): Project
    {
        // The PM agent gets its OWN dedicated user (as in production) so Agent::fromUser resolves it
        // unambiguously — a shared auth user would collide with other tests' agents.
        $pmUser = Users::factory()->create();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $pmUser->getId(), 'is_active' => true]);

        return new CreateProjectAction(
            ProjectData::from(
                $app,
                $user,
                $company,
                ['title' => 'Mention routing', 'agent_id' => $agent->id],
            ),
        )->execute();
    }

    public function testMentioningPmOnProjectChannelRoutesToProjectLoop(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user)->refresh();

        $message = new PostProjectMessageAction(
            project: $project,
            verb: 'note',
            content: '@pm can you break this down?',
            author: $user,
        )->execute();

        $pmUserId = (int) $project->pmAgent->user_id;

        Bus::fake([WakeAgentForProjectJob::class, RespondToMentionJob::class]);

        new RespondToAgentMentionListener()->handle(
            new MessageMentionsStoredEvent($message, [$pmUserId]),
        );

        Bus::assertDispatched(
            WakeAgentForProjectJob::class,
            fn (WakeAgentForProjectJob $job): bool =>
                $job->project->id === $project->id
                && $job->reason === WakeAgentForProjectJob::REASON_MENTION
                // The reply must thread under the mentioning message.
                && (int) $job->triggerMessageId === (int) $message->getId(),
        );
        // The generic mention responder must NOT also fire — no double answer.
        Bus::assertNotDispatched(RespondToMentionJob::class);
    }

    public function testMentioningPmOnPlanChannelRoutesToProjectLoopFocusedOnThePlan(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user)->refresh();

        // A plan under the project — its `created` observer makes a Plan-namespace Activities channel.
        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'GA Group Proposal & Sales Cycle',
                planType: 'project_work',
                user: $user,
                status: PlanStatusEnum::BLOCKED,
                project: $project,
            ),
        )->execute();

        // Someone @mentions the PM on the PLAN's activity thread — NOT the project channel.
        $message = new PostPlanActivityMessageAction(
            plan: $plan,
            content: '@pm thoughts?',
            author: $user,
            verb: 'note',
        )->execute();

        $this->assertNotNull($message, 'precondition: plan activity message posted');
        $this->assertTrue(
            $message->channels()->where('entity_namespace', Plan::class)->exists(),
            'Precondition: the mention is on the plan channel.',
        );
        $this->assertFalse(
            $message->channels()->where('entity_namespace', Project::class)->exists(),
            'Precondition: the mention is NOT on the project channel (that path is already covered).',
        );

        $pmUserId = (int) $project->pmAgent->user_id;

        Bus::fake([WakeAgentForProjectJob::class, RespondToMentionJob::class]);

        new RespondToAgentMentionListener()->handle(
            new MessageMentionsStoredEvent($message, [$pmUserId]),
        );

        Bus::assertDispatched(
            WakeAgentForProjectJob::class,
            fn (WakeAgentForProjectJob $job): bool =>
                $job->project->id === $project->id
                && $job->reason === WakeAgentForProjectJob::REASON_MENTION
                // The trigger pins the reply to THIS plan so the PM answers about it, not the whole board.
                && str_contains((string) $job->triggerMessage, 'PLAN #' . $plan->getId()),
        );
        // The generic mention responder (project-blind) must NOT also fire — no double, unfocused answer.
        Bus::assertNotDispatched(RespondToMentionJob::class);
    }

    public function testMentionWakeRepliesOnTheTriggerMessageChannelNotTheProjectDefault(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user)->refresh();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Channel-scoped plan',
                planType: 'project_work',
                user: $user,
                status: PlanStatusEnum::ACTIVE,
                project: $project,
            ),
        )->execute();

        $mention = new PostPlanActivityMessageAction(
            plan: $plan,
            content: '@pm thoughts?',
            author: $user,
            verb: 'note',
        )->execute();

        $planChannelId = (int) $plan->socialChannels->first()->getId();
        $this->assertNotSame($planChannelId, (int) $project->default_channel_id, 'Precondition: plan channel differs from project default.');

        $resolve = new ReflectionMethod(WakeAgentForProjectJob::class, 'resolveReplyChannel');

        // A mention wake answers on the trigger message's channel — the plan thread, so the person sees it.
        $channel = $resolve->invoke(new WakeAgentForProjectJob(
            $project,
            WakeAgentForProjectJob::REASON_MENTION,
            'trigger',
            (int) $mention->getId(),
        ));
        $this->assertNotNull($channel);
        $this->assertSame($planChannelId, (int) $channel->getId());

        // Non-mention wakes (ingest/heartbeat/assigned/plan outcome) keep posting to the project
        // default channel. It is named here rather than left to the post action to resolve, because
        // the wake baselines this channel to tell whether the agent already answered on it mid-turn.
        $ingest = $resolve->invoke(new WakeAgentForProjectJob(
            $project,
            WakeAgentForProjectJob::REASON_INGEST,
            'trigger',
            (int) $mention->getId(),
        ));
        $this->assertSame((int) $project->default_channel_id, (int) $ingest?->getId());

        // A mention with no trigger message also falls back to the default channel.
        $noTrigger = $resolve->invoke(new WakeAgentForProjectJob(
            $project,
            WakeAgentForProjectJob::REASON_MENTION,
            null,
            null,
        ));
        $this->assertSame((int) $project->default_channel_id, (int) $noTrigger?->getId());
    }

    public function testMentioningANonPmAgentOnProjectChannelFallsThroughToGenericResponder(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user)->refresh();

        // A different agent (NOT the project's PM) is mentioned on the project channel. The Project's
        // own eligibility gate (agent_id must match) must decline, so it uses the default reply.
        $otherUser = Users::factory()->create();
        $otherAgent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $otherUser->getId(), 'is_active' => true]);

        $message = new PostProjectMessageAction(
            project: $project,
            verb: 'note',
            content: '@someone can you help?',
            author: $user,
        )->execute();

        Bus::fake([WakeAgentForProjectJob::class, RespondToMentionJob::class]);

        new RespondToAgentMentionListener()->handle(
            new MessageMentionsStoredEvent($message, [(int) $otherAgent->user_id]),
        );

        // Not the PM → the project loop must NOT fire; the generic responder answers instead.
        Bus::assertNotDispatched(WakeAgentForProjectJob::class);
        Bus::assertDispatched(
            RespondToMentionJob::class,
            fn (RespondToMentionJob $job): bool => (int) $job->agent->getId() === (int) $otherAgent->getId(),
        );
    }

    public function testMentioningPmInsideAThreadStillRoutesToProjectLoop(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user)->refresh();

        // Root message on the project channel.
        $root = new PostProjectMessageAction(
            project: $project,
            verb: 'note',
            content: 'Kicking off the project',
            author: $user,
        )->execute();

        // A threaded reply that mentions the PM — created via the generic message path with ONLY
        // parent_id (no channel_slug), exactly as a UI thread reply that isn't re-tagged onto the
        // project channel. This is the case the parent-walk exists to cover.
        $messageType = new CreateMessageTypeAction(
            new MessageTypeInput($app->getId(), 0, 'note', 'note'),
        )->execute();

        $createReply = new CreateMessageAction(
            new MessageInput(
                app: $app,
                company: $company,
                user: $user,
                type: $messageType,
                message: ['content' => '@pm can you break this down?', 'from_ia' => false],
                parent_id: (int) $root->getId(),
            ),
        );
        $createReply->runWorkflow = false;
        $reply = $createReply->execute();

        // The reply must NOT be on the project channel — otherwise this wouldn't prove the parent walk.
        $this->assertFalse(
            $reply->channels()->where('entity_namespace', Project::class)->exists(),
            'Precondition: threaded reply should not carry the project channel directly.',
        );

        $pmUserId = (int) $project->pmAgent->user_id;

        Bus::fake([WakeAgentForProjectJob::class, RespondToMentionJob::class]);

        new RespondToAgentMentionListener()->handle(
            new MessageMentionsStoredEvent($reply, [$pmUserId]),
        );

        Bus::assertDispatched(
            WakeAgentForProjectJob::class,
            fn (WakeAgentForProjectJob $job): bool =>
                $job->project->id === $project->id
                // Reply threads under the ROOT, not the in-thread mention — the thread stays flat.
                && (int) $job->triggerMessageId === (int) $root->getId(),
        );
        Bus::assertNotDispatched(RespondToMentionJob::class);
    }

    public function testMentionWakeReleasesAndRetriesWhileAutomatedWakesDrop(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user)->refresh();

        $mentionLock = $this->overlappingMiddleware(
            new WakeAgentForProjectJob($project, WakeAgentForProjectJob::REASON_MENTION),
        );
        $ingestLock = $this->overlappingMiddleware(
            new WakeAgentForProjectJob($project, WakeAgentForProjectJob::REASON_INGEST),
        );

        // Same per-project key so the two reasons still serialize against each other.
        $this->assertSame('project-wake-' . $project->getId(), $mentionLock->key);
        $this->assertSame('project-wake-' . $project->getId(), $ingestLock->key);

        // A human @mention re-queues on collision (never silently dropped); automated wakes drop.
        $this->assertSame(15, $mentionLock->releaseAfter);
        $this->assertNull($ingestLock->releaseAfter);

        // Both share the lock TTL so a crashed holder can't wedge the project forever.
        $this->assertSame(600, $mentionLock->expiresAfter);
        $this->assertSame(600, $ingestLock->expiresAfter);
    }

    public function testHeartbeatWakePromptInvitesNoUpdateButMentionDoesNot(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user)->refresh();

        $build = new ReflectionMethod(WakeAgentForProjectJob::class, 'buildMessage');

        $heartbeat = $build->invoke(new WakeAgentForProjectJob($project, WakeAgentForProjectJob::REASON_HEARTBEAT));
        $this->assertStringContainsString('NO_UPDATE', $heartbeat, 'heartbeat wake must let the PM stay silent');
        $this->assertStringContainsString('periodic check-in', $heartbeat);

        // A human @mention is a direct question — never invite a no-op there.
        $mention = $build->invoke(new WakeAgentForProjectJob($project, WakeAgentForProjectJob::REASON_MENTION, 'hi', 1));
        $this->assertStringNotContainsString('NO_UPDATE', $mention);
    }

    public function testNoOpResponseDetectionSuppressesEmptyAndSentinelOnly(): void
    {
        $isNoOp = new ReflectionMethod(WakeAgentForProjectJob::class, 'isNoOpResponse');
        $job = new WakeAgentForProjectJob(new Project(), WakeAgentForProjectJob::REASON_HEARTBEAT);

        foreach (['NO_UPDATE', '**NO_UPDATE**', "NO_UPDATE.\n", '  no_update  ', '', '   '] as $silent) {
            $this->assertTrue($isNoOp->invoke($job, $silent), "should suppress: '{$silent}'");
        }

        // A real status update still posts.
        $this->assertFalse($isNoOp->invoke($job, 'Plan 34 moved to done. Task 12 assigned to Max.'));
        $this->assertFalse($isNoOp->invoke($job, 'Everything is synchronized and ready for the workweek.'));
    }

    public function testMentionRetryWindowOutlastsAutomatedWakes(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user)->refresh();

        CarbonImmutable::setTestNow('2026-07-23 12:00:00');

        $mentionUntil = new WakeAgentForProjectJob($project, WakeAgentForProjectJob::REASON_MENTION)->retryUntil();
        $ingestUntil = new WakeAgentForProjectJob($project, WakeAgentForProjectJob::REASON_INGEST)->retryUntil();

        // Mention retries for the full lock-TTL window (600s); automated wakes cap failure retries short (90s).
        $this->assertSame(600, (int) now()->diffInSeconds($mentionUntil));
        $this->assertSame(90, (int) now()->diffInSeconds($ingestUntil));

        CarbonImmutable::setTestNow();
    }

    private function overlappingMiddleware(WakeAgentForProjectJob $job): WithoutOverlapping
    {
        foreach ($job->middleware() as $middleware) {
            if ($middleware instanceof WithoutOverlapping) {
                return $middleware;
            }
        }

        $this->fail('WakeAgentForProjectJob is missing its WithoutOverlapping middleware.');
    }

    public function testListenerSkipsProjectIngestMessagesToAvoidDoubleWake(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user)->refresh();

        // An ingest message (carries ingest_type) — IngestToProjectAction already owns its wake.
        $message = new PostProjectMessageAction(
            project: $project,
            verb: 'meeting-transcript',
            content: 'meeting notes mentioning @pm',
            author: $user,
            extraPayload: ['ingest_type' => 'transcript'],
        )->execute();

        $pmUserId = (int) $project->pmAgent->user_id;

        Bus::fake([WakeAgentForProjectJob::class, RespondToMentionJob::class]);

        new RespondToAgentMentionListener()->handle(
            new MessageMentionsStoredEvent($message, [$pmUserId]),
        );

        // Neither path fires — the ingest already handled the wake.
        Bus::assertNotDispatched(WakeAgentForProjectJob::class);
        Bus::assertNotDispatched(RespondToMentionJob::class);
    }
}
