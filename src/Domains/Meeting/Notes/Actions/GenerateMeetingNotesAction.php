<?php

declare(strict_types=1);

namespace Kanvas\Meeting\Notes\Actions;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Meeting\Notes\Agents\MeetingNotesAgent;
use Kanvas\Meeting\Notes\DataTransferObject\MeetingNote as MeetingNoteData;
use Kanvas\Meeting\Notes\Enums\MeetingNoteKindEnum;
use Kanvas\Meeting\Notes\Models\MeetingNote;
use Kanvas\Meeting\Notes\Services\MeetingNotesAgentService;
use Kanvas\Meeting\Transcripts\Enums\MeetingTranscriptStatusEnum;
use Kanvas\Meeting\Transcripts\Models\MeetingTranscript;
use Kanvas\Meeting\Transcripts\Models\MeetingTranscriptSegment;

class GenerateMeetingNotesAction
{
    /**
     * @param MeetingNotesAgent|null $handler a ready agent, so a test runs without a live provider
     */
    public function __construct(
        protected readonly MeetingTranscript $transcript,
        protected readonly ?MeetingNotesAgent $handler = null,
    ) {
    }

    /**
     * Writes the meeting's enhanced note from this transcript, replacing the one written from an
     * earlier version. The person's manual note is never touched; it is handed to the agent as what
     * they cared about.
     *
     * Null when a newer transcript version exists: its own job writes the note, and a late or retried
     * job for this one must not put the older text back.
     */
    public function execute(): ?MeetingNote
    {
        if ($this->transcript->status !== MeetingTranscriptStatusEnum::COMPLETED) {
            throw new ValidationException('Transcript ' . $this->transcript->getId() . ' is not completed');
        }

        $meeting = $this->transcript->meeting;

        if ($this->isSuperseded()) {
            return null;
        }

        $handler = $this->handler ?? new MeetingNotesAgentService()->handlerFor($meeting);
        $body = $handler->write($this->brief(), 'meeting-notes-' . $this->transcript->getId());

        // Again after the call: a newer version can complete while the model writes.
        if ($this->isSuperseded()) {
            return null;
        }

        if ($body === '') {
            throw new ValidationException('The meeting notes agent answered with nothing');
        }

        return new SaveMeetingNoteAction(
            new MeetingNoteData(
                meeting: $meeting,
                body: $body,
                kind: MeetingNoteKindEnum::ENHANCED,
                transcript: $this->transcript,
            ),
        )->execute();
    }

    protected function isSuperseded(): bool
    {
        return (int) $this->transcript->meeting->transcripts()->max('version') > $this->transcript->version;
    }

    protected function brief(): string
    {
        $meeting = $this->transcript->meeting;
        $brief = 'Meeting: ' . $meeting->title;

        if ($meeting->started_at !== null) {
            $brief .= "\nHeld: " . $meeting->started_at->toDateTimeString() . ' UTC';
        }

        $manual = $meeting->notes()->where('kind', MeetingNoteKindEnum::MANUAL->value)->first();

        if ($manual !== null && trim($manual->body) !== '') {
            $brief .= "\n\nThe person's own notes:\n" . trim($manual->body);
        }

        return $brief . "\n\nTranscript:\n" . $this->transcriptText();
    }

    /**
     * One line per segment, `Name: text`. A speaker the user has not named is numbered in order of
     * appearance; the provider's own label ("spk:0") says nothing to a reader, and the same label in
     * two chunks of a long recording is two speaker rows, so it gets two numbers.
     */
    protected function transcriptText(): string
    {
        $numbers = [];

        return $this->transcript->segments()
            ->with('speaker')
            ->orderBy('position')
            ->get()
            ->map(function (MeetingTranscriptSegment $segment) use (&$numbers): string {
                $speaker = $segment->speaker;

                if ($speaker === null) {
                    return $segment->text;
                }

                $name = $speaker->display_name
                    ?? 'Speaker ' . ($numbers[$speaker->getId()] ??= count($numbers) + 1);

                return $name . ': ' . $segment->text;
            })
            ->implode("\n");
    }
}
