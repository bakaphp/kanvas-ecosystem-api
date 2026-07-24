<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Carbon\CarbonImmutable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Jobs\RespondToMentionJob;
use Kanvas\Intelligence\Agents\Listeners\RespondToAgentMentionListener;
use Kanvas\Intelligence\Agents\Models\Agent;
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
use Tests\TestCase;

class ProjectMentionRoutingTest extends TestCase
{
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
