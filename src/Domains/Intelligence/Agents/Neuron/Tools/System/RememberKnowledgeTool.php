<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\System;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

#[AgentTool(name: 'Remember Knowledge', category: 'ecosystem')]
class RememberKnowledgeTool extends Tool
{
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly Agent $agent,
    ) {
        parent::__construct(
            name: 'remember',
            description: 'Save a durable fact, decision, or pattern to your long-term memory so you recall it in '
                . 'future conversations. Use it for things worth keeping — a company preference, a recurring risk, a '
                . 'decision and its rationale — NOT for one-off chit-chat. You recall saved knowledge later via '
                . 'read_my_ledger.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'title', type: PropertyType::STRING, description: 'A short headline for the memory (e.g. "Acme prefers quarterly invoicing").', required: true),
            new ToolProperty(name: 'content', type: PropertyType::STRING, description: 'The fact/decision/insight in full, with enough context to be useful months later.', required: true),
            new ToolProperty(name: 'tags', type: PropertyType::STRING, description: 'Optional comma-separated tags for retrieval (e.g. "billing,acme,risk").', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $title, string $content, ?string $tags = null): array
    {
        $title = trim($title);
        $content = trim($content);

        if ($title === '' || $content === '') {
            return [
                'status' => 'error',
                'message' => 'Both title and content are required to save a memory. Nothing was saved.',
            ];
        }

        $tagList = array_values(array_filter(array_map(
            'trim',
            explode(',', $tags ?? ''),
        )));

        try {
            new AppendEventAction(new EventData(
                app: $this->app,
                company: $this->company,
                sourceDomain: 'NervousSystem.Memory',
                eventType: 'agent.knowledge.saved',
                status: EventStatusEnum::INFO,
                actorType: 'Agent',
                actorId: $this->agent->getId(),
                payload: [
                    'title' => $title,
                    'content' => $content,
                    'tags' => $tagList,
                ],
            ))->execute();
        } catch (Throwable $e) {
            report($e);

            return [
                'status' => 'error',
                'message' => 'Could not save that memory right now. Do not retry automatically.',
            ];
        }

        return [
            'status' => 'success',
            'message' => 'Saved to long-term memory.',
            'title' => $title,
            'tags' => $tagList,
        ];
    }
}
