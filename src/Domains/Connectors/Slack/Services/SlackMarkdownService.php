<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Services;

use InvalidArgumentException;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Parser\MarkdownParser;

class SlackMarkdownService
{
    /** @return list<string> */
    public static function split(string $markdown, int $limit = 3000): array
    {
        if ($limit < 32) {
            throw new InvalidArgumentException('Slack Markdown chunks must allow at least 32 bytes.');
        }

        if (strlen($markdown) <= $limit) {
            return [$markdown];
        }

        $environment = new Environment();
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $document = new MarkdownParser($environment)->parse($markdown);
        $lines = preg_split('/(?<=\n)/', $markdown);
        $chunks = [];
        $current = '';
        $offset = 0;

        foreach ($document->children() as $block) {
            $end = $block->getEndLine();
            $text = implode('', array_slice($lines, $offset, $end - $offset));
            $offset = $end;

            if (strlen($current . $text) <= $limit) {
                $current .= $text;

                continue;
            }

            if ($current !== '') {
                $chunks[] = $current;
                $current = '';
            }

            if (strlen($text) <= $limit) {
                $current = $text;

                continue;
            }

            // Each message is parsed independently, so oversized code fences must be reopened.
            if ($block instanceof FencedCode) {
                $fence = str_repeat($block->getChar(), $block->getLength());
                $opening = $fence . ($block->getInfo() ?? '') . "\n";
                $closing = "\n" . $fence;
                $budget = $limit - strlen($opening . $closing);

                if ($budget >= 4) {
                    foreach (self::splitVerbatim($block->getLiteral(), $budget) as $part) {
                        $chunks[] = $opening . $part . $closing;
                    }

                    continue;
                }
            }

            array_push($chunks, ...self::splitVerbatim($text, $limit));
        }

        $current .= implode('', array_slice($lines, $offset));
        if ($current !== '') {
            array_push($chunks, ...self::splitVerbatim($current, $limit));
        }

        return $chunks;
    }

    /** @return list<string> */
    private static function splitVerbatim(string $text, int $limit): array
    {
        $chunks = [];

        while (strlen($text) > $limit) {
            $chunk = mb_strcut($text, 0, $limit, 'UTF-8');
            $newline = strrpos($chunk, "\n");
            if ($newline !== false) {
                $chunk = substr($chunk, 0, $newline + 1);
            }

            $chunks[] = $chunk;
            $text = substr($text, strlen($chunk));
        }

        if ($text !== '') {
            $chunks[] = $text;
        }

        return $chunks;
    }

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
