<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Providers;

use Illuminate\Support\Facades\Log;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use Override;

/**
 * A model that calls a tool it was never given kills the whole turn: Neuron's findTool() throws
 * ProviderException while parsing the response, before any tool runs, so the user gets the generic
 * "I ran into a hiccup" apology and Sentry gets an error for what is a recoverable model mistake.
 * The usual trigger is a sibling tool's description naming a tool the agent wasn't granted — the
 * SalesManagerAgent's lead tools point at `get_lead_ref` (KANVAS-ECOSYSTEM-675).
 *
 * Answer with a stand-in tool instead: its result tells the model the name doesn't exist and lists
 * what it actually has, so the next inference round self-corrects. The stub is then kept in the
 * declared tool list, because a provider that validates function responses against the declarations
 * it sent (Gemini) would reject a response for a function it never declared.
 */
trait RecoversUnknownToolCalls
{
    /** @var array<string, Tool> */
    private array $unknownToolStubs = [];

    #[Override]
    public function setTools(array $tools): AIProviderInterface
    {
        return parent::setTools([...$tools, ...array_values($this->unknownToolStubs)]);
    }

    #[Override]
    public function findTool(string $name): ToolInterface
    {
        try {
            return parent::findTool($name);
        } catch (ProviderException) {
            $available = $this->availableToolNames();

            Log::warning('Agent called a tool it was not given; answering with a not-found stub.', [
                'tool' => $name,
                'provider' => static::class,
                'available_tools' => $available,
            ]);

            return clone $this->registerUnknownToolStub($name, $available);
        }
    }

    /**
     * @param list<string> $available
     */
    private function registerUnknownToolStub(string $name, array $available): Tool
    {
        $stub = Tool::make(
            $name,
            'NOT AVAILABLE. This tool does not exist for this agent — calling it only returns an error.'
        )->setCallable(fn (): array => [
            'status' => 'error',
            'message' => sprintf(
                'There is no tool named "%s". Never call a tool that is not in your tool list. %s',
                $name,
                $available === []
                    ? 'You have no tools on this turn — answer from the conversation instead.'
                    : 'Use one of these instead, or answer from what you already know: '
                        . implode(', ', $available) . '.'
            ),
        ]);

        $this->unknownToolStubs[$name] = $stub;
        $this->tools[] = $stub;

        return $stub;
    }

    /**
     * @return list<string>
     */
    private function availableToolNames(): array
    {
        return array_values(
            array_map(
                fn (ToolInterface $tool): string => $tool->getName(),
                array_filter($this->tools, fn (object $tool): bool => $tool instanceof ToolInterface)
            )
        );
    }
}
