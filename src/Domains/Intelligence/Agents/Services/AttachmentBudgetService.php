<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Baka\Support\Str;

/**
 * Bounds what one turn's attachments may add to the prompt.
 *
 * The provider's input ceiling has a third half nobody guarded: stored history is trimmed by
 * RebuildsTrimmedHistory and in-turn tool output by BoundToolResultsMiddleware, but the content
 * blocks built from a turn's own attachments were counted by neither. Only the per-file caps
 * applied — 200KB for inlined text, and SSRF_MAX_BYTES (50MB) for everything else — with no limit
 * on how many files a message carries. One large PDF, or a WhatsApp burst carrying several, put the
 * request past the ceiling; the provider answers 400, 400 is deliberately not retryable, and the
 * turn surfaces as "I ran into a hiccup" with the real cause nowhere in sight.
 *
 * Counts raw bytes, not base64: the 4/3 inflation is applied by the provider client, and 10MB raw
 * lands near 13MB encoded — inside Gemini's inline-request cap with room for the prompt itself.
 */
final class AttachmentBudgetService
{
    public const int MAX_TURN_BYTES = 10 * 1024 * 1024;

    private int $used = 0;

    /** @var list<string> */
    private array $skipped = [];

    public function __construct(
        private readonly int $maxBytes = self::MAX_TURN_BYTES,
    ) {
    }

    /**
     * Charge an attachment against the budget. False means it does not fit and must be skipped —
     * the source is remembered so {@see skippedNote()} can tell the model what it is not seeing.
     */
    public function admits(string $source, int $bytes): bool
    {
        if ($this->used + $bytes > $this->maxBytes) {
            $this->skipped[] = $source;

            return false;
        }

        $this->used += $bytes;

        return true;
    }

    /**
     * Silence would leave the model answering as though it had read every attachment — the same
     * failure AttachmentFetchService::unavailableNote() exists to prevent for an unreadable one.
     */
    public function skippedNote(): ?string
    {
        if ($this->skipped === []) {
            return null;
        }

        $names = array_map(
            static fn (string $source): string => Str::fileNameFromUrl($source, 'attachment'),
            $this->skipped,
        );

        return sprintf(
            '[%d attachment(s) were too large to include in this message and are not visible to you: %s. '
                . 'If the person refers to them, say they were too large to open and ask for a smaller file '
                . 'or the relevant excerpt.]',
            count($names),
            implode(', ', $names),
        );
    }
}
