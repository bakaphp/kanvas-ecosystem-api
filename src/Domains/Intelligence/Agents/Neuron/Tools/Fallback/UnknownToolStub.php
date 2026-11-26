<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Fallback;

use NeuronAI\Tools\Tool;

/**
 * The answer to a tool the model named but was never given. Kept as a declared tool on the provider so a
 * vendor that validates function responses against the declarations it sent (Gemini) accepts the reply.
 */
final class UnknownToolStub extends Tool
{
    private const string UNNAMED = 'unnamed_tool';

    /**
     * What every provider accepts: Bedrock and OpenAI stop at `[a-zA-Z0-9_-]{1,64}`, Gemini wants a
     * letter or `_` first. Laravel agents check Gemini's grammar alone (KanvasToolInterface), dots included.
     */
    private const string DECLARABLE_NAME = '/^[a-zA-Z_][a-zA-Z0-9_-]{0,63}$/';

    private const int MAX_NAME_LENGTH = 64;

    /**
     * The name a call to a missing tool travels under. The call is replayed and its stub declared on the
     * next round, and a provider refuses the whole request over a name outside its grammar: Llama on
     * Bedrock called a tool with no name at all (KANVAS-ECOSYSTEM-708).
     */
    public static function declarableName(string $called): string
    {
        if (preg_match(self::DECLARABLE_NAME, $called) === 1) {
            return $called;
        }

        $name = trim((string) preg_replace('/[^a-zA-Z0-9_-]+/', '_', $called), '_-');

        if ($name === '') {
            return self::UNNAMED;
        }

        if (preg_match('/^[a-zA-Z]/', $name) !== 1) {
            $name = 'tool_' . $name;
        }

        return substr($name, 0, self::MAX_NAME_LENGTH);
    }

    /**
     * @param list<string> $available
     */
    public function __construct(string $name, private readonly array $available)
    {
        $this->name = $name;
        $this->description = 'NOT AVAILABLE. This tool does not exist for this agent — calling it only returns an error.';
    }

    /**
     * The payload the model reads, whichever half of the recovery answers.
     *
     * @param list<string> $available
     * @return array{status: string, message: string}
     */
    public static function response(string $name, array $available): array
    {
        return [
            'status' => 'error',
            'message' => sprintf(
                '%s Never call a tool that is not in your tool list. %s',
                $name === self::UNNAMED
                    ? 'You made a tool call without a tool name.'
                    : sprintf('There is no tool named "%s".', $name),
                $available === []
                    ? 'You have no tools on this turn — answer from the conversation instead.'
                    : 'Use one of these instead, or answer from what you already know: ' . implode(', ', $available) . '.'
            ),
        ];
    }

    /**
     * @return array{status: string, message: string}
     */
    public function __invoke(mixed ...$arguments): array
    {
        return self::response($this->name, $this->available);
    }
}
