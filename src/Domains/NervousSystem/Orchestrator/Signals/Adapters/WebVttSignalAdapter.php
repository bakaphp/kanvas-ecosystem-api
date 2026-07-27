<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Signals\Adapters;

use Kanvas\NervousSystem\Orchestrator\Signals\Concerns\NormalizesTranscriptCues;
use Kanvas\NervousSystem\Orchestrator\Signals\Contracts\SignalSourceAdapter;
use Kanvas\NervousSystem\Orchestrator\Signals\DataTransferObject\InboundSignal;
use Kanvas\NervousSystem\Orchestrator\Signals\Enums\SignalSourceEnum;
use Kanvas\NervousSystem\Project\Enums\ProjectIngestTypeEnum;
use Override;

/**
 * WebVTT transcript webhook → InboundSignal. Payload: `{ "type": "transcript", "transcript":
 * "WEBVTT\n\n<cue-id>\n00:00:04.956 --> 00:00:09.836\n<v Speaker>text</v>..." }`. Speaker comes from
 * each cue's `<v Name>` voice tag; the external id is the meeting UUID that prefixes every cue id;
 * there are no participant emails so routing is content-based.
 */
final class WebVttSignalAdapter implements SignalSourceAdapter
{
    use NormalizesTranscriptCues;

    #[Override]
    public function parse(array $payload): InboundSignal
    {
        $body = (string) ($payload['transcript'] ?? '');
        $cues = $this->cues($body);

        return new InboundSignal(
            source: SignalSourceEnum::WEBVTT,
            kind: ProjectIngestTypeEnum::TRANSCRIPT,
            externalId: $this->meetingId($body),
            title: 'Untitled meeting',
            content: $this->contentFromCues($cues),
            occurredAt: null,
            actors: $this->actorsFromCues($cues),
            metadata: ['type' => (string) ($payload['type'] ?? 'transcript')],
        );
    }

    /**
     * Parse the WebVTT body into ordered cues. Header (WEBVTT), NOTE/STYLE/REGION blocks, cue-id lines
     * and timing lines are dropped; `<v Name>` gives the speaker; all tags are stripped from the text.
     *
     * @return list<array{speaker: string, text: string}>
     */
    private function cues(string $body): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $body);

        $cues = [];
        foreach (explode("\n\n", $normalized) as $block) {
            $block = trim($block);
            if ($block === '' || str_starts_with($block, 'WEBVTT') || str_starts_with($block, 'NOTE')
                || str_starts_with($block, 'STYLE') || str_starts_with($block, 'REGION')) {
                continue;
            }

            // Drop the cue-id line (anything before the "start --> end" timing line) and the timing line.
            $payloadLines = [];
            $seenTiming = false;
            foreach (explode("\n", $block) as $line) {
                if (! $seenTiming) {
                    if (str_contains($line, '-->')) {
                        $seenTiming = true;
                    }

                    continue;
                }
                $payloadLines[] = $line;
            }

            $raw = trim(implode(' ', $payloadLines));
            if ($raw === '') {
                continue;
            }

            $speaker = '';
            if (preg_match('/<v\s+([^>]+)>/', $raw, $m) === 1) {
                $speaker = trim($m[1]);
                // Strip an optional cue class prefix, e.g. "<v.loud Name>".
                if (str_starts_with($speaker, '.')) {
                    $speaker = trim((string) strstr($speaker, ' '));
                }
            }

            $text = trim((string) preg_replace('/<[^>]+>/', '', $raw));
            if ($text !== '') {
                $cues[] = ['speaker' => $speaker, 'text' => $text];
            }
        }

        return $cues;
    }

    /**
     * The meeting UUID that prefixes every cue id (e.g. "<uuid>/6-1") — a stable per-meeting external
     * id for dedup. Empty when the transcript carries no such id.
     */
    private function meetingId(string $body): string
    {
        if (preg_match('/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $body, $m) === 1) {
            return $m[1];
        }

        return '';
    }
}
