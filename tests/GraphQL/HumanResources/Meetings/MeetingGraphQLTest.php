<?php

declare(strict_types=1);

namespace Tests\GraphQL\HumanResources\Meetings;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\HumanResources\Meetings\Models\Meeting;
use Kanvas\HumanResources\Meetings\Notes\Models\MeetingNote;
use Kanvas\HumanResources\Meetings\Recordings\Actions\AttachMeetingRecordingAction;
use Kanvas\HumanResources\Meetings\Recordings\DataTransferObject\MeetingRecording as MeetingRecordingData;
use Kanvas\HumanResources\Meetings\Recordings\Models\MeetingRecording;
use Kanvas\HumanResources\Meetings\Transcripts\Jobs\TranscribeMeetingJob;
use Kanvas\HumanResources\Meetings\Transcripts\Models\MeetingTranscript;
use Kanvas\HumanResources\Meetings\Transcripts\Models\MeetingTranscriptSpeaker;
use Tests\TestCase;

class MeetingGraphQLTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'hr', 'crm'];

    private function createMeeting(?string $uuid = null, string $title = 'Weekly sync'): array
    {
        return $this->graphQL('
            mutation($input: MeetingInput!) {
                createMeeting(input: $input) { id uuid title status started_at }
            }
        ', ['input' => [
            'uuid' => $uuid ?? (string) Str::uuid(),
            'title' => $title,
            'started_at' => '2026-11-14 10:00:00',
        ]])->assertSuccessful()->json('data.createMeeting');
    }

    // The file is already in storage here; the GraphQL way in is uploadMeetingRecording.
    private function attachRecording(string $meetingId, array $input): MeetingRecording
    {
        return new AttachMeetingRecordingAction(
            MeetingRecordingData::from(Meeting::query()->findOrFail($meetingId), $input),
        )->execute();
    }

    private function recordingInput(string $seed): array
    {
        return [
            'storage_key' => 'meetings/' . $seed . '/audio.webm',
            'mime_type' => 'audio/webm',
            'size_bytes' => 3000000000,
            'sha256' => hash('sha256', $seed),
            'duration_ms' => 3600000,
            'channel_map' => ['0' => 'microphone', '1' => 'system'],
        ];
    }

    private function createRecordedMeeting(): array
    {
        $meeting = $this->createMeeting();

        $this->attachRecording($meeting['id'], $this->recordingInput($meeting['uuid']));

        return $meeting;
    }

    private function createTranscript(string $meetingId): array
    {
        return $this->graphQL('
            mutation($meetingId: ID!, $input: MeetingTranscriptInput!) {
                createMeetingTranscript(meeting_id: $meetingId, input: $input) { id version status }
            }
        ', [
            'meetingId' => $meetingId,
            'input' => ['provider' => 'gemini', 'model' => 'gemini-2.5-pro'],
        ])->assertSuccessful()->json('data.createMeetingTranscript');
    }

    private function storeSegments(string $transcriptId, array $segments): array
    {
        return $this->graphQL('
            mutation($id: ID!, $segments: [MeetingTranscriptSegmentInput!]!) {
                storeMeetingTranscriptSegments(id: $id, segments: $segments) {
                    status
                    completed_at
                    meeting { status }
                    speakers { id provider_label source_key display_name }
                    segments { data { position text start_ms speaker { provider_label display_name } } }
                }
            }
        ', ['id' => $transcriptId, 'segments' => $segments])
            ->assertSuccessful()
            ->json('data.storeMeetingTranscriptSegments');
    }

    private function meetingOfAnotherUser(): Meeting
    {
        return Meeting::query()->create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
            'users_id' => auth()->user()->getId() + 999999,
            'title' => 'Someone else',
        ]);
    }

    public function testCreateMeetingIsIdempotentByUuid(): void
    {
        $uuid = (string) Str::uuid();

        $first = $this->createMeeting($uuid);
        $retry = $this->createMeeting($uuid, 'Retried title');

        $this->assertSame($uuid, $first['uuid']);
        $this->assertSame('AWAITING_UPLOAD', $first['status']);
        $this->assertSame($first['id'], $retry['id']);
        $this->assertSame('Weekly sync', $retry['title']);
    }

    private function person(?int $companyId = null): People
    {
        return People::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($companyId ?? auth()->user()->getCurrentCompany()->getId())
            ->withUserId(auth()->user()->getId())
            ->create();
    }

    public function testAMeetingIsAssignedToAPersonOfItsCompanyAndCanBeUnassigned(): void
    {
        $person = $this->person();
        $update = '
            mutation($id: ID!, $input: UpdateMeetingInput!) {
                updateMeeting(id: $id, input: $input) { title people { id } }
            }
        ';

        $meeting = $this->graphQL('
            mutation($input: MeetingInput!) {
                createMeeting(input: $input) { id people { id } }
            }
        ', ['input' => ['uuid' => (string) Str::uuid(), 'title' => 'Client call', 'people_id' => $person->getId()]])
            ->assertSuccessful()
            ->json('data.createMeeting');

        $this->assertSame((string) $person->getId(), $meeting['people']['id']);

        $renamed = $this->graphQL($update, ['id' => $meeting['id'], 'input' => ['title' => 'Renamed']])
            ->assertSuccessful()
            ->json('data.updateMeeting');
        $this->assertSame((string) $person->getId(), $renamed['people']['id']);

        $filtered = $this->graphQL('
            query($peopleId: Mixed) {
                meetings(where: { column: PEOPLE_ID, operator: EQ, value: $peopleId }) { data { id } }
            }
        ', ['peopleId' => $person->getId()])->assertSuccessful()->json('data.meetings.data.*.id');
        $this->assertSame([$meeting['id']], $filtered);

        $outsider = $this->person(auth()->user()->getCurrentCompany()->getId() + 999999);
        $this->graphQL($update, ['id' => $meeting['id'], 'input' => ['people_id' => $outsider->getId()]])
            ->assertGraphQLErrorMessage('No person found with ID ' . $outsider->getId());
        $this->assertSame($person->getId(), Meeting::query()->findOrFail($meeting['id'])->people_id);

        $unassigned = $this->graphQL($update, ['id' => $meeting['id'], 'input' => ['people_id' => null]])
            ->assertSuccessful()
            ->json('data.updateMeeting');
        $this->assertNull($unassigned['people']);
    }

    public function testUpdateMeetingKeepsFieldsThatWereNotSent(): void
    {
        $meeting = $this->createMeeting();

        $updated = $this->graphQL('
            mutation($id: ID!, $input: UpdateMeetingInput!) {
                updateMeeting(id: $id, input: $input) { title started_at ended_at }
            }
        ', ['id' => $meeting['id'], 'input' => ['title' => 'Renamed', 'ended_at' => '2026-11-14 11:00:00']])
            ->assertSuccessful()
            ->json('data.updateMeeting');

        $this->assertSame('Renamed', $updated['title']);
        $this->assertSame($meeting['started_at'], $updated['started_at']);
        $this->assertSame('2026-11-14 11:00:00', $updated['ended_at']);
    }

    public function testAttachRecordingMarksTheMeetingUploadedAndAcceptsARetryOfTheSameFile(): void
    {
        $meeting = $this->createMeeting();
        $input = $this->recordingInput($meeting['uuid']);

        $recording = $this->attachRecording($meeting['id'], $input);
        $retry = $this->attachRecording($meeting['id'], $input);

        $this->assertSame($recording->getId(), $retry->getId());
        $this->assertEquals(3000000000, $recording->refresh()->size_bytes);
        $this->assertSame('system', $recording->channel_map['1']);
        $this->assertSame('uploaded', Meeting::query()->findOrFail($meeting['id'])->status->value);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Meeting ' . $meeting['id'] . ' already has a recording');
        $this->attachRecording($meeting['id'], ['sha256' => hash('sha256', 'another file')] + $input);
    }

    private function uploadRecording(string $meetingId, string $content, ?string $sha256 = null): TestResponse
    {
        return $this->multipartGraphQL(
            [
                'query' => '
                    mutation($meetingId: ID!, $file: Upload!, $input: MeetingRecordingUploadInput!) {
                        uploadMeetingRecording(meeting_id: $meetingId, file: $file, input: $input) {
                            id sha256 size_bytes mime_type duration_ms
                            meeting { id status }
                        }
                    }
                ',
                'variables' => [
                    'meetingId' => $meetingId,
                    'file' => null,
                    'input' => [
                        'mime_type' => 'audio/webm',
                        'sha256' => $sha256 ?? hash('sha256', $content),
                        'duration_ms' => 61000,
                        'channel_map' => ['0' => 'microphone', '1' => 'system'],
                    ],
                ],
            ],
            ['0' => ['variables.file']],
            ['0' => UploadedFile::fake()->createWithContent('meeting.webm', $content)],
        );
    }

    public function testUploadRecordingStoresTheFileAndAnswersARetryWithoutStoringItAgain(): void
    {
        // multipartGraphQL file uploads hang with concurrent paratest workers (see FilesystemTest).
        if (getenv('TEST_TOKEN') !== false) {
            $this->markTestSkipped('Upload test skipped under paratest');
        }

        $disk = (string) config('filesystems.default');
        Storage::fake($disk);
        Bus::fake([TranscribeMeetingJob::class]);
        $meeting = $this->createMeeting();

        $this->uploadRecording($meeting['id'], 'audio bytes', hash('sha256', 'other bytes'))
            ->assertGraphQLErrorMessage('The uploaded recording does not match its sha256');
        $this->assertSame([], Storage::disk($disk)->allFiles());

        $recording = $this->uploadRecording($meeting['id'], 'audio bytes')
            ->assertSuccessful()
            ->json('data.uploadMeetingRecording');
        $retry = $this->uploadRecording($meeting['id'], 'audio bytes')
            ->assertSuccessful()
            ->json('data.uploadMeetingRecording');

        $stored = MeetingRecording::query()->findOrFail($recording['id']);
        $this->assertSame($recording['id'], $retry['id']);
        // The retry asks again while nothing has transcribed the recording, and the job being unique
        // per meeting is what drops that second one while the first is still waiting.
        Bus::assertDispatchedTimes(TranscribeMeetingJob::class, 1);
        Bus::assertDispatched(
            TranscribeMeetingJob::class,
            fn (TranscribeMeetingJob $job): bool => $job->uniqueId() === $meeting['id']
                && $job->queue === TranscribeMeetingJob::QUEUE
        );

        $this->assertSame(['id' => $meeting['id'], 'status' => 'UPLOADED'], $recording['meeting']);
        $this->assertEquals(strlen('audio bytes'), $recording['size_bytes']);
        $this->assertSame($disk, $stored->storage_disk);
        $this->assertSame([$stored->storage_key], Storage::disk($disk)->allFiles());
        $this->assertStringContainsString($meeting['uuid'], $stored->storage_key);
        $this->assertSame('uploaded', Meeting::query()->findOrFail($meeting['id'])->status->value);

        $this->uploadRecording($meeting['id'], 'a different file')
            ->assertGraphQLErrorMessage('Meeting ' . $meeting['id'] . ' already has a recording');
        $this->assertCount(1, Storage::disk($disk)->allFiles());

        $theirs = $this->meetingOfAnotherUser();
        $this->uploadRecording((string) $theirs->getId(), 'audio bytes')
            ->assertGraphQLErrorMessage('No meeting found with ID ' . $theirs->getId());
        $this->assertCount(1, Storage::disk($disk)->allFiles());
    }

    public function testTranscriptVersionsIncrementAndSegmentsCompleteTheMeeting(): void
    {
        $meeting = $this->createRecordedMeeting();

        $first = $this->createTranscript($meeting['id']);
        $second = $this->createTranscript($meeting['id']);

        $this->assertSame(1, $first['version']);
        $this->assertSame(2, $second['version']);
        $this->assertSame('PENDING', $second['status']);

        $stored = $this->storeSegments($second['id'], [
            ['text' => 'Hello everyone', 'speaker_label' => 'spk_1', 'start_ms' => 0, 'end_ms' => 1200],
            ['text' => 'Hi', 'speaker_label' => 'spk_2', 'start_ms' => 1300, 'end_ms' => 1600],
            ['text' => 'Let us begin', 'speaker_label' => 'spk_1'],
            ['text' => '[music]'],
        ]);

        $this->assertSame('COMPLETED', $stored['status']);
        $this->assertNotNull($stored['completed_at']);
        $this->assertSame('COMPLETED', $stored['meeting']['status']);
        $this->assertCount(2, $stored['speakers']);
        $this->assertSame('full', $stored['speakers'][0]['source_key']);
        $this->assertSame([0, 1, 2, 3], array_column($stored['segments']['data'], 'position'));
        $this->assertSame('spk_1', $stored['segments']['data'][2]['speaker']['provider_label']);
        $this->assertNull($stored['segments']['data'][3]['speaker']);

        $listed = $this->graphQL('
            query($id: ID!) {
                meetingTranscriptSegments(transcript_id: $id, first: 2, page: 2) {
                    data { position text }
                    paginatorInfo { total }
                }
            }
        ', ['id' => $second['id']])->assertSuccessful()->json('data.meetingTranscriptSegments');

        $this->assertSame(4, $listed['paginatorInfo']['total']);
        $this->assertSame('Let us begin', $listed['data'][0]['text']);
    }

    public function testAConfirmedSpeakerNameSurvivesStoringTheSegmentsAgain(): void
    {
        $meeting = $this->createRecordedMeeting();
        $transcript = $this->createTranscript($meeting['id']);
        $stored = $this->storeSegments($transcript['id'], [['text' => 'Hello', 'speaker_label' => 'spk_1']]);

        $renamed = $this->graphQL('
            mutation($id: ID!, $name: String) {
                updateMeetingTranscriptSpeaker(id: $id, display_name: $name) { id display_name }
            }
        ', ['id' => $stored['speakers'][0]['id'], 'name' => ' Frederick '])
            ->assertSuccessful()
            ->json('data.updateMeetingTranscriptSpeaker');

        $this->assertSame('Frederick', $renamed['display_name']);

        $again = $this->storeSegments($transcript['id'], [
            ['text' => 'Hello again', 'speaker_label' => 'spk_1'],
            ['text' => 'Bye', 'speaker_label' => 'spk_1'],
        ]);

        $this->assertCount(2, $again['segments']['data']);
        $this->assertSame('Frederick', $again['segments']['data'][0]['speaker']['display_name']);
        $this->assertSame(1, MeetingTranscriptSpeaker::query()->where('meeting_transcripts_id', $transcript['id'])->count());
    }

    public function testAFailedTranscriptKeepsItsErrorCodeAndFailsTheMeeting(): void
    {
        $meeting = $this->createRecordedMeeting();
        $transcript = $this->createTranscript($meeting['id']);

        $failed = $this->graphQL('
            mutation($id: ID!, $input: MeetingTranscriptStatusInput!) {
                updateMeetingTranscriptStatus(id: $id, input: $input) { status error_code completed_at meeting { status } }
            }
        ', ['id' => $transcript['id'], 'input' => ['status' => 'FAILED', 'error_code' => 'stt_timeout']])
            ->assertSuccessful()
            ->json('data.updateMeetingTranscriptStatus');

        $this->assertSame('FAILED', $failed['status']);
        $this->assertSame('stt_timeout', $failed['error_code']);
        $this->assertNull($failed['completed_at']);
        $this->assertSame('FAILED', $failed['meeting']['status']);
    }

    public function testSaveNoteKeepsOneNotePerKind(): void
    {
        $meeting = $this->createRecordedMeeting();
        $transcript = $this->createTranscript($meeting['id']);
        $mutation = '
            mutation($meetingId: ID!, $input: MeetingNoteInput!) {
                saveMeetingNote(meeting_id: $meetingId, input: $input) { id kind body transcript { id } }
            }
        ';

        $manual = $this->graphQL($mutation, ['meetingId' => $meeting['id'], 'input' => ['body' => 'First draft']])
            ->assertSuccessful()
            ->json('data.saveMeetingNote');
        $rewritten = $this->graphQL($mutation, ['meetingId' => $meeting['id'], 'input' => ['body' => 'Second draft']])
            ->assertSuccessful()
            ->json('data.saveMeetingNote');
        $enhanced = $this->graphQL($mutation, [
            'meetingId' => $meeting['id'],
            'input' => ['kind' => 'ENHANCED', 'body' => 'Summary', 'transcript_id' => $transcript['id']],
        ])->assertSuccessful()->json('data.saveMeetingNote');

        $this->assertSame('MANUAL', $manual['kind']);
        $this->assertSame($manual['id'], $rewritten['id']);
        $this->assertSame('Second draft', $rewritten['body']);
        $this->assertNull($rewritten['transcript']);
        $this->assertSame($transcript['id'], $enhanced['transcript']['id']);

        $this->graphQL($mutation, ['meetingId' => $meeting['id'], 'input' => ['kind' => 'ENHANCED', 'body' => 'No source']])
            ->assertGraphQLErrorMessage('An enhanced note needs the transcript it was written from');

        $nested = $this->graphQL('
            query($where: QueryMeetingsWhereWhereConditions) {
                meetings(where: $where) {
                    data { id notes { kind } latest_transcript { version } recording { id } }
                }
            }
        ', ['where' => ['column' => 'ID', 'operator' => 'EQ', 'value' => $meeting['id']]])
            ->assertSuccessful()
            ->json('data.meetings.data');

        $this->assertCount(1, $nested);
        $this->assertCount(2, $nested[0]['notes']);
        $this->assertSame(1, $nested[0]['latest_transcript']['version']);
        $this->assertNotNull($nested[0]['recording']);
    }

    public function testAMeetingOfAnotherUserIsNeitherListedNorWritable(): void
    {
        $mine = $this->createMeeting();
        $theirs = $this->meetingOfAnotherUser();

        $ids = $this->graphQL('query { meetings(first: 100, orderBy: [{ column: ID, order: DESC }]) { data { id } } }')
            ->assertSuccessful()
            ->json('data.meetings.data.*.id');

        $this->assertContains($mine['id'], $ids);
        $this->assertNotContains((string) $theirs->getId(), $ids);

        $this->graphQL('
            mutation($id: ID!) {
                updateMeeting(id: $id, input: { title: "Hijacked" }) { id }
            }
        ', ['id' => $theirs->getId()])->assertGraphQLErrorMessage('No meeting found with ID ' . $theirs->getId());

        $this->graphQL('
            mutation($id: ID!) {
                createMeetingTranscript(meeting_id: $id, input: { provider: "gemini", model: "x" }) { id }
            }
        ', ['id' => $theirs->getId()])->assertGraphQLErrorMessage('No meeting found with ID ' . $theirs->getId());

        $this->graphQL('
            mutation($id: ID!) {
                saveMeetingNote(meeting_id: $id, input: { body: "Planted" }) { id }
            }
        ', ['id' => $theirs->getId()])->assertGraphQLErrorMessage('No meeting found with ID ' . $theirs->getId());

        $this->assertSame('Someone else', $theirs->refresh()->title);
    }

    public function testTheChildrenOfAnotherUsersMeetingAreOutOfReach(): void
    {
        $mine = $this->createRecordedMeeting();
        $theirs = $this->meetingOfAnotherUser();
        $transcript = MeetingTranscript::query()->create([
            'meetings_id' => $theirs->getId(),
            'version' => 1,
            'provider' => 'gemini',
            'model' => 'x',
        ]);
        $speaker = MeetingTranscriptSpeaker::query()->create([
            'meeting_transcripts_id' => $transcript->getId(),
            'provider_label' => 'spk_1',
        ]);
        $notFound = 'No meeting transcript found with ID ' . $transcript->getId();

        $this->graphQL('
            mutation($id: ID!) {
                updateMeetingTranscriptStatus(id: $id, input: { status: FAILED }) { id }
            }
        ', ['id' => $transcript->getId()])->assertGraphQLErrorMessage($notFound);

        $this->graphQL('
            mutation($id: ID!) {
                storeMeetingTranscriptSegments(id: $id, segments: [{ text: "Planted" }]) { id }
            }
        ', ['id' => $transcript->getId()])->assertGraphQLErrorMessage($notFound);

        $this->graphQL('
            query($id: ID!) { meetingTranscriptSegments(transcript_id: $id) { data { id } } }
        ', ['id' => $transcript->getId()])->assertGraphQLErrorMessage($notFound);

        $this->graphQL('
            mutation($id: ID!) {
                updateMeetingTranscriptSpeaker(id: $id, display_name: "Renamed") { id }
            }
        ', ['id' => $speaker->getId()])
            ->assertGraphQLErrorMessage('No meeting transcript speaker found with ID ' . $speaker->getId());

        $this->graphQL('
            mutation($meetingId: ID!, $transcriptId: ID!) {
                saveMeetingNote(
                    meeting_id: $meetingId
                    input: { kind: ENHANCED, body: "Summary", transcript_id: $transcriptId }
                ) { id }
            }
        ', ['meetingId' => $mine['id'], 'transcriptId' => $transcript->getId()])->assertGraphQLErrorMessage($notFound);

        $this->assertSame('pending', $transcript->refresh()->status->value);
        $this->assertNull($speaker->refresh()->display_name);
    }

    public function testOnlyTheNewestTranscriptVersionDrivesTheMeetingStatus(): void
    {
        $meeting = $this->createRecordedMeeting();
        $first = $this->createTranscript($meeting['id']);
        $second = $this->createTranscript($meeting['id']);
        $this->storeSegments($second['id'], [['text' => 'Hello']]);

        $late = $this->graphQL('
            mutation($id: ID!) {
                updateMeetingTranscriptStatus(id: $id, input: { status: FAILED, error_code: "stt_timeout" }) {
                    status
                    meeting { status latest_transcript { version status } }
                }
            }
        ', ['id' => $first['id']])->assertSuccessful()->json('data.updateMeetingTranscriptStatus');

        $this->assertSame('FAILED', $late['status']);
        $this->assertSame('COMPLETED', $late['meeting']['status']);
        $this->assertSame(2, $late['meeting']['latest_transcript']['version']);
    }

    public function testATranscriptNeedsARecordingAndAtLeastOneValidSegment(): void
    {
        $unrecorded = $this->createMeeting();

        $this->graphQL('
            mutation($id: ID!) {
                createMeetingTranscript(meeting_id: $id, input: { provider: "gemini", model: "x" }) { id }
            }
        ', ['id' => $unrecorded['id']])
            ->assertGraphQLErrorMessage('Meeting ' . $unrecorded['id'] . ' has no recording to transcribe');

        $transcript = $this->createTranscript($this->createRecordedMeeting()['id']);
        $store = '
            mutation($id: ID!, $segments: [MeetingTranscriptSegmentInput!]!) {
                storeMeetingTranscriptSegments(id: $id, segments: $segments) { id }
            }
        ';

        $this->graphQL($store, ['id' => $transcript['id'], 'segments' => []])
            ->assertGraphQLErrorMessage('A transcript needs at least one segment');
        $this->graphQL($store, [
            'id' => $transcript['id'],
            'segments' => [['text' => 'Backwards', 'start_ms' => 2000, 'end_ms' => 1000]],
        ])->assertGraphQLErrorMessage('Segment 0 ends before it starts');
        $this->graphQL($store, ['id' => $transcript['id'], 'segments' => [['text' => 'Far channel', 'channel' => 70000]]])
            ->assertGraphQLValidationKeys(['segments.0.channel']);
        $this->graphQL($store, ['id' => $transcript['id'], 'segments' => [['text' => str_repeat('a', 16001)]]])
            ->assertGraphQLValidationKeys(['segments.0.text']);
    }

    public function testMeetingDatesCanBeClearedButNotInverted(): void
    {
        $meeting = $this->createMeeting();
        $mutation = '
            mutation($id: ID!, $input: UpdateMeetingInput!) {
                updateMeeting(id: $id, input: $input) { started_at ended_at }
            }
        ';

        $this->graphQL($mutation, ['id' => $meeting['id'], 'input' => ['ended_at' => '2026-11-14 09:00:00']])
            ->assertGraphQLErrorMessage('A meeting cannot end before it starts');

        $cleared = $this->graphQL($mutation, ['id' => $meeting['id'], 'input' => ['started_at' => null]])
            ->assertSuccessful()
            ->json('data.updateMeeting');

        $this->assertNull($cleared['started_at']);
    }

    public function testAUuidOwnedBySomeoneElseIsRefusedWithoutSayingWhy(): void
    {
        $theirs = $this->meetingOfAnotherUser();

        $this->graphQL('
            mutation($input: MeetingInput!) { createMeeting(input: $input) { id } }
        ', ['input' => ['uuid' => $theirs->uuid, 'title' => 'Mine now']])
            ->assertGraphQLErrorMessage('This meeting uuid cannot be used');
    }

    public function testALongNoteBodyIsStored(): void
    {
        $meeting = $this->createMeeting();
        $body = str_repeat('nota', 20000);

        $this->graphQL('
            mutation($id: ID!, $body: String!) { saveMeetingNote(meeting_id: $id, input: { body: $body }) { id } }
        ', ['id' => $meeting['id'], 'body' => $body])->assertSuccessful();

        $this->assertSame($body, MeetingNote::query()->where('meetings_id', $meeting['id'])->firstOrFail()->body);
    }
}
