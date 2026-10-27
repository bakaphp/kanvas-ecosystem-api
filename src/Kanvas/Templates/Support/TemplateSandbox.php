<?php

declare(strict_types=1);

namespace Kanvas\Templates\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Notifications\Support\MarkdownEmailRenderer;
use Twig\Environment;
use Twig\Error\Error as TwigError;
use Twig\Error\SyntaxError;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Node\Expression\Binary\RangeBinary;
use Twig\Node\Node;
use Twig\Runtime\EscaperRuntime;
use Twig\Sandbox\SecurityPolicy;
use Twig\Source;
use Twig\TemplateWrapper;
use Twig\TwigFilter;

/**
 * Renders tenant-written text (agent roles, prompt overrides, stage messages, twig email templates)
 * with Twig in sandbox mode. Stored text written for Blade is translated expression by expression,
 * never evaluated.
 * The context is flattened by PlainData first.
 */
class TemplateSandbox
{
    private const array FILTERS = [
        'capitalize',
        'date',
        'date_modify',
        'default',
        'e',
        'escape',
        'first',
        'join',
        'json_encode',
        'keys',
        'last',
        'length',
        'lower',
        'nl2br',
        'number_format',
        'replace',
        'round',
        'slice',
        'split',
        'striptags',
        'title',
        'trim',
        'upper',
        'url_encode',
    ];

    private const array HTML_FILTERS = ['raw', 'markdown'];

    private const array TESTS = ['defined', 'empty', 'null', 'none', 'iterable'];

    private const string PATH = '\$[A-Za-z_]\w*(?:(?:\??->)[A-Za-z_]\w*|\[\s*(?:\'[^\'"\\\\]*\'|"[^\'"\\\\$]*"|\d+)\s*\])*';

    /**
     * Plain-text mode for LLM prompts and chat messages. On a template that does not parse or breaks
     * the sandbox the text comes back unrendered, which degrades better than an exception there.
     */
    public static function render(string $template, array $context = []): string
    {
        if (! str_contains($template, '{')) {
            return $template;
        }

        try {
            return self::compile($template, html: false)->render(PlainData::from($context));
        } catch (TwigError $e) {
            Log::warning('Template sandbox refused a template', [
                'error' => $e->getRawMessage(),
                'line' => $e->getTemplateLine(),
            ]);

            return $template;
        }
    }

    /**
     * HTML mode for email, push and PDF templates: values are escaped like Blade's `{{ }}`, and a
     * failure throws, because mailing the template source to a customer is worse than not sending.
     */
    public static function renderHtml(string $template, array $context = []): string
    {
        return self::compile($template, html: true)->render(PlainData::from($context));
    }

    /**
     * Run when a template is saved, so a template the sandbox refuses never reaches a render.
     */
    public static function validate(string $template): void
    {
        try {
            self::compile($template, html: true);
        } catch (TwigError $e) {
            throw new ValidationException('Invalid template: ' . $e->getRawMessage());
        }
    }

    private static function compile(string $template, bool $html): TemplateWrapper
    {
        $source = self::translateBlade($template, $html);
        $twig = self::environment($html);

        // `a..b` compiles straight to PHP's range(), which the sandbox does not see; a large one
        // exhausts the worker's memory.
        self::assertNoRange($twig->parse($twig->tokenize(new Source($source, 'template'))));

        $template = $twig->createTemplate($source);

        // The sandbox checks tags, filters and tests when a template first renders; run it now so a
        // refused template fails when it is saved, not when it is sent.
        $template->unwrap()->checkSecurity();

        return $template;
    }

    private static function assertNoRange(Node $node): void
    {
        if ($node instanceof RangeBinary) {
            throw new SyntaxError('The range operator is not allowed.', $node->getTemplateLine());
        }

        foreach ($node as $child) {
            self::assertNoRange($child);
        }
    }

