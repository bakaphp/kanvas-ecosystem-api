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
 * Plain timestamped transcript webhook → InboundSignal. Payload: `{ "type": "transcript", "transcript":
 * "Title\nDate\n\n0:08 - Speaker\ntext\n\n0:36 - Speaker\ntext..." }`. Speaker comes from each block's
 * `M:SS - Name` header; the first preamble line (before the first timestamp) is the title; there are no
 * participant emails so routing is content-based.
 */
final class PlainTranscriptSignalAdapter implements SignalSourceAdapter
{
    use NormalizesTranscriptCues;

    #[Override]
    public function parse(array $payload): InboundSignal
    {
        $body = (string) ($payload['transcript'] ?? '');
        $cues = $this->cues($body);

        return new InboundSignal(
            source: SignalSourceEnum::PLAIN_TRANSCRIPT,
            kind: ProjectIngestTypeEnum::TRANSCRIPT,
            externalId: '',
            title: $this->title($body) ?? 'Untitled meeting',
            content: $this->contentFromCues($cues),
            occurredAt: null,
            actors: $this->actorsFromCues($cues),
            metadata: ['type' => (string) ($payload['type'] ?? 'transcript')],
        );
    }

    /**
     * Split the body on each "M:SS - Speaker" header into ordered cues. The preamble before the first
     * timestamp (title + date) has no header and is skipped — see title().
     *
     * @return list<array{speaker: string, text: string}>
     */
    private function cues(string $body): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", trim($body));

        $cues = [];
        foreach (preg_split('/\n(?=\d{1,2}:\d{2}\s*-\s)/', $normalized) ?: [] as $block) {
            $block = trim($block);
            if (preg_match('/^\d{1,2}:\d{2}\s*-\s/', $block) !== 1) {
                continue;
            }

            [$header, $text] = array_pad(explode("\n", $block, 2), 2, '');
            preg_match('/^\d{1,2}:\d{2}\s*-\s*(.*)$/', trim($header), $sm);
            $speaker = trim($sm[1] ?? '');
            $text = trim($text);

            if ($text !== '') {
                $cues[] = ['speaker' => $speaker, 'text' => $text];
            }
        }

        return $cues;
    }

    /**
     * The first preamble line is the title (the line before the first "M:SS -" timestamp). Null when the
     * body opens straight into a timestamped cue.
     */
    private function title(string $body): ?string
    {
        $first = trim((string) strtok(str_replace(["\r\n", "\r"], "\n", trim($body)), "\n"));

        if ($first === '' || preg_match('/^\d{1,2}:\d{2}\s*-\s/', $first) === 1) {
            return null;
        }

        return $first;
    }
}
