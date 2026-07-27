<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Routing\Actions;

use Illuminate\Support\Str;
use Kanvas\NervousSystem\Orchestrator\Signals\DataTransferObject\InboundSignal;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\StructuredAgentResponse;

use function Laravel\Ai\agent;

/**
 * Fan-out: split one signal into the DISTINCT topics it covers so each can be routed
 * to a different project. A leadership sync touching three initiatives becomes three segments, each
 * routed independently by RouteSignalAction. A single-topic signal returns ONE segment (the signal
 * whole) — the common case pays nothing extra downstream. Short signals skip the LLM entirely: too
 * small to be multi-topic. Segmentation is by CONTENT (topics), never by attendee — actors stay
 * parent-wide on every segment so the deterministic member-match still works per segment.
 */
class SegmentSignalAction
{
    // Below this a signal is treated as single-topic — not worth an LLM segmentation call.
    private const int MIN_SEGMENTABLE_LENGTH = 1500;
    private const int CONTENT_CHAR_CAP = 12000;
    private const int MAX_SEGMENTS = 6;

    public function __construct(
        private readonly InboundSignal $signal,
        private readonly string $model = 'gemini-2.5-pro',
    ) {
    }

    /**
     * @return list<InboundSignal> one entry (the original signal) when single-topic; several when fanned out
     */
    public function execute(): array
    {
        if (mb_strlen(trim($this->signal->content)) < self::MIN_SEGMENTABLE_LENGTH) {
            return [$this->signal];
        }

        /** @var StructuredAgentResponse $response */
        $response = agent(
            schema: fn ($schema): array => [
                'segments' => $schema->array()
                    ->description('Each DISTINCT topic/workstream the signal covers. ONE segment for a '
                        . 'single-topic signal; several ONLY when clearly separate initiatives are discussed.')
                    ->items($schema->object([
                        'title' => $schema->string()
                            ->description('Short title naming this topic.')
                            ->required(),
                        'content' => $schema->string()
                            ->description('The self-contained portion of the signal about THIS topic.')
                            ->required(),
                    ])),
            ],
        )->prompt(
            $this->buildPrompt(),
            provider: Lab::Gemini,
            model: $this->model,
            timeout: 220,
        );

        return $this->normalize($response->structured);
    }

    /**
     * @param array<string, mixed> $structured
     *
     * @return list<InboundSignal>
     */
    private function normalize(array $structured): array
    {
        $raw = $structured['segments'] ?? [];
        if (! is_array($raw)) {
            return [$this->signal];
        }

        $segments = [];
        foreach (array_values($raw) as $index => $segment) {
            $content = trim((string) (is_array($segment) ? ($segment['content'] ?? '') : ''));
            if ($content === '') {
                continue;
            }

            $segments[] = $this->signal->segment(
                (string) ($segment['title'] ?? ''),
                $content,
                $index + 1,
            );

            if (count($segments) >= self::MAX_SEGMENTS) {
                break;
            }
        }

        // 0 or 1 topic → no fan-out; route the original signal whole.
        return count($segments) > 1 ? $segments : [$this->signal];
    }

    private function buildPrompt(): string
    {
        return "Split this inbound {$this->signal->kind->value} into the DISTINCT topics it covers, so each "
            . "can be routed to the project it belongs to.\n\n"
            . "A signal about ONE thing yields ONE segment (return it whole). Split into several ONLY when "
            . "clearly separate initiatives/workstreams are discussed — e.g. a leadership sync covering three "
            . "different projects. NEVER fragment a single topic into pieces.\n\n"
            . "SIGNAL\n"
            . "Title: {$this->signal->title}\n"
            . "Content:\n" . Str::limit($this->signal->content, self::CONTENT_CHAR_CAP);
    }
}
