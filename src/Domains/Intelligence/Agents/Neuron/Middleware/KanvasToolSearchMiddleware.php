<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Middleware;

use Kanvas\Intelligence\Agents\Neuron\Tools\System\KanvasToolSearchTool;
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Middleware\ToolSearchMiddleware;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Events\Event;
use Override;

/**
 * Neuron's middleware with the search tool swapped for KanvasToolSearchTool. The tool needs to know
 * how many searches this turn already ran, and the registry keeps the first `tool_search` it was
 * given, so the tool is re-registered on every node with the count read off the turn's messages.
 * Counting from the messages rather than a property keeps it right across a durable-run resume.
 */
class KanvasToolSearchMiddleware extends ToolSearchMiddleware
{
    protected const string KANVAS_SYSTEM_PROMPT = parent::DEFAULT_SYSTEM_PROMPT . <<<'PROMPT'

        A search that finds nothing lists every tool you hold. Do not search again for the same need with other words; the list is complete.
        PROMPT;

    /** @var array<string, ToolInterface> */
    private array $poolByName = [];

    /**
     * @param ToolInterface[] $toolPool
     * @param int<1,max> $topN
     */
    public function __construct(array $toolPool, int $topN = 5)
    {
        parent::__construct($toolPool, $topN, self::KANVAS_SYSTEM_PROMPT);

        foreach ($toolPool as $tool) {
            $this->poolByName[$tool->getName()] = $tool;
        }
    }

    #[Override]
    protected function beforeAgentNode(
        AgentNodeInterface $node,
        Event $event,
        AgentState $state,
        AgentResources $resources,
    ): void {
        $turn = isset($state->request)
            ? $this->currentTurn([...$resources->history->getMessages(), ...$state->request->messages])
            : [];

        $search = new KanvasToolSearchTool($this->toolPool, $this->topN, $this->searchesIn($turn));
        $resources->tools->remove($search->getName());
        $resources->tools->add($search);

        if ($event instanceof ToolCallEvent) {
            foreach ($this->calledFromPool($event->toolCallMessage) as $tool) {
                $resources->tools->add($tool);
            }
        }

        if (! isset($state->request)) {
            return;
        }

        if ($event instanceof AIInferenceEvent && ! $state->request->instructions->contains($this->systemPrompt)) {
            $state->request->instructions->addContent(new SystemContent($this->systemPrompt));
        }

        foreach ($this->discoverFromMessages($turn) as $tool) {
            $resources->tools->add($tool);
        }
    }

    /**
     * A miss names every pooled tool, so the model may call one by name without searching. That is a
     * correct call, not a hallucination: load the tool for this node instead of refusing it.
     *
     * @return list<ToolInterface>
     */
    private function calledFromPool(ToolCallMessage $message): array
    {
        $loaded = [];
        foreach ($message->getToolCalls() as $call) {
            if (isset($this->poolByName[$call->getName()])) {
                $loaded[] = $this->poolByName[$call->getName()];
            }
        }

        return $loaded;
    }

    /**
     * Distinct call ids: the ToolNode leaves the latest result on the request while the history
     * already holds it, so the same call can appear twice in the turn.
     *
     * @param Message[] $turn
     */
    private function searchesIn(array $turn): int
    {
        $ids = [];
        foreach ($turn as $message) {
            if (! $message instanceof ToolResultMessage) {
                continue;
            }

            foreach ($message->getToolCalls() as $call) {
                if ($call->getName() === 'tool_search') {
                    $ids[$call->getCallId() ?? spl_object_id($call)] = true;
                }
            }
        }

        return count($ids);
    }
}
