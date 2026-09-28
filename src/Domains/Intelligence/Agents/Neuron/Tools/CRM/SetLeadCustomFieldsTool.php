<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\DecodesJsonObjectParam;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Writes and removes arbitrary custom fields on a lead — the lead-side counterpart of
 * set_person_custom_fields. update_lead only knows the five hardcoded qualification answers, so anything
 * a tenant configured for itself is unreachable without this. Non-destructive: only the keys you pass
 * are touched.
 */
#[AgentTool(name: 'Set Lead Custom Fields', category: 'crm')]
class SetLeadCustomFieldsTool extends Tool implements HasRunKey
{
    use DecodesJsonObjectParam;
    use ResolvesLeadForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'set_lead_custom_fields',
            description: 'Store or remove custom fields on a lead — anything structured worth keeping that is not '
                . 'one of the standard fields (a score, a classification, an external reference, a tenant-specific '
                . 'attribute). Pass custom_fields as a map of field name → value to write, and/or remove as a '
                . 'comma-separated list of field names to delete. Only the keys you name are touched; everything '
                . 'else on the lead is left alone. For the prospect\'s contact details, title, organization, type, '
                . 'source or qualification answers use update_lead instead.',
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
                description: 'The ID of the lead to write to.',
                required: true,
            ),
            new ToolProperty(
                name: 'custom_fields',
                type: PropertyType::STRING,
                description: 'A JSON object mapping custom field name → value, passed as a string. '
                    . 'For example: {"lead_score": "82", "competitor": "Salesforce"}.',
                required: false,
            ),
            new ToolProperty(
                name: 'remove',
                type: PropertyType::STRING,
                description: 'Comma-separated field names to delete from the lead, e.g. "competitor, trade_in". '
                    . 'Names that are not set on the lead are reported back as not_found, not as an error.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $lead_id,
        array|string|null $custom_fields = null,
        ?string $remove = null,
    ): array {
        $custom_fields = $this->decodeJsonObjectParam($custom_fields);
        $toRemove = $this->parseFieldNames($remove);

        if ($custom_fields === [] && $toRemove === []) {
            return [
                'status' => 'error',
                'message' => 'Provide custom_fields to write, remove to delete, or both.',
            ];
        }

        $result = $this->resolveLeadOrError($lead_id);
        if (is_array($result)) {
            return $result;
        }
        $lead = $result;

        $written = [];
        foreach ($custom_fields as $name => $value) {
            $key = trim((string) $name);
            if ($key === '') {
                continue;
            }

            $lead->set($key, $value);
            $written[$key] = $value;
        }

        $removed = [];
        $notFound = [];
        foreach ($toRemove as $key) {
            // del() returns true whether or not the field existed, so ask first — otherwise the tool
            // reports a removal the agent will repeat back to the user as a change that happened.
            if ($lead->getCustomField($key) === null) {
                $notFound[] = $key;

                continue;
            }

            $lead->del($key);
            $removed[] = $key;
        }

        return [
            'status' => 'success',
            'lead_id' => $lead->getId(),
            'set' => $written,
            'removed' => $removed,
            'not_found' => $notFound,
            'message' => count($written) . ' custom field(s) set, ' . count($removed) . ' removed.',
        ];
    }

    /**
     * @return list<string>
     */
    private function parseFieldNames(?string $names): array
    {
        if ($names === null || trim($names) === '') {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('trim', explode(',', $names)),
            fn (string $name): bool => $name !== '',
        )));
    }
}
