<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use NeuronAI\Tools\PropertyType as ToolsPropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Artifacts')]
class ArtifactsTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'get_lead_artifacts',
            description: 'Retrieve CRM artifacts and vehicle information for the current lead, including status, stage, tags, forms, records, and ADF comments.',
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
        $lead = Lead::getById($lead_id);

        $adf = $lead->get(LeadCustomFieldEnum::ADF_LEAD_XML->value);
        $comment = $adf ? ($adf['adf']['prospect']['customer']['comments'] ?? []) : [];

        return [
            'crm' => [
                'status' => $lead->status()->first()?->name,
                'stage' => $lead->stage->name,
                'tags' => $lead->tags->pluck('name')->toArray(),
                'last_notes' => [],
                'forms' => [
                    'prequal' => null,
                    'credit_app' => null,
                    'trade_appraisal' => null,
                    'payment_calc' => null,
                ],
                'records' => [
                    'appointment' => null,
                    'quote' => null,
                    'appraisal' => null,
                    'delivery' => null,
                ],
            ],
            'transcript_snippet' => null,
            'comments' => $comment,
        ];
    }
}
