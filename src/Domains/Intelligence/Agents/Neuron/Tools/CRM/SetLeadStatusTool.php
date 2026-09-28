<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Illuminate\Support\Collection;
use Kanvas\Guild\Leads\Actions\RecordLeadNoteAction;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Writes leads_status_id — the status the CRM UI reads. Status names are per-tenant free text
 * (leads_status rows are seeded globally as Active/Inactive but each company adds its own), so the
 * tool cannot enumerate them in its description; an unknown name comes back with the tenant's real
 * list attached so the model can retry in the same turn instead of inventing one.
 */
#[AgentTool(name: 'Set Lead Status', category: 'crm')]
class SetLeadStatusTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use ResolvesLeadForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'set_lead_status',
            description: 'Change a lead\'s status — this is what closes a lead out (closed, lost, won, inactive) '
                . 'or reopens it, and it is what shows up in the lead UI and filters the active pipeline. '
                . 'Use this whenever you are asked to close, cancel, archive, mark cold/lost/won, or reopen a lead. '
                . 'Use search_leads to get the lead_id first. '
                . 'Status names are configured per company — pass your best guess and, if it is not a real status, '
                . 'the tool returns available_statuses so you can retry with a valid one in the SAME turn. '
                . 'Never tell the user a lead was closed unless this tool returned status "success".',
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
                description: 'The ID of the lead to change (from search_leads or get_lead_ref).',
                required: true,
            ),
            new ToolProperty(
                name: 'status',
                type: PropertyType::STRING,
                description: 'The status name to set, e.g. "Closed", "Lost", "Won", "Inactive". Case-insensitive. '
                    . 'If the name is not configured for this company the tool returns the valid options.',
                required: true,
            ),
            new ToolProperty(
                name: 'reason',
                type: PropertyType::STRING,
                description: 'Why the status is changing. Saved on the lead and recorded as a note on the '
                    . 'activity thread so the team can see who closed it and why.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $lead_id, string $status, ?string $reason = null): array
    {
        $status = trim($status);

        if ($status === '') {
            return [
                'status' => 'error',
                'message' => 'Provide the status name to set.',
                'available_statuses' => $this->availableStatusNames(),
            ];
        }

        $result = $this->resolveLeadOrError($lead_id);
        if (is_array($result)) {
            return $result;
        }
        $lead = $result;

        $target = $this->availableStatuses()
            ->first(fn (LeadStatus $leadStatus): bool => strcasecmp($leadStatus->name, $status) === 0);

        if ($target === null) {
            return [
                'status' => 'error',
                'lead_id' => $lead->getId(),
                'message' => sprintf(
                    '"%s" is not a status configured for this company. Pick one of available_statuses and call '
                    . 'set_lead_status again in this same turn — do not invent a status name.',
                    $status,
                ),
                'available_statuses' => $this->availableStatusNames(),
            ];
        }

        // `status` is both a column and a relation on Lead — read through the relation explicitly or
        // the column's int value comes back instead of the LeadStatus row.
        $previous = $lead->status()->first()?->name;

        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;

        $lead->leads_status_id = $target->getId();
        if ($reason !== null) {
            $lead->reason_lost = $reason;
        }
        $lead->saveOrFail();

        $noteRecorded = new RecordLeadNoteAction($lead)->execute(
            $this->changeNote($previous, $target->name, $reason),
            'status-change',
            $this->contextUser(),
        ) !== null;

        return [
            'status' => 'success',
            'lead_id' => $lead->getId(),
            'previous_status' => $previous,
            'new_status' => $target->name,
            'reason' => $reason,
            'note_recorded' => $noteRecorded,
            'message' => sprintf('Lead %d is now "%s".', $lead->getId(), $target->name),
        ];
    }

    private function changeNote(?string $previous, string $new, ?string $reason): string
    {
        return 'Status changed'
            . ($previous !== null ? ' from "' . $previous . '"' : '')
            . ' to "' . $new . '"'
            . ($reason !== null ? '. Reason: ' . $reason : '.');
    }

    /**
     * @return Collection<int, LeadStatus>
     */
    private function availableStatuses(): Collection
    {
        return LeadStatus::query()
            ->notDeleted()
            ->fromPublicOrCurrentApp($this->app)
            ->fromCompanyAndGlobal($this->company)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<int, string>
     */
    private function availableStatusNames(): array
    {
        return $this->availableStatuses()
            ->map(fn (LeadStatus $leadStatus): string => $leadStatus->name)
            ->unique()
            ->values()
            ->all();
    }
}
