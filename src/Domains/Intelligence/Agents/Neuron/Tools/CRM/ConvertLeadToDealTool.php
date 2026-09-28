<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Baka\Support\Str;
use Kanvas\Guild\Deals\Actions\ConvertLeadToDealAction;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Promote a qualified lead into a deal (pipeline opportunity). The new deal inherits the lead's
 * contact, organization, branch and owner, and stays linked back to the lead. Use this once a lead
 * shows real buying intent — do NOT hand-create a deal and re-type the contact; this copies it over.
 */
#[AgentTool(name: 'Convert Lead to Deal', category: 'crm')]
class ConvertLeadToDealTool extends Tool
{
    use ResolvesLeadForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'convert_lead_to_deal',
            description: 'Turn an existing qualified lead into a deal (pipeline opportunity). Pass the lead_id; the '
                . 'deal inherits the lead\'s contact, organization and owner and links back to it. Optionally override '
                . 'the title/description. Use this when a lead is ready to become an opportunity — it returns the new '
                . 'deal_id for subsequent deal tools. Do not call it twice for the same lead.',
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
                description: 'The ID of the lead to convert into a deal.',
                required: true,
            ),
            new ToolProperty(
                name: 'title',
                type: PropertyType::STRING,
                description: 'Optional deal title. Defaults to the lead\'s title if omitted.',
                required: false,
            ),
            new ToolProperty(
                name: 'description',
                type: PropertyType::STRING,
                description: 'Optional deal description. Defaults to the lead\'s description if omitted.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $lead_id,
        ?string $title = null,
        ?string $description = null,
    ): array {
        $result = $this->resolveLeadOrError($lead_id);
        if (is_array($result)) {
            return $result;
        }
        $lead = $result;

        $existingDealId = (int) $lead->get(ConfigurationEnum::CONVERTED_TO_DEAL_ID->value);
        if ($existingDealId > 0) {
            try {
                $existingDeal = Deal::getById($existingDealId);

                return [
                    'status' => 'noop',
                    'lead_id' => $lead_id,
                    'deal_id' => $existingDeal->getId(),
                    'message' => "Lead {$lead_id} was already converted to deal {$existingDeal->getId()} "
                        . "('{$existingDeal->title}'). Use that deal_id instead of converting again.",
                ];
            } catch (Throwable) {
                // Stale pointer (deal was deleted) — fall through and convert afresh.
            }
        }

        try {
            $deal = new ConvertLeadToDealAction(
                lead: $lead,
                title: Str::trimToNull((string) $title),
                description: Str::trimToNull((string) $description),
            )->execute();
        } catch (Throwable $e) {
            report($e);

            return [
                'status' => 'error',
                'message' => "Failed to convert lead {$lead_id} to a deal: {$e->getMessage()}",
            ];
        }

        return [
            'status' => 'success',
            'lead_id' => $lead_id,
            'deal_id' => $deal->getId(),
            'title' => $deal->title,
            'message' => "Lead {$lead_id} converted to deal '{$deal->title}' (deal_id {$deal->getId()}).",
        ];
    }
}
