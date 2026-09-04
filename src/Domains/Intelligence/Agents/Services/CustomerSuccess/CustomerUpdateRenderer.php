<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services\CustomerSuccess;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Intelligence\Agents\DataTransferObject\CustomerUpdateDraft;
use Kanvas\Notifications\Support\MarkdownEmailRenderer;
use Kanvas\Templates\Actions\RenderTemplateAction;

/**
 * The agent writes plain text on purpose — markup is not its job, and an LLM emitting it is
 * inconsistent and unescaped. Markdown for the note a human reads, HTML for the email.
 */
class CustomerUpdateRenderer
{
    /** Resolved company → app → global, so one company can look different from the rest. */
    public const string TEMPLATE_NAME = 'customer-update-email';

    private const string FONT = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif";
    private const string INK = '#1b2320';
    private const string MUTED = '#5f6b68';
    private const string RULE = '#e3e8e6';
    private const string P = 'font:400 16px/1.7 ' . self::FONT . ';color:' . self::INK . ';margin:0 0 14px;';

    private const array BLOCK_STYLES = [
        '<h2>' => '<h2 style="font:700 23px/1.3 ' . self::FONT . ';letter-spacing:-.02em;color:'
            . self::INK . ';margin:30px 0 8px;">',
        '<h3>' => '<h3 style="font:700 18px/1.35 ' . self::FONT . ';color:' . self::INK . ';margin:24px 0 6px;">',
        '<p>' => '<p style="' . self::P . '">',
        '<ul>' => '<ul style="' . self::P . 'padding-left:20px;">',
        '<ol>' => '<ol style="' . self::P . 'padding-left:20px;">',
        '<li>' => '<li style="font:400 16px/1.7 ' . self::FONT . ';color:' . self::INK . ';margin:0 0 6px;">',
        '<a href' => '<a style="color:' . self::INK . ';text-decoration:underline;" href',
    ];

    /**
     * Takes the markdown, not the draft: the approval card shows the markdown and a human may rewrite
     * it, so rendering from the draft would mail the copy nobody signed off on. `allowHtml: false`
     * because it leaves the building — raw HTML in that box gets escaped, not forwarded to a customer.
     */
    public function toEmailHtml(
        string $markdown,
        ?AppInterface $app = null,
        ?CompanyInterface $company = null
    ): string {
        $body = $this->applyInlineStyles(
            MarkdownEmailRenderer::toEmailHtml($markdown, allowHtml: false)
        );

        if ($app === null) {
            return $this->shell($body);
        }

        try {
            return new RenderTemplateAction($app, $company)->execute(self::TEMPLATE_NAME, ['body' => $body]);
        } catch (ModelNotFoundException) {
            return $this->shell($body);
        }
    }

    public function toMarkdown(CustomerUpdateDraft $draft): string
    {
        $parts = $this->parse($draft->body);

        if ($parts === null) {
            return trim($draft->body);
        }

        $out = ['# ' . $parts['masthead']];

        if ($parts['intro'] !== null) {
            $out[] = $parts['intro'];
        }

        foreach ($parts['sections'] as $section) {
            if ($section['headline'] !== null) {
                $out[] = '## ' . $section['headline'];
            }

            $out[] = $section['body'];
        }

        if ($parts['signOff'] !== null) {
            $out[] = '---';
            $out[] = $parts['signOff'];
        }

        return implode("\n\n", $out);
    }

    /**
     * The h1 is a masthead rather than a headline, so it is styled here before the sweep at the end
     * gives every remaining tag its default.
     */
    private function applyInlineStyles(string $html): string
    {
        [$body, $signOff] = array_pad(preg_split('/<hr\s*\/?>/', $html, 2) ?: [$html], 2, null);

        $body = preg_replace(
            '/<h1>/',
            '<h1 style="font:400 15px/1.4 ' . self::FONT . ';color:' . self::MUTED . ';margin:0 0 20px;">',
            (string) $body,
            1
        );

        if ($signOff !== null) {
            $body .= '<hr style="border:0;border-top:1px solid ' . self::RULE . ';margin:34px 0 24px;">' . $signOff;
        }

        return strtr((string) $body, self::BLOCK_STYLES);
    }

    /**
     * @return array{masthead: string, intro: string|null, sections: array<int, array{headline: string|null, body: string}>, signOff: string|null}|null
     */
    private function parse(string $body): ?array
    {
        $blocks = $this->blocks($body);

        if ($blocks === []) {
            return null;
        }

        $masthead = implode(' ', array_shift($blocks));
        $signOff = count($blocks) > 1 ? implode("\n", array_pop($blocks)) : null;
        $intro = $blocks !== [] ? implode(' ', array_shift($blocks)) : null;

        $sections = array_map(
            // A lone line is a paragraph, not a headline — a headline with nothing under it would
            // render as a heading for content that is not there.
            fn (array $block): array => count($block) < 2
                ? ['headline' => null, 'body' => implode(' ', $block)]
                : ['headline' => $block[0], 'body' => implode(' ', array_slice($block, 1))],
            $blocks
        );

        return [
            'masthead' => $masthead,
            'intro' => $intro,
            'sections' => $sections,
            'signOff' => $signOff,
        ];
    }

    /**
     * Blank lines separate blocks; the agent is instructed to write that way.
     *
     * @return array<int, array<int, string>>
     */
    private function blocks(string $body): array
    {
        $chunks = preg_split('/\n\s*\n/', trim($body)) ?: [];

        return array_values(array_filter(array_map(
            fn (string $chunk): array => array_values(array_filter(
                array_map('trim', preg_split('/\n/', trim($chunk)) ?: []),
                fn (string $line): bool => $line !== ''
            )),
            $chunks
        )));
    }

    private function shell(string $inner): string
    {
        return '<div style="font-family:' . self::FONT . ';color:' . self::INK
            . ';max-width:600px;margin:0 auto;padding:40px 24px;">' . $inner . '</div>';
    }
}
