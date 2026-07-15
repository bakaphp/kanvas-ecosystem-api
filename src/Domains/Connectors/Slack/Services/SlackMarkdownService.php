<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Services;

/**
 * Slack's mrkdwn is not Markdown: `*bold*` (not `**bold**`), `_italic_`, `~strike~`, and links are
 * `<url|text>`. An agent writes Markdown, so its reply renders with literal asterisks unless it
 * goes through here first.
 *
 * https://api.slack.com/reference/surfaces/formatting
 */
class SlackMarkdownService
{
    public static function fromMrkdwn(string $mrkdwn): string
    {
        // <#C123|general> → #general   (fall back to the id when Slack omits the name)
        $text = preg_replace('/<#[A-Z0-9]+\|([^>]+)>/', '#$1', $mrkdwn) ?? $mrkdwn;
        $text = preg_replace('/<#([A-Z0-9]+)>/', '#$1', $text) ?? $text;

        // <@U123|carla> → @carla ; <@U123> → @U123
        $text = preg_replace('/<@[A-Z0-9]+\|([^>]+)>/', '@$1', $text) ?? $text;
        $text = preg_replace('/<@([A-Z0-9]+)>/', '@$1', $text) ?? $text;

        // <!here> / <!channel> / <!subteam^S1|@team>
        $text = preg_replace('/<!(?:subteam\^[A-Z0-9]+\|)?@?([a-z]+)>/i', '@$1', $text) ?? $text;

        // <https://x|the deal> → [the deal](https://x) ; <https://x> → https://x
        $text = preg_replace('/<((?:https?|mailto):[^>|]+)\|([^>]+)>/', '[$2]($1)', $text) ?? $text;
        $text = preg_replace('/<((?:https?|mailto):[^>|]+)>/', '$1', $text) ?? $text;

        return trim(str_replace(['&amp;', '&lt;', '&gt;'], ['&', '<', '>'], $text));
    }

    public static function toMrkdwn(string $markdown): string
    {
        // Fenced code blocks keep their content verbatim — pull them out, convert, put them back.
        $codeBlocks = [];
        $text = preg_replace_callback(
            '/```.*?```/s',
            function (array $match) use (&$codeBlocks): string {
                $codeBlocks[] = $match[0];

                return '%%KANVAS_CODE_BLOCK_' . (count($codeBlocks) - 1) . '%%';
            },
            $markdown
        ) ?? $markdown;

        // [text](url) → <url|text>
        $text = preg_replace('/\[([^\]]+)\]\(([^)\s]+)\)/', '<$2|$1>', $text) ?? $text;

        // **bold** / __bold__ → *bold*. Must run before the italic rule, which would otherwise
        // eat the inner asterisks.
        $text = preg_replace('/\*\*(.+?)\*\*/s', '*$1*', $text) ?? $text;
        $text = preg_replace('/__(.+?)__/s', '*$1*', $text) ?? $text;

        // Markdown headings have no mrkdwn equivalent — bold the line instead.
        $text = preg_replace('/^#{1,6}\s+(.+)$/m', '*$1*', $text) ?? $text;

        foreach ($codeBlocks as $index => $codeBlock) {
            $text = str_replace('%%KANVAS_CODE_BLOCK_' . (int) $index . '%%', (string) $codeBlock, $text);
        }

        return $text;
    }
}
