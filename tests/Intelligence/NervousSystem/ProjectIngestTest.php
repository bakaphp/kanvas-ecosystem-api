<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\Actions\IngestToProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Enums\ProjectIngestTypeEnum;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForProjectJob;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class ProjectIngestTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        // The PM wake dispatched by ingest is exercised in ProjectExecutionTest with a stub agent;
        // here we only care about the message + ledger event, so keep the wake off the LLM path.
        Queue::fake([WakeAgentForProjectJob::class]);
    }

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
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);

        return new CreateProjectAction(
            ProjectData::from(
                $app,
                $user,
                $company,
                ['title' => 'Ingest test', 'agent_id' => $agent->id],
            ),
        )->execute();
    }

    public function testIngestTranscriptLandsOnChannelAndEmitsEvent(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);

        $message = new IngestToProjectAction(
            project: $project,
            type: ProjectIngestTypeEnum::TRANSCRIPT,
            content: 'Meeting notes: ship Friday, assign design to Ana.',
        )->execute();

        $this->assertSame(
            'Meeting notes: ship Friday, assign design to Ana.',
            $message->message['content'],
        );

        // The message is attached to one of the project's channels.
        $channelIds = $project->channels()->pluck('id')->all();
        $this->assertNotEmpty(array_intersect($channelIds, $message->channels()->pluck('channels.id')->all()));

        $this->assertDatabaseHas(
            'nervous_system_events',
            [
                'source_entity_type' => Project::class,
                'source_entity_id' => $project->id,
                'event_type' => 'project.transcript.received',
            ],
            'intelligence',
        );

        // Ingest wakes the PM agent to act on the new context.
        Queue::assertPushed(WakeAgentForProjectJob::class);
    }

    public function testAttachMessageToProjectViaGraphQL(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);

        $this->graphQL('
            mutation ($project_id: ID!, $content: String!, $type: String) {
                attachMessageToProject(project_id: $project_id, content: $content, type: $type) { id }
            }
        ', ['project_id' => $project->id, 'content' => 'Client asked for a dark mode.', 'type' => 'mention'])
            ->assertJsonMissingPath('errors')
            ->assertSuccessful();

        $this->assertDatabaseHas(
            'nervous_system_events',
            [
                'source_entity_type' => Project::class,
                'source_entity_id' => $project->id,
                'event_type' => 'project.mention.received',
            ],
            'intelligence',
        );
    }
}
