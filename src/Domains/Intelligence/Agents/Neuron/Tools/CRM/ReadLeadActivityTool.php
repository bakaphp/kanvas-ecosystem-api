<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ReadsActivityForEntity;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Read Lead Activity', category: 'crm')]
class ReadLeadActivityTool extends Tool implements HasRunKey
{
    use ReadsActivityForEntity;
    use ResolvesLeadForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'read_lead_activity',
            description: 'Read a lead\'s Activity thread — the notes, logged calls, emails, SMS and stage/system events '
                . 'the team sees in the lead\'s Activity tab, newest first. Use it before summarizing a lead, answering '
                . '"what happened with this lead", or deciding a next step: progress that only lives in the activity '
                . 'is not in the lead\'s description.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'lead_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the lead whose activity to read.',
                required: true,
            ),
            ...$this->paginationProperties(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $lead_id, ?int $limit = null, ?int $before_id = null): array
    {
        $refusal = $this->customerSurfaceRefusal();
        if ($refusal !== null) {
            return $refusal;
        }

        $lead = $this->resolveLeadOrError($lead_id);
        if (is_array($lead)) {
            return $lead;
        }

        return $this->readActivity(
            $lead,
            'lead_id',
            (string) $lead->title,
            $limit,
            $before_id
        );
    }
}
