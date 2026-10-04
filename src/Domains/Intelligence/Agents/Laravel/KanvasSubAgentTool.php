<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel;

use Baka\Support\Str;
use Generator;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\Request;
use Override;

/**
 * laravel-ai's `AgentTool` hands the parent whatever text the sub-agent ended on, which is '' when the
 * sub-agent spent its whole step budget on tool calls. Gemini's Interactions API rejects a function
 * result whose text block is empty ("Request contains an invalid argument", Sentry KANVAS-ECOSYSTEM-6J2)
 * and every other provider lets the parent reason over nothing, so the empty result becomes a statement
 * the parent can act on.
 */
final class KanvasSubAgentTool extends AgentTool
{
    public const string NO_ANSWER = 'The sub-agent ended without an answer: it used all of its steps on tool calls. Treat this as no result and decide from what you already know.';

    /**
     * What an agent's tool list hands laravel-ai: a sub-agent wrapped, anything else untouched.
     */
    public static function wrap(object $tool): object
    {
        return $tool instanceof KanvasAgentAsTool ? new self($tool) : $tool;
    }

    #[Override]
    public function handle(Request $request): string
    {
        return self::nonEmpty(parent::handle($request));
    }

    #[Override]
    public function stream(Request $request): Generator
    {
        return self::nonEmpty(yield from parent::stream($request));
    }

    private static function nonEmpty(string $text): string
    {
        return Str::trimToNull($text) ?? self::NO_ANSWER;
    }
}