    /**
     * Blade echoes become Twig expressions when they are a variable path with an optional `??`
     * default, or `json_encode(path, FLAGS)`. Anything else is kept as literal text.
     */
    private static function translateBlade(string $template, bool $html): string
    {
        $template = preg_replace('/\{\{--.*?--\}\}/s', '', $template) ?? $template;

        return preg_replace_callback(
            '/\{!!(.*?)!!\}|\{\{(.*?)\}\}/s',
            function (array $match) use ($html): string {
                $isRaw = ($match[2] ?? '') === '';
                $expression = trim($isRaw ? $match[1] : $match[2]);

                if (! str_contains($expression, '$')) {
                    return $match[0];
                }

                $twig = self::translateExpression($expression);

                if ($twig === null) {
                    return '{% verbatim %}' . $match[0] . '{% endverbatim %}';
                }

                return '{{ ' . $twig . ($html && $isRaw ? '|raw' : '') . ' }}';
            },
            $template
        ) ?? $template;
    }

    private static function translateExpression(string $expression): ?string
    {
        if (preg_match('/^json_encode\(\s*(' . self::PATH . ')\s*(?:,\s*([A-Z_|\s]+))?\)$/', $expression, $m)) {
            $flags = self::jsonFlags($m[2] ?? '');

            return $flags === null ? null : self::translatePath($m[1]) . '|json_encode(' . $flags . ')';
        }

        $default = '(?:\'[^\'\\\\]*\'|"[^\'"\\\\$#]*"|-?\d+(?:\.\d+)?|null|' . self::PATH . ')';

        if (! preg_match('/^(' . self::PATH . ')(?:\s*\?\?\s*(' . $default . '))?$/', $expression, $m)) {
            return null;
        }

        $twig = self::translatePath($m[1]);

        if (isset($m[2])) {
            $fallback = $m[2];
            $twig .= ' ?? ' . match (true) {
                str_starts_with($fallback, '$') => self::translatePath($fallback),
                str_starts_with($fallback, '"') => "'" . substr($fallback, 1, -1) . "'",
                default => $fallback,
            };
        }

        return $twig;
    }

    /**
     * `$lead?->leadStatus['name']` → `lead.lead_status['name']`. Property segments are snake-cased
     * because that is how a model's loaded relations are keyed once it is flattened to an array.
     */
    private static function translatePath(string $path): string
    {
        preg_match_all('/^\$([A-Za-z_]\w*)|\??->([A-Za-z_]\w*)|\[\s*(\'[^\']*\'|"[^"]*"|\d+)\s*\]/', $path, $segments, PREG_SET_ORDER);

        $twig = '';

        foreach ($segments as $segment) {
            $twig .= match (true) {
                ($segment[1] ?? '') !== '' => $segment[1],
                ($segment[2] ?? '') !== '' => '.' . Str::snake($segment[2]),
                default => "['" . trim($segment[3], '\'"') . "']",
            };
        }

        return $twig;
    }

    private static function jsonFlags(string $flags): ?int
    {
        $value = 0;

        foreach (array_filter(array_map('trim', explode('|', $flags))) as $name) {
            if (! str_starts_with($name, 'JSON_') || ! defined($name)) {
                return null;
            }

            $value |= constant($name);
        }

        return $value;
    }

    private static function environment(bool $html): Environment
    {
        $twig = new Environment(new ArrayLoader(), [
            'autoescape' => $html ? 'markup' : 'prompt',
            'strict_variables' => false,
            'cache' => false,
        ]);

        $escaper = $twig->getRuntime(EscaperRuntime::class);
        $escaper->setEscaper('prompt', fn (mixed $value): string => self::stringify($value));
        $escaper->setEscaper(
            'markup',
            fn (mixed $value): string => htmlspecialchars(self::stringify($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );

        $filters = self::FILTERS;

        if ($html) {
            $twig->addFilter(new TwigFilter(
                'markdown',
                fn (mixed $value): string => MarkdownEmailRenderer::toEmailHtml((string) $value, allowHtml: false),
                ['is_safe' => ['all']]
            ));
            $filters = array_merge($filters, self::HTML_FILTERS);
        }

        $twig->addExtension(new SandboxExtension(
            new SecurityPolicy(
                allowedTags: $html ? ['if', 'for', 'set'] : ['if'],
                allowedFilters: $filters,
                allowedTests: self::TESTS,
            ),
            true
        ));

        return $twig;
    }

    private static function stringify(mixed $value): string
    {
        return is_array($value)
            ? (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : (string) $value;
    }
}
