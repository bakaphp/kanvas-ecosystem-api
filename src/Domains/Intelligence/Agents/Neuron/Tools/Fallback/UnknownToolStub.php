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
                'There is no tool named "%s". Never call a tool that is not in your tool list. %s',
                $name,
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
