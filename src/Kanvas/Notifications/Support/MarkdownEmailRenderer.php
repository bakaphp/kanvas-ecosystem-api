<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Support;

use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Converts agent-generated Markdown into email-safe HTML.
 *
 * LLM agents reply in Markdown (`**bold**`, `- bullets`, `[links](url)`, headings).
 * The mail layout renders its body with `{!! $html !!}` (raw), so without this the
 * recipient sees literal markdown syntax. `soft_break => <br>` preserves the single
 * newlines agents use for signatures / address blocks, which CommonMark would
 * otherwise collapse into a single line.
 */
final class MarkdownEmailRenderer
{
    /**
     * @param bool $allowHtml false for content that leaves the building — an LLM-written, human-editable
     *                        customer email. It escapes raw HTML instead of passing it through, and skips
     *                        the idempotency guard, which is itself a passthrough: content that merely
     *                        LOOKS like HTML is returned unconverted, so a raw <a href> or an
     *                        <img onerror> reaches the recipient verbatim. Internal agent replies keep
     *                        the permissive default.
     */
    public static function toEmailHtml(string $markdown, bool $allowHtml = true): string
    {
        $trimmed = trim($markdown);

        if ($trimmed === '') {
            return $trimmed;
        }

        // Idempotency guard: if the content already looks like HTML (an agent that
        // emitted HTML, or a value that was already converted), don't double-process.
        if ($allowHtml && self::looksLikeHtml($trimmed)) {
            return $markdown;
        }

        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => $allowHtml ? 'allow' : 'escape',
            'allow_unsafe_links' => false,
            'renderer' => [
                'soft_break' => "<br>\n",
            ],
        ]);

        return (string) $converter->convert($markdown);
    }

    private static function looksLikeHtml(string $value): bool
    {
        return (bool) preg_match('/<(p|br|div|ul|ol|li|strong|em|a|h[1-6]|table)\b[^>]*>/i', $value);
    }
}
