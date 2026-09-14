<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ClaudeAgent\Services;

use Kanvas\Intelligence\Agents\Models\Agent;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolPropertyInterface;
use Throwable;

/**
 * Makes Kanvas tools callable from a hosted agent — what separates "a capable sandbox" from "a
 * Kanvas teammate".
 *
 * Managed Agents `custom` tools don't run remotely: the session goes idle and hands the call back
 * over the event stream, and we execute it here against the same PHP tool objects a Neuron agent
 * uses. Credentials therefore never enter the sandbox — the agent only ever sees declared inputs
 * and returned output.
 */
class CustomToolBridgeService
{
    /** @var list<ToolInterface>|null */
    protected ?array $tools = null;

    /**
     * @param list<ToolInterface>|null $tools Injectable so tests don't need the capability registry.
     * @param list<object> $additionalTools Injected for one turn only — the board toolset a wake job
     *        supplies. Merged on top of the agent's granted tools; a same-named extra wins, since it
     *        was built with the caller's own context.
     */
    public function __construct(
        protected readonly Agent $agent,
        ?array $tools = null,
        protected readonly array $additionalTools = [],
    ) {
        $this->tools = $tools;
    }

    /**
     * @return list<ToolInterface>
     */
    public function tools(): array
    {
        if ($this->tools === null) {
            $this->tools = $this->onlyTools(new KanvasToolResolverService($this->agent)->resolve());
        }

        if ($this->additionalTools === []) {
            return $this->tools;
        }

        $byName = [];

        foreach ([...$this->tools, ...$this->onlyTools($this->additionalTools)] as $tool) {
            $byName[$tool->getName()] = $tool;
        }

        return array_values($byName);
    }

    /**
     * @param list<object> $tools
     * @return list<ToolInterface>
     */
    protected function onlyTools(array $tools): array
    {
        return array_values(array_filter(
            $tools,
            static fn (object $tool): bool => $tool instanceof ToolInterface,
        ));
    }

    /**
     * These ride the agent spec, so changing a grant versions the remote agent — correct, since an
     * agent with different tools is a different agent.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        $definitions = [];

        foreach ($this->tools() as $tool) {
            $properties = [];

            foreach ($tool->getProperties() as $property) {
                if ($property instanceof ToolPropertyInterface) {
                    $properties[$property->getName()] = $property->getJsonSchema();
                }
            }

            $definitions[] = [
                'type' => 'custom',
                'name' => $tool->getName(),
                'description' => (string) $tool->getDescription(),
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) $properties,
                    'required' => array_values($tool->getRequiredProperties()),
                ],
            ];
        }

        return $definitions;
    }

    /**
     * Failures come back as `is_error` results, never exceptions: a thrown tool aborts the whole
     * turn, while an error result is handed to the agent, which can fix its arguments or take
     * another route. Same for a hallucinated tool name.
     *
     * @param array<string, mixed> $input
     * @return array{content: string, isError: bool}
     */
    public function call(string $name, array $input): array
    {
        $tool = $this->find($name);

        if ($tool === null) {
            return [
                'content' => "Unknown tool '{$name}'. Use only the tools declared for you.",
                'isError' => true,
            ];
        }

        try {
            // A fresh clone per call: NeuronAI tools carry inputs and the last result as instance
            // state, so reusing one across calls in the same turn would leak arguments between them.
            $invocation = clone $tool;
            $invocation->setInputs($input);
            $invocation->execute();

            return ['content' => $invocation->getResult(), 'isError' => false];
        } catch (Throwable $e) {
            return ['content' => $e->getMessage(), 'isError' => true];
        }
    }

    /**
     * Run every pending call and shape the results as `user.custom_tool_result` events. Both the
     * synchronous turn and the async poller unblock a session the same way.
     *
     * @param list<array{id: string, name: string, input: array<string, mixed>}> $calls
     * @return list<array<string, mixed>>
     */
    public function resultEvents(array $calls): array
    {
        $events = [];

        foreach ($calls as $call) {
            $outcome = $this->call($call['name'], $call['input']);

            $events[] = [
                'type' => 'user.custom_tool_result',
                'custom_tool_use_id' => $call['id'],
                'content' => [['type' => 'text', 'text' => $outcome['content']]],
                'is_error' => $outcome['isError'],
            ];
        }

        return $events;
    }

    protected function find(string $name): ?ToolInterface
    {
        foreach ($this->tools() as $tool) {
            if ($tool->getName() === $name) {
                return $tool;
            }
        }

        return null;
    }
}
