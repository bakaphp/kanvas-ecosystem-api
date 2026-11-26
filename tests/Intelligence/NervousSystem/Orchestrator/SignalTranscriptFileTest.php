<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem\Orchestrator;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FileTextExtractor;
use Kanvas\Intelligence\Agents\Jobs\WakeAgentInSessionJob;
use Kanvas\Intelligence\Agents\Neuron\CRM\SalesManagerAgent;
use Kanvas\NervousSystem\Orchestrator\Webhooks\ProcessOrchestratorSignalJob;
use Kanvas\NervousSystem\Project\Actions\AddProjectMemberAction;
use Kanvas\NervousSystem\Project\Enums\ProjectIngestTypeEnum;
use Kanvas\NervousSystem\Project\Enums\ProjectMemberRoleEnum;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForProjectJob;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Social\Messages\Actions\ApproveAgentMessageAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Laravel\Ai\StructuredAnonymousAgent;
use Tests\Intelligence\NervousSystem\Orchestrator\Concerns\MakesMeetingRoutingFixtures;
use Tests\TestCase;

/**
 * A meeting's full transcript is one stored file attached to every message the meeting is routed to;
 * the message itself only summarizes it. When storage refuses the file the transcript stays in the
 * message.
 */
class SignalTranscriptFileTest extends TestCase
{
    use DatabaseTransactions;
    use MakesMeetingRoutingFixtures;

    protected array $connectionsToTransact = ['mysql', 'crm', 'hr', 'intelligence', 'social', 'workflow'];

