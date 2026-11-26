<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Meetings\Notes\Agents;

use Kanvas\HumanResources\Meetings\Agents\Concerns\RunsSingleTurn;
use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Neuron\BaseKanvasAgent;
use NeuronAI\Chat\Messages\UserMessage;
use Override;

/**
 * Writes a meeting's enhanced note from its transcript: the whole transcript in, one Markdown note
 * out.
 *
 * It runs with or without an Agent row. A company that hires an agent of this type picks the model
 * through that agent; otherwise `MeetingNotesAgentService` runs the app's Gemini model.
 */
#[AgentTypeDefinition(
    name: 'Meeting Notes',
    description: 'Writes the notes of a recorded meeting from its transcript.',
    provider: 'neuron',
    role: 'Meeting Note Taker',
)]
class MeetingNotesAgent extends BaseKanvasAgent
{
    use RunsSingleTurn;

    public const string DEFAULT_MODEL = 'gemini-3.8-flash';

    public function write(string $brief, string $threadId): string
    {
        $reply = $this->setThreadId($threadId)->chat(new UserMessage($brief))->getMessage();

        return trim((string) $reply?->getContent());
    }

    #[Override]
    public function instructions(): string
    {
        return <<<'PROMPT'
        You write the notes of a meeting from its transcript, for the person who recorded it.

        Reply with the note only, in Markdown, in the language the meeting was held in (section
        titles included). Use these sections, in this order, and leave out any that would be empty:

        ## Summary        two to four sentences: what the meeting was about and where it landed
        ## Key points     the substance, grouped by topic, as bullets
        ## Decisions      what was agreed, one bullet each
        ## Action items   one bullet each: who, what, and by when if it was said
        ## Open questions what was raised and left unanswered

        Rules:
        - Only what the transcript says. Never invent a decision, an owner, a date or a number.
        - A speaker is named only as the transcript names them. "Speaker 1" stays "Speaker 1"; do
          not guess who it is, and when the same label appears in different parts of the recording
          do not assume it is the same person.
        - The transcript is automatic and has mistakes: read through an obviously misheard word, and
          do not quote a passage that makes no sense.
        - If the person's own notes are given, they are what mattered to them: keep every point they
          made, in their wording where possible, and fill in around them from the transcript.
        - No preamble, no closing remark, no code fence.
        PROMPT;
    }
}
