<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use NeuronAI\Tools\PropertyType as ToolsPropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Lead Intent')]
class LeadIntentTool extends Tool
{
    use ResolvesLeadForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'get_lead_intent',
            description: 'Determine the lead intent and completion status based on source and subsource mapping from ADF sources configuration.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'lead_id',
                type: ToolsPropertyType::INTEGER,
                description: 'The ID of the lead provided in the conversation context.',
                required: true,
            ),
        ];
    }

    public function __invoke(int $lead_id): array
    {
        $result = $this->resolveLeadOrError($lead_id);
        if (is_array($result)) {
            return $result;
        }
        $lead = $result;

        $sources = $lead->company->get('adf_sources') ?? [];
        if (empty($sources)) {
            return [
                'lead_intent' => 'ADVANCED_REQUEST',
                'intent_completion_status' => 'Incomplete',
            ];
        }

        $sources = collect($sources);
        $leadSource = $lead->source?->name;
        $subSource = $lead->get('sub_source');

        if ($leadSource === null) {
            return [
                'lead_intent' => 'ADVANCED_REQUEST',
                'intent_completion_status' => 'Incomplete',
            ];
        }

        // @todo standardize source and subsource names to lowercase to avoid issues like this
        if ($lead->get('VIN_SOLUTION_LEADS')) {
            $leadSource = $lead->type->name;
            $subSource = $lead->source->name;
        }

        $source = $sources->where('Source', $leadSource)
           ->where('Sub_Source', $subSource)
           ->first();

        if (! $source) {
            $source = $sources->where('is_default', true)->first() ?? [
                'Backend' => 'ADVANCED_REQUEST',
                'Default_Completion_Status' => 'Incomplete',
            ];
        }

        return [
            'lead_intent' => $source['Backend'],
            'intent_completion_status' => $source['Default_Completion_Status'],
        ];
    }
}
