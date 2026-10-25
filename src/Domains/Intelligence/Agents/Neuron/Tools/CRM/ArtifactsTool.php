<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Guild\Leads\Enums\LeadMessageTypeEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\Tools\PropertyType as ToolsPropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Artifacts', category: 'crm')]
class ArtifactsTool extends Tool
{
    use ResolvesLeadForTool;

    protected string $name = 'get_lead_artifacts';

    protected ?string $description = 'Retrieve CRM artifacts and vehicle information for the current lead, including status, stage, tags, forms, records, internal lead notes (newest first, with author and date), and ADF comments. Consult recent notes for contact preferences before drafting a follow-up; notes are internal context, not customer messages.';

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

        $adf = $lead->get(LeadCustomFieldEnum::ADF_LEAD_XML->value);
        $comment = $adf ? ($adf['adf']['prospect']['customer']['comments'] ?? []) : [];

        return [
            'crm' => [
                'status' => $lead->status()->first()?->name,
                'stage' => $lead->stage->name,
                'tags' => $lead->tags->pluck('name')->toArray(),
                'last_notes' => $this->recentNotes($lead),
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

    private function recentNotes(Lead $lead): array
    {
        return Message::query()
            ->where('apps_id', $lead->apps_id)
            ->where('companies_id', $lead->companies_id)
            ->where('is_deleted', 0)
            ->whereHas('messageType', fn ($query) => $query->where('verb', LeadMessageTypeEnum::NOTES->value))
            ->whereHas('appModuleMessages', fn ($query) => $query
                ->where('apps_id', $lead->apps_id)
                ->where('companies_id', $lead->companies_id)
                ->where('system_modules', Lead::class)
                ->where('entity_id', $lead->getId()))
            ->with('user')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (Message $note): array {
                $data = $note->getMessage();

                return [
                    'id' => $note->getId(),
                    'content' => $data['content'] ?? $data['text'] ?? '',
                    'created_at' => $note->created_at?->toIso8601String(),
                    'author' => $note->user ? [
                        'id' => $note->user->getId(),
                        'name' => $note->user->displayname,
                    ] : null,
                ];
            })
            ->all();
    }
}
