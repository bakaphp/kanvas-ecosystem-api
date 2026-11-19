<?php

declare(strict_types=1);

namespace Tests\Meeting;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasGeminiTranscribe;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Meeting\Meetings\Models\Meeting;
use Kanvas\Meeting\Notes\Jobs\GenerateMeetingNotesJob;
use Kanvas\Meeting\Recordings\Actions\AttachMeetingRecordingAction;
use Kanvas\Meeting\Recordings\DataTransferObject\MeetingRecording as MeetingRecordingData;
use Kanvas\Meeting\Transcripts\Actions\CreateMeetingTranscriptAction;
use Kanvas\Meeting\Transcripts\Actions\TranscribeMeetingAction;
use Kanvas\Meeting\Transcripts\Agents\TranscriptAgent;
use Kanvas\Meeting\Transcripts\DataTransferObject\MeetingTranscript as MeetingTranscriptData;
use Kanvas\Meeting\Transcripts\Exceptions\TranscriptProviderException;
use Kanvas\Meeting\Transcripts\Jobs\TranscribeMeetingJob;
use Kanvas\Meeting\Transcripts\Services\TranscriptAgentService;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use Tests\Stubs\Intelligence\FakeNeuronProvider;
use Tests\TestCase;

class TranscribeMeetingTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'meeting', 'intelligence'];

    private function recordedMeeting(): Meeting
    {
        $audio = sys_get_temp_dir() . '/meeting-test-' . uniqid() . '.webm';
        $made = Process::run([
            'ffmpeg', '-y', '-loglevel', 'error',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1',
            '-c:a', 'libopus', $audio,
        ]);

        if ($made->failed()) {
            $this->markTestSkipped('ffmpeg is not available to make a recording');
        }

        $disk = (string) config('filesystems.default');
        Storage::fake($disk);

        $meeting = Meeting::query()->create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
            'users_id' => auth()->user()->getId(),
            'title' => 'Weekly sync',
        ]);
        $key = 'meetings/' . $meeting->uuid . '/audio.webm';
        Storage::disk($disk)->put($key, (string) file_get_contents($audio));

        new AttachMeetingRecordingAction(
            MeetingRecordingData::from($meeting, [
                'storage_key' => $key,
                'mime_type' => 'audio/webm',
                'size_bytes' => filesize($audio),
                'sha256' => hash_file('sha256', $audio),
            ]),
        )->execute();
        unlink($audio);

        return $meeting;
    }

    private function agentAnswering(string $reply): TranscriptAgent
    {
        return new TranscriptAgent()->runWith(new FakeNeuronProvider($reply), 'fake');
    }

    public function testARecordingBecomesSpeakerSegmentsAndCompletesTheMeeting(): void
    {
        Bus::fake([GenerateMeetingNotesJob::class]);
        $meeting = $this->recordedMeeting();
        $reply = json_encode(['segments' => [
            ['speaker' => 'spk:0', 'text' => 'Buenos días a todos.', 'start_ms' => 0, 'end_ms' => 1500],
            ['speaker' => 'spk:1', 'text' => 'Gracias.'],
            ['speaker' => 'spk:0', 'text' => str_repeat('Una frase larga de la reunión. ', 200)],
        ]]);

        $transcript = new TranscribeMeetingAction($meeting, $this->agentAnswering("```json\n" . $reply . "\n```"))->execute();
        $segments = $transcript->segments()->with('speaker')->orderBy('position')->get();

        $this->assertSame('completed', $transcript->status->value);
        $this->assertSame('fake', $transcript->provider);
        $this->assertSame('fake-model', $transcript->model);
        $this->assertSame('completed', $meeting->refresh()->status->value);
        $this->assertSame('Buenos días a todos.', $segments[0]->text);
        $this->assertSame([0, 1500], [$segments[0]->start_ms, $segments[0]->end_ms]);
        $this->assertSame('spk:1', $segments[1]->speaker->provider_label);
        $this->assertNull($segments[1]->start_ms);
        $this->assertSame($segments[0]->speaker->getId(), $segments[2]->speaker->getId());
        $this->assertGreaterThan(3, $segments->count());
        $this->assertLessThanOrEqual(4000, $segments->max(fn ($segment) => mb_strlen($segment->text)));
        $this->assertCount(2, $transcript->speakers()->get());
        Bus::assertDispatched(
            GenerateMeetingNotesJob::class,
            fn (GenerateMeetingNotesJob $job): bool => $job->transcript->getId() === $transcript->getId()
                && $job->queue === GenerateMeetingNotesJob::QUEUE
        );

        $blocks = FakeNeuronProvider::$lastMessages[0]->getContentBlocks();
        $audio = array_values(array_filter($blocks, fn ($block) => $block instanceof AudioContent));
        $this->assertCount(1, $audio);
        $this->assertSame('audio/webm', $audio[0]->mediaType);
    }

    public function testARunThatBreaksOrHearsNothingIsStoredAsAFailedTranscript(): void
    {
        Bus::fake([GenerateMeetingNotesJob::class]);
        $meeting = $this->recordedMeeting();

        $broken = new TranscribeMeetingAction($meeting, $this->agentAnswering('I could not hear that.'))->execute();
        $this->assertSame(['failed', TranscribeMeetingAction::ERROR_FAILED], [$broken->status->value, $broken->error_code]);
        $this->assertSame('failed', $meeting->refresh()->status->value);

        $silent = new TranscribeMeetingAction($meeting, $this->agentAnswering('{"segments": []}'))->execute();
        $this->assertSame(['failed', TranscribeMeetingAction::ERROR_NO_SPEECH], [$silent->status->value, $silent->error_code]);
        $this->assertSame(2, $silent->version);
        Bus::assertNotDispatched(GenerateMeetingNotesJob::class);
    }

    public function testTheTranscribeProviderAnswersWithOneSegmentPerSpeakerTurn(): void
    {
        $provider = new class ('key', TranscriptAgent::DEFAULT_MODEL, wordTimestamps: true) extends KanvasGeminiTranscribe {
            public function read(array $result): string
            {
                return (string) $this->processChatResult($result)->getContent();
            }
        };

        $segments = json_decode($provider->read(['candidates' => [[
            'finishReason' => 'STOP',
            'content' => ['role' => 'model', 'parts' => [
                ['text' => 'Hola a todos.', 'audioTranscription' => [
                    'text' => 'Hola a todos.',
                    'speakerLabel' => 'spk:0',
                    'words' => [
                        ['word' => 'Hola', 'startOffset' => '0s', 'endOffset' => '0.400s'],
                        ['word' => 'todos.', 'startOffset' => '0.800s', 'endOffset' => '1.500s'],
                    ],
                ]],
                ['audioTranscription' => ['text' => 'Gracias.', 'speakerLabel' => 'spk:1']],
            ]],
        ]]]), true)['segments'];

        $this->assertSame(['speaker' => 'spk:0', 'text' => 'Hola a todos.', 'start_ms' => 0, 'end_ms' => 1500], $segments[0]);
        $this->assertSame(['speaker' => 'spk:1', 'text' => 'Gracias.', 'start_ms' => null, 'end_ms' => null], $segments[1]);
        $silence = ['candidates' => [['finishReason' => 'STOP', 'index' => 0]]];
        $this->assertSame('{"segments":[]}', $provider->read($silence));

        $this->assertTrue(KanvasGeminiTranscribe::supports('gemini-3.5-transcribe'));
        $this->assertFalse(KanvasGeminiTranscribe::supports('gemini-3.8-flash'));
    }

    public function testAJobTheQueueFailsWithoutRunningLeavesNoTranscriptInProgress(): void
    {
        $meeting = $this->recordedMeeting();
        $transcript = new CreateMeetingTranscriptAction(
            new MeetingTranscriptData($meeting, 'gemini', TranscriptAgent::DEFAULT_MODEL),
        )->execute();
        $job = new TranscribeMeetingJob($meeting);

        $job->failed(new MaxAttemptsExceededException('The worker was killed'));

        $this->assertSame(
            ['failed', TranscribeMeetingAction::ERROR_INTERRUPTED],
            [$transcript->refresh()->status->value, $transcript->error_code]
        );
        $this->assertSame('failed', $meeting->refresh()->status->value);
        $this->assertSame((string) $meeting->getId(), $job->uniqueId());
    }

    private function companyAgent(Meeting $meeting, array $config): Agent
    {
        $type = AgentType::factory()
            ->withAppId($meeting->apps_id)
            ->create(['provider' => 'neuron', 'handler' => TranscriptAgent::class]);

        return Agent::factory()
            ->withAppId($meeting->apps_id)
            ->withCompanyId($meeting->companies_id)
            ->create([
                'name' => 'Transcriber ' . uniqid(),
                'user_id' => auth()->user()->getId(),
                'agent_type_id' => $type->getId(),
                'config' => $config,
            ]);
    }

    public function testTheCompanyAgentsProviderIsTheOneThatRuns(): void
    {
        $meeting = $this->recordedMeeting();
        $service = new TranscriptAgentService();
        $agent = $this->companyAgent($meeting, ['llm_provider' => 'gemini', 'key' => 'agent-key', 'model' => 'gemini-3.8-flash']);

        $chat = $service->handlerFor($meeting);
        $this->assertSame(['gemini', 'gemini-3.8-flash'], [$chat->providerName(), $chat->resolvedModelName()]);

        $agent->config = ['llm_provider' => 'gemini', 'key' => 'agent-key'];
        $agent->saveOrFail();
        $this->assertSame(TranscriptAgent::DEFAULT_MODEL, $service->handlerFor($meeting)->resolvedModelName());

        $agent->config = ['llm_provider' => 'mistral', 'key' => 'agent-key', 'model' => 'voxtral-small'];
        $agent->saveOrFail();
        $mistral = $service->handlerFor($meeting);
        $this->assertSame(['mistral', 'voxtral-small'], [$mistral->providerName(), $mistral->resolvedModelName()]);
    }

    public function testAnAgentOnAProviderThatCannotHearFailsTheTranscriptWithItsReason(): void
    {
        Bus::fake([GenerateMeetingNotesJob::class]);
        $meeting = $this->recordedMeeting();
        $this->companyAgent($meeting, ['llm_provider' => 'anthropic', 'key' => 'agent-key', 'model' => 'claude-sonnet-5-5']);

        $transcript = new TranscribeMeetingAction($meeting)->execute();

        $this->assertSame('failed', $transcript->status->value);
        $this->assertSame(TranscriptProviderException::CANNOT_HEAR_AUDIO, $transcript->error_code);
        $this->assertSame(['anthropic', 'claude-sonnet-5-5'], [$transcript->provider, $transcript->model]);
        $this->assertSame('failed', $meeting->refresh()->status->value);
    }

    public function testWithoutACompanyAgentTheDefaultModelRunsOnTheAppKey(): void
    {
        $meeting = $this->recordedMeeting();
        $hasKey = (string) $meeting->app->get(ConfigurationEnum::GEMINI_KEY->value) !== '';

        if (! $hasKey) {
            $this->expectException(TranscriptProviderException::class);
        }

        $handler = new TranscriptAgentService()->handlerFor($meeting);

        $this->assertSame(TranscriptAgent::DEFAULT_MODEL, $handler->resolvedModelName());
        $this->assertSame('gemini', $handler->providerName());
    }
}
