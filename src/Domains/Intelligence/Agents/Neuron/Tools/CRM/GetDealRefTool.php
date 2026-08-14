<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesDealForTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Deal Reference', category: 'crm')]
class GetDealRefTool extends Tool
{
    use HasKanvasContext;
    use ResolvesDealForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'get_deal_ref',
            description: 'Get the full detail of a deal by its deal_id: title, description, contact person, '
                . 'organization, owner, pipeline + stage, status, notes and the lead it came from. Use this to '
                . 'load a deal\'s context before acting on it.',
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
                name: 'deal_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the deal to look up.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $deal_id): array
    {
        $result = $this->resolveDealOrError($deal_id);
        if (is_array($result)) {
            return $result;
        }
        $deal = $result;

        $people = $deal->people;

        return [
            'deal_id' => $deal->getId(),
            'uuid' => $deal->uuid,
            'title' => $deal->title,
            'description' => $deal->description,
            'status' => $deal->status,
            'is_open' => $deal->status === null || $deal->status < 2,
            'lead_status' => $deal->leadStatus?->name,
            'pipeline' => $deal->pipeline?->name,
            'stage' => $deal->pipelineStage?->name,
            'notes' => $deal->get('deal_notes'),
            'from_lead_id' => $deal->leads_id,
            'owner' => $deal->owner ? [
                'id' => $deal->owner->getId(),
                'name' => trim($deal->owner->firstname . ' ' . $deal->owner->lastname),
                'email' => $deal->owner->email,
            ] : null,
            'people' => $people ? [
                'id' => $people->getId(),
                'name' => $people->getName(),
                'contacts' => $people->contacts()->with('type')->get()->map(fn ($contact) => [
                    'type' => $contact->type?->name,
                    'value' => $contact->value,
                    'is_opt_out' => (bool) $contact->is_opt_out,
                ])->toArray(),
            ] : null,
            'organization' => $deal->organization ? [
                'id' => $deal->organization->getId(),
                'name' => $deal->organization->name,
            ] : null,
        ];
    }
}
