<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Providers;

use Illuminate\Support\Facades\Log;
use Kanvas\Intelligence\Agents\Neuron\Tools\Fallback\UnknownToolStub;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\ToolInterface;
use Override;

/**
 * A model that calls a tool it was never given (usually one a sibling tool's description names,
 * KANVAS-ECOSYSTEM-675) must not kill the turn: findTool() throws while parsing the response, before any
 * tool runs. Answer with a stand-in tool instead, and keep it in the declared tool list, because a
 * provider that validates function responses against the declarations it sent (Gemini) rejects a
 * response for a function it never declared. The agent half of the recovery is
 * HasKanvasAgentBehavior::resolveToolErrorHandler(): ToolNode resolves the call against the agent's
 * registry, where the stub is not, and the handler answers that with the same feedback.
 */
trait RecoversUnknownToolCalls
{
    /** @var array<string, UnknownToolStub> */
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
    private function registerUnknownToolStub(string $name, array $available): UnknownToolStub
    {
        $stub = new UnknownToolStub($name, $available);

        $this->unknownToolStubs[$name] = $stub;
        $this->tools[] = $stub;

        return $stub;
    }

    /**
     * @return list<string>
     */
    private function availableToolNames(): array
    {
        // A stub registered for an earlier hallucination stays declared, but it is not a tool to offer.
        return array_values(
            array_map(
                fn (ToolInterface $tool): string => $tool->getName(),
                array_filter(
                    $this->tools,
                    fn (object $tool): bool => $tool instanceof ToolInterface && ! $tool instanceof UnknownToolStub
                )
            )
        );
    }
}
