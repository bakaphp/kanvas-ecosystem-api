<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Meetings\Transcripts\Agents;

use Kanvas\Exceptions\ValidationException;
use Kanvas\HumanResources\Meetings\Agents\Concerns\RunsSingleTurn;
use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Neuron\BaseKanvasAgent;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\UserMessage;
use Override;

/**
 * Turns one piece of meeting audio into speaker-labelled segments: one audio block in, one JSON reply
 * out.
 *
 * It runs with or without an Agent row. A company that hires an agent of this type picks the model
 * through that agent's LLM config; otherwise `TranscriptAgentService` runs DEFAULT_MODEL on the app's
 * Gemini key.
 */
#[AgentTypeDefinition(
    name: 'Transcript',
    description: 'Transcribes a recorded meeting into speaker-labelled segments.',
    provider: 'neuron',
    role: 'Meeting Transcriber',
    config: ['transcription' => ['diarization' => true, 'word_timestamps' => false]],
)]
class TranscriptAgent extends BaseKanvasAgent
{
    use RunsSingleTurn;

    public const string DEFAULT_MODEL = 'gemini-3.5-transcribe';

    /**
     * A speaker turn is one segment, and a long monologue is cut so every segment fits the 16000
     * characters a segment row holds, with room to spare.
     */
    private const int SEGMENT_CHARS = 4000;

    /**
     * @return list<array{speaker_label: ?string, text: string, start_ms: ?int, end_ms: ?int}>
     */
    public function transcribe(string $audioPath, string $mimeType, string $threadId): array
    {
        $message = new UserMessage('Transcribe this recording.');
        $message->addContent(new AudioContent(
            base64_encode((string) file_get_contents($audioPath)),
            SourceType::BASE64,
            $mimeType,
        ));

        $reply = $this->setThreadId($threadId)->chat($message)->getMessage();

        return self::parse((string) $reply?->getContent());
    }

    /**
     * A speech-to-text model ignores this; a chat model (an agent row pointed at one) is held to it.
     */
    #[Override]
    public function instructions(): string
    {
        return <<<'PROMPT'
        You transcribe meeting recordings. Write down what is said, verbatim, in the language it is
        spoken in. Do not summarize, translate, correct or comment.

        Reply with JSON only, no prose and no code fence, in this shape:
        {"segments": [{"speaker": "Speaker 1", "text": "...", "start_ms": 0, "end_ms": 1500}]}

        - One segment per speaker turn, in the order spoken.
        - Label speakers "Speaker 1", "Speaker 2", ... and keep each label on the same voice.
        - start_ms and end_ms are milliseconds from the start of the audio; use null when unsure.
        - If nobody speaks, reply {"segments": []}.
        PROMPT;
    }

    /**
     * @return list<array{speaker_label: ?string, text: string, start_ms: ?int, end_ms: ?int}>
     */
    private static function parse(string $reply): array
    {
        $json = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($reply)));
        $decoded = json_decode($json, true);

        if (! is_array($decoded) || ! is_array($decoded['segments'] ?? null)) {
            throw new ValidationException('The transcript agent did not answer with segments.');
        }

        $segments = [];

        foreach ($decoded['segments'] as $segment) {
            $text = trim((string) ($segment['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $pieces = self::split($text);
            $timed = count($pieces) === 1;

            foreach ($pieces as $piece) {
                $segments[] = [
                    'speaker_label' => isset($segment['speaker']) ? (string) $segment['speaker'] : null,
                    'text' => $piece,
                    'start_ms' => $timed && isset($segment['start_ms']) ? (int) $segment['start_ms'] : null,
                    'end_ms' => $timed && isset($segment['end_ms']) ? (int) $segment['end_ms'] : null,
                ];
            }
        }

        return $segments;
    }

    /**
     * @return list<string>
     */
    private static function split(string $text): array
    {
        if (mb_strlen($text) <= self::SEGMENT_CHARS) {
            return [$text];
        }

        $pieces = [];
        $current = '';

        foreach (preg_split('/(?<=[.!?…])\s+|\n+/u', $text) ?: [$text] as $sentence) {
            foreach (mb_str_split($sentence, self::SEGMENT_CHARS) as $part) {
                if ($current !== '' && mb_strlen($current) + mb_strlen($part) + 1 > self::SEGMENT_CHARS) {
                    $pieces[] = $current;
                    $current = '';
                }

                $current = $current === '' ? $part : $current . ' ' . $part;
            }
        }

        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }
}
