<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

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
use Kanvas\Social\Messages\Events\MessageMentionsStoredEvent;
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
                && $job->reason === WakeAgentForProjectJob::REASON_MENTION,
        );
        // The generic mention responder must NOT also fire — no double answer.
        Bus::assertNotDispatched(RespondToMentionJob::class);
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
