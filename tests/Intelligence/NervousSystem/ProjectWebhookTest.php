<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForProjectJob;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Project\Webhooks\ProcessProjectWebhookJob;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Tests\TestCase;

class ProjectWebhookTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        // Webhook ingest dispatches the PM wake — keep it off the LLM path here.
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
                ['title' => 'Webhook test', 'agent_id' => $agent->id],
            ),
        )->execute();
    }

    public function testProjectProvisionsWebhookOnCreate(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user)->refresh();

        $this->assertNotNull($project->receiver_webhook_id, 'project should have a bound inbound webhook');
        $this->assertNotNull($project->webhook_url);
        $this->assertStringContainsString('/receiver/', (string) $project->webhook_url);
    }

    public function testWebhookJobIngestsTranscriptIntoProject(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user)->refresh();

        $receiver = $project->receiverWebhook;
        $this->assertNotNull($receiver);

        $payload = ['type' => 'transcript', 'transcript' => 'Ship Friday; assign design to Ana.'];
        $call = ReceiverWebhookCall::create([
            'receiver_webhooks_id' => $receiver->getId(),
            'uuid' => Str::uuid()->toString(),
            'url' => '/receiver/' . $receiver->uuid,
            'payload' => $payload,
            'raw_payload' => json_encode($payload),
            'status' => 'pending',
        ]);

        $result = new ProcessProjectWebhookJob($call)->execute();

        $this->assertSame('success', $result['status']);
        $this->assertSame('transcript', $result['type']);

        $this->assertDatabaseHas(
            'nervous_system_events',
            [
                'source_entity_type' => Project::class,
                'source_entity_id' => $project->id,
                'event_type' => 'project.transcript.received',
            ],
            'intelligence',
        );

        Queue::assertPushed(WakeAgentForProjectJob::class);
    }
}
