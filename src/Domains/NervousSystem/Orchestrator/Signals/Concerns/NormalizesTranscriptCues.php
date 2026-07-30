<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Signals\Concerns;

/**
 * Shared cue → content/actors normalization for transcript adapters (WebVTT, plain timestamped, …).
 * Each adapter parses its own format into ordered cues `{ speaker, text }`; this turns that into the
 * flattened "Speaker: text" content (merging consecutive same-speaker cues) and the actor list.
 */
trait NormalizesTranscriptCues
{
    /**
     * Flatten cues to "Speaker: text" lines, merging consecutive cues from the same speaker into one.
     *
     * @param list<array{speaker: string, text: string}> $cues
     */
    private function contentFromCues(array $cues): string
    {
        $lines = [];
        $currentSpeaker = null;
        $buffer = [];

        foreach ($cues as $cue) {
            if ($cue['speaker'] !== $currentSpeaker && $buffer !== []) {
                $lines[] = $this->cueLine($currentSpeaker, $buffer);
                $buffer = [];
            }
            $currentSpeaker = $cue['speaker'];
            $buffer[] = $cue['text'];
        }

        if ($buffer !== []) {
            $lines[] = $this->cueLine($currentSpeaker, $buffer);
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $buffer
     */
    private function cueLine(?string $speaker, array $buffer): string
    {
        $text = implode(' ', $buffer);

        return $speaker !== null && $speaker !== '' ? "{$speaker}: {$text}" : $text;
    }

    /**
     * Unique named speakers. "Unidentified Speaker" (the transcription placeholder) is not a real
     * person, so it's excluded. No emails in these formats → routing is content-based.
     *
     * @param list<array{speaker: string, text: string}> $cues
     *
     * @return list<array{name: string, email: ?string}>
     */
    private function actorsFromCues(array $cues): array
    {
        $seen = [];
        foreach ($cues as $cue) {
            $name = $cue['speaker'];
            if ($name !== '' && strcasecmp($name, 'Unidentified Speaker') !== 0) {
                $seen[$name] = true;
            }
        }

        return array_map(
            static fn (string $name): array => ['name' => $name, 'email' => null],
            array_keys($seen),
        );
    }
}