    private FilesystemAdapter $disk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disk = $this->fakeFileStorage();
    }

    public function testALeadMeetingKeepsItsTranscriptInAFileOnTheNote(): void
    {
        Bus::fake([WakeAgentInSessionJob::class]);

        [$receiver, $buyer] = $this->leadReceiver();

        $result = new ProcessOrchestratorSignalJob($this->callFor($receiver, $this->summarizedPayload($buyer)))
            ->execute();

        $this->assertSame('routed', $result['status']);

        $note = Message::query()->findOrFail($result['message_id']);
        $file = $this->soleFileOf($note);
        $body = (string) $note->message['content'];

        $this->assertStringEndsWith('.md', $file->name);
        $this->assertStringContainsString('filesystem_id ' . $file->getId(), $body);
        $this->assertStringContainsString('They want the pro plan by March.', $body, 'the summary stays in the note');
        $this->assertStringNotContainsString('We need forty seats', $body, 'the transcript itself moved to the file');
        $this->assertStringContainsString('Buyer: We need forty seats', $this->storedText());

        $this->assertTrue(
            new FileTextExtractor()->supports($file),
            'read_file refuses a type the extractor does not support'
        );

        Bus::assertDispatched(
            WakeAgentInSessionJob::class,
            fn (WakeAgentInSessionJob $job): bool => str_contains($job->instruction, 'read_file(filesystem_id: ' . $file->getId() . ')')
                && ! str_contains($job->instruction, 'read_message_content')
        );
    }

    public function testAnUploadTheStorageRefusesLeavesTheTranscriptInTheNote(): void
    {
        Bus::fake([WakeAgentInSessionJob::class]);
        $this->refuseFileStorage();

        [$receiver, $buyer] = $this->leadReceiver();

        $result = new ProcessOrchestratorSignalJob($this->callFor($receiver, $this->summarizedPayload($buyer)))
            ->execute();

        $this->assertSame('routed', $result['status']);

        $note = Message::query()->findOrFail($result['message_id']);

        $this->assertSame(0, $note->files()->count());
        $this->assertStringContainsString("Transcript:\nBuyer: We need forty seats", (string) $note->message['content']);

        Bus::assertDispatched(
            WakeAgentInSessionJob::class,
            fn (WakeAgentInSessionJob $job): bool => str_contains($job->instruction, 'read_message_content(message_id: ' . $note->getId() . ')')
        );
    }

    public function testAMeetingWithNoAgentToReadItStoresNoFile(): void
    {
        [$receiver, $buyer] = $this->leadReceiver(withSalesManager: false);

        $result = new ProcessOrchestratorSignalJob($this->callFor($receiver, $this->summarizedPayload($buyer)))
            ->execute();

        $this->assertSame('skipped', $result['status']);
        $this->assertSame([], $this->disk->allFiles());
    }

    public function testEveryProjectOfASplitMeetingGetsTheWholeTranscript(): void
    {
        Bus::fake([WakeAgentForProjectJob::class]);

        $acme = $this->project('Acme');
        $beta = $this->project('Beta');
        $receiver = $this->receiver();

        StructuredAnonymousAgent::fake([
            ['segments' => [
                ['title' => 'Acme launch', 'content' => 'Acme rollout ships Friday; design owns the banner.'],
                ['title' => 'Beta pricing', 'content' => 'Beta pricing tiers need finance sign-off next week.'],
            ]],
            ['candidate' => 'project:' . $acme->id, 'confidence' => 0.9, 'reason' => 'Acme topic'],
            ['candidate' => 'project:' . $beta->id, 'confidence' => 0.9, 'reason' => 'Beta topic'],
        ]);

        // Past the 12k characters the segmenter reads, so the closing line reaches no segment.
        $words = str_repeat('We covered two separate initiatives across the leadership sync today. ', 200)
            . 'Closing decision: hire two engineers.';

        $result = new ProcessOrchestratorSignalJob($this->callFor($receiver, [
            'session_id' => 'sess_' . uniqid(),
            'title' => 'Leadership sync',
            'participants' => [['name' => 'Exec', 'email' => 'exec@nomatch.io']],
            'transcript' => ['speaker_blocks' => [['speaker' => ['name' => 'Exec'], 'words' => $words]]],
        ]))->execute();

        $this->assertSame('fan_out', $result['status']);

        $messages = Message::query()->whereIn('id', array_column($result['results'], 'message_id'))->get();
        $this->assertCount(2, $messages);

        $fileIds = $messages
            ->map(fn (Message $message): int => $this->soleFileOf($message)->getId())
            ->unique();
        $this->assertCount(1, $fileIds, 'both projects attach the same stored file');
        $this->assertCount(1, $this->disk->allFiles());
        $this->assertStringContainsString('Closing decision: hire two engineers.', $this->storedText());

        $acmeBody = (string) $messages->first(
            fn (Message $message): bool => str_contains((string) $message->message['content'], 'Acme rollout')
        )?->message['content'];
        $this->assertStringContainsString("Relevant part:\nAcme rollout ships Friday", $acmeBody);
        $this->assertStringContainsString('filesystem_id ' . $fileIds->first(), $acmeBody);

        Bus::assertDispatched(
            WakeAgentForProjectJob::class,
            fn (WakeAgentForProjectJob $job): bool => str_contains((string) $job->triggerMessage, 'filesystem_id ' . $fileIds->first())
        );
    }

    public function testTheSameWebhookDeliveredTwiceStoresOneFile(): void
    {
        Bus::fake([WakeAgentForProjectJob::class]);

        $acme = $this->project('Acme');
        $member = Users::factory()->create(['email' => $this->uniqueEmail('acme-member.io')]);
        new AddProjectMemberAction(project: $acme, role: ProjectMemberRoleEnum::CONTRIBUTOR, user: $member)
            ->execute();
        $receiver = $this->receiver();

        $payload = [
            'session_id' => 'sess_' . uniqid(),
            'title' => 'Acme rollout',
            'participants' => [['name' => 'Greg', 'email' => $member->email]],
            'transcript' => ['speaker_blocks' => [['speaker' => ['name' => 'Greg'], 'words' => 'Ship Friday.']]],
        ];

        $first = new ProcessOrchestratorSignalJob($this->callFor($receiver, $payload))->execute();
        $again = new ProcessOrchestratorSignalJob($this->callFor($receiver, $payload))->execute();

        $this->assertSame('routed', $first['status']);
        $this->assertSame('duplicate', $again['status']);
        $this->assertCount(1, $this->disk->allFiles());
    }

    public function testAHeldMeetingCarriesItsFileToTheProjectAPersonApproves(): void
    {
        Bus::fake([WakeAgentForProjectJob::class]);

        $target = $this->project('Acme');
        $inbox = $this->project('Inbox');

        $receiver = $this->receiver([
            'signal_source' => 'read_ai',
            'inbox_project_id' => (int) $inbox->id,
            'orchestrator_agent_id' => (int) $inbox->agent_id,
        ]);

        StructuredAnonymousAgent::fake([[
            'candidate' => 'project:' . $target->id,
            'confidence' => 0.55,
            'reason' => 'probably Acme',
        ]]);

        $result = new ProcessOrchestratorSignalJob($this->callFor($receiver, [
            'session_id' => 'sess_' . uniqid(),
            'title' => 'Ambiguous sync',
            'participants' => [['name' => 'Someone', 'email' => 'someone@nowhere.io']],
            'transcript' => ['speaker_blocks' => [['speaker' => ['name' => 'X'], 'words' => 'Some discussion.']]],
        ]))->execute();

        $this->assertSame('approval', $result['status']);

        $request = Message::query()->findOrFail($result['approval_message_id']);
        $file = $this->soleFileOf($request);
        $this->assertSame($file->getId(), (int) $request->message['approval']['context']['filesystem_id']);

        new ApproveAgentMessageAction(message: $request, context: ['project_id' => (int) $target->id])
            ->execute();

        $ingested = Message::query()
            ->whereHas(
                'channels',
                fn ($query) => $query->where('channels.id', $target->refresh()->default_channel_id)
            )
            ->get()
            ->first(fn (Message $message): bool => ($message->message['ingest_type'] ?? null) === 'transcript');

        $this->assertNotNull($ingested);
        $this->assertSame($file->getId(), $this->soleFileOf($ingested)->getId());
        $this->assertStringContainsString('filesystem_id ' . $file->getId(), (string) $ingested->message['content']);
    }

    public function testOnlyATranscriptIsStoredAsAFile(): void
    {
        $this->assertTrue(ProjectIngestTypeEnum::TRANSCRIPT->isStoredAsFile());
        $this->assertFalse(ProjectIngestTypeEnum::EMAIL->isStoredAsFile());
        $this->assertFalse(ProjectIngestTypeEnum::MENTION->isStoredAsFile());
    }

    public function testTheFileIsNamedAfterTheMeetingAndItsDay(): void
    {
        $this->assertSame('meeting-discovery-call-2026-11-16.md', $this->meetingSignal([])->fileName());
        $this->assertSame('meeting-2026-11-16.md', $this->meetingSignal([], '???')->fileName());
    }

    private function project(string $title): Project
    {
        $user = $this->testUser();

        return $this->makeProject(
            app(Apps::class),
            $user->getCurrentCompany(),
            $user,
            $title
        );
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function receiver(array $configuration = ['signal_source' => 'read_ai']): ReceiverWebhook
    {
        $user = $this->testUser();

        return $this->orchestratorReceiver(
            app(Apps::class),
            $user->getCurrentCompany(),
            $user,
            $configuration
        );
    }

    /**
     * @return array{0: ReceiverWebhook, 1: string} the receiver and the buyer's email
     */
    private function leadReceiver(bool $withSalesManager = true): array
    {
        $company = $this->freshCompany();
        $buyer = $this->uniqueEmail();
        $this->lead($company, $this->person($company, $buyer));

        if ($withSalesManager) {
            $this->agentOfType($company, SalesManagerAgent::class);
        }

        $receiver = $this->orchestratorReceiver(
            app(Apps::class),
            $company,
            $this->testUser(),
            [
                'signal_source' => 'read_ai',
                'destinations' => ['lead'],
            ],
        );

        return [$receiver, $buyer];
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizedPayload(string $buyer): array
    {
        return [
            'session_id' => 'sess_' . uniqid(),
            'trigger' => 'meeting_end',
            'title' => 'Pricing call',
            'start_time' => '2026-11-16T15:00:00Z',
            'participants' => [['name' => 'Buyer', 'email' => $buyer]],
            'summary' => 'They want the pro plan by March.',
            'transcript' => ['speaker_blocks' => [['speaker' => ['name' => 'Buyer'], 'words' => 'We need forty seats.']]],
        ];
    }

    private function soleFileOf(Message $message): Filesystem
    {
        $files = $message->files()->get();
        $this->assertCount(1, $files, 'the message should attach exactly one file');

        return $files->first();
    }

    private function storedText(): string
    {
        $paths = $this->disk->allFiles();
        $this->assertCount(1, $paths);

        return (string) $this->disk->get($paths[0]);
    }
}
