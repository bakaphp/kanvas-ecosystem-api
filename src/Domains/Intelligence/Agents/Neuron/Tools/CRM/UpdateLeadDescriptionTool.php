<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Writes the lead's `description` field (the "Description" box on the lead profile). Surgical — it
 * touches ONLY that column and saves, so it never blanks other fields the way a full-record update
 * can. Supports append so an existing description (e.g. prior notes) is preserved instead of clobbered.
 * Company-wide write — an internal-teammate capability, NOT for the customer-facing prospect surface.
 */
#[AgentTool(name: 'Update Lead Description', category: 'crm')]
class UpdateLeadDescriptionTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'update_lead_description',
            description: 'Set the Description field on a lead\'s profile (the main "Description" box, not an Activity '
                . 'note). Use this when asked to record something on the lead itself — e.g. a DNC / Do-Not-Contact '
                . 'note, a status remark, or context the whole team should see on the record. Pass mode="append" to '
                . 'add to the existing description instead of replacing it (use append when you don\'t want to lose '
                . 'what is already there). Use search_leads to get the lead_id first.',
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
                description: 'The ID of the lead to update (from search_leads or get_lead_ref).',
                required: true,
            ),
            new ToolProperty(
                name: 'description',
                type: PropertyType::STRING,
                description: 'The text to write into the lead\'s Description field.',
                required: true,
            ),
            new ToolProperty(
                name: 'mode',
                type: PropertyType::STRING,
                description: '"replace" (default) overwrites the Description; "append" adds to what is already there '
                    . 'on a new line.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $lead_id, string $description, ?string $mode = null): array
    {
        $description = trim($description);
        if ($description === '') {
            return ['error' => 'Provide the description text to write.'];
        }

        $mode = strtolower($mode ?? 'replace');
        if (! in_array($mode, ['replace', 'append'], true)) {
            return ['error' => 'mode must be "replace" or "append".'];
        }

        try {
            /** @var Lead $lead */
            $lead = Lead::getByIdFromCompanyApp($lead_id, $this->company, $this->app);
        } catch (Throwable) {
            return ['error' => sprintf('Lead #%d not found in this company.', $lead_id)];
        }

        $previous = (string) ($lead->description ?? '');

        $lead->description = $mode === 'append' && $previous !== ''
            ? $previous . "\n" . $description
            : $description;
        $lead->saveOrFail();

        return [
            'lead_id' => $lead->getId(),
            'title' => $lead->title,
            'mode' => $mode,
            'previous_description' => $previous !== '' ? $previous : null,
            'description' => $lead->description,
            'message' => 'Lead description updated.',
        ];
    }
}
