<?php

declare(strict_types=1);

namespace Tests\Meeting;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Meeting\Meetings\Models\Meeting;
use Kanvas\Meeting\Notes\Actions\GenerateMeetingNotesAction;
use Kanvas\Meeting\Notes\Actions\SaveMeetingNoteAction;
use Kanvas\Meeting\Notes\Agents\MeetingNotesAgent;
use Kanvas\Meeting\Notes\DataTransferObject\MeetingNote as MeetingNoteData;
use Kanvas\Meeting\Notes\Services\MeetingNotesAgentService;
use Kanvas\Meeting\Recordings\Actions\AttachMeetingRecordingAction;
use Kanvas\Meeting\Recordings\DataTransferObject\MeetingRecording as MeetingRecordingData;
use Kanvas\Meeting\Transcripts\Actions\CreateMeetingTranscriptAction;
use Kanvas\Meeting\Transcripts\Actions\StoreMeetingTranscriptSegmentsAction;
use Kanvas\Meeting\Transcripts\Actions\UpdateMeetingTranscriptSpeakerAction;
use Kanvas\Meeting\Transcripts\DataTransferObject\MeetingTranscript as MeetingTranscriptData;
use Kanvas\Meeting\Transcripts\Models\MeetingTranscript;
use Tests\Stubs\Intelligence\FakeNeuronProvider;
use Tests\TestCase;

class GenerateMeetingNotesTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'meeting'];

    private function meeting(): Meeting
    {
        $meeting = Meeting::query()->create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
            'users_id' => auth()->user()->getId(),
            'title' => 'Weekly sync',
        ]);

        new AttachMeetingRecordingAction(
            MeetingRecordingData::from($meeting, [
                'storage_key' => 'meetings/' . $meeting->uuid . '/audio.webm',
                'mime_type' => 'audio/webm',
                'size_bytes' => 1000,
                'sha256' => hash('sha256', $meeting->uuid),
            ]),
        )->execute();

        return $meeting;
    }

    private function transcript(Meeting $meeting, bool $completed = true): MeetingTranscript
    {
        $transcript = new CreateMeetingTranscriptAction(
            new MeetingTranscriptData($meeting, 'gemini', 'gemini-3.5-transcribe'),
        )->execute();

        if (! $completed) {
            return $transcript;
        }

        return new StoreMeetingTranscriptSegmentsAction($transcript, [
            ['text' => 'Empezamos la reunión.', 'speaker_label' => 'spk:0', 'source_key' => 'part-1'],
            ['text' => 'La subida ya funciona.', 'speaker_label' => 'spk:1', 'source_key' => 'part-1'],
            ['text' => 'Seguimos con las notas.', 'speaker_label' => 'spk:0', 'source_key' => 'part-2'],
            ['text' => 'Ruido de fondo.'],
        ])->execute();
    }

    private function agentAnswering(string $reply): MeetingNotesAgent
    {
        return new MeetingNotesAgent()->runWith(new FakeNeuronProvider($reply), 'fake');
    }

    public function testTheEnhancedNoteIsWrittenFromTheTranscriptAndRewrittenByALaterOne(): void
    {
        $meeting = $this->meeting();
        $transcript = $this->transcript($meeting);
        new UpdateMeetingTranscriptSpeakerAction($transcript->speakers()->orderBy('id')->firstOrFail(), 'Frederick')->execute();
        $manual = new SaveMeetingNoteAction(new MeetingNoteData($meeting, 'Revisar el límite de 512 MB.'))->execute();

        $note = new GenerateMeetingNotesAction($transcript, $this->agentAnswering("## Resumen\nSe revisó la subida.\n"))->execute();

        $this->assertSame('enhanced', $note->kind->value);
        $this->assertSame("## Resumen\nSe revisó la subida.", $note->body);
        $this->assertSame($transcript->getId(), $note->meeting_transcripts_id);
        $this->assertSame('Revisar el límite de 512 MB.', $manual->refresh()->body);

        $brief = (string) FakeNeuronProvider::$lastMessages[0]->getContent();
        $this->assertStringContainsString('Meeting: Weekly sync', $brief);
        $this->assertStringContainsString("The person's own notes:\nRevisar el límite de 512 MB.", $brief);
        $this->assertStringContainsString(
            "Frederick: Empezamos la reunión.\nSpeaker 1: La subida ya funciona.\nSpeaker 2: Seguimos con las notas.\nRuido de fondo.",
            $brief
        );

        $later = new GenerateMeetingNotesAction($this->transcript($meeting), $this->agentAnswering('## Resumen v2'))->execute();

        $this->assertSame($note->getId(), $later->getId());
        $this->assertSame('## Resumen v2', $later->body);
        $this->assertCount(2, $meeting->notes()->get());

        $late = new GenerateMeetingNotesAction($transcript, $this->agentAnswering('## Resumen viejo'))->execute();

        $this->assertNull($late);
        $this->assertSame('## Resumen v2', $later->refresh()->body);
    }

    public function testNoNoteIsWrittenFromAnUnfinishedTranscriptOrAnEmptyReply(): void
    {
        $meeting = $this->meeting();

        try {
            new GenerateMeetingNotesAction($this->transcript($meeting, completed: false), $this->agentAnswering('## Resumen'))->execute();
            $this->fail('An unfinished transcript produced a note');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('is not completed', $e->getMessage());
        }

        try {
            new GenerateMeetingNotesAction($this->transcript($meeting), $this->agentAnswering("  \n"))->execute();
            $this->fail('An empty reply produced a note');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('answered with nothing', $e->getMessage());
        }

        $this->assertCount(0, $meeting->notes()->get());
    }

    public function testWithoutACompanyAgentTheAppGeminiModelWritesTheNote(): void
    {
        $meeting = $this->meeting();
        $app = $meeting->app;

        if ((string) $app->get(ConfigurationEnum::GEMINI_KEY->value) === '') {
            $this->expectException(ValidationException::class);
        }

        $handler = new MeetingNotesAgentService()->handlerFor($meeting);

        $this->assertSame(
            (string) ($app->get(ConfigurationEnum::GEMINI_MODEL->value) ?: MeetingNotesAgent::DEFAULT_MODEL),
            $handler->resolvedModelName()
        );
        $this->assertSame('gemini', $handler->providerName());
    }
}
