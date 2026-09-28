<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Override;

#[AgentTool(name: 'Find Leads Bulk', category: 'crm')]
class FindLeadsBulkTool extends FindCrmRecordsBulkTool
{
    public function __construct()
    {
        parent::__construct(
            name: 'find_leads_bulk',
            description: 'Look up MANY leads at once by contact name or lead title. ALWAYS use this instead of '
                . 'calling search_leads once per name whenever you have more than one to check — cross-referencing a '
                . 'spreadsheet/CSV column, "which of these people already have a lead", qualifying an imported list. '
                . 'Pass every name in one call, separated by commas or new lines. Returns one row per name, in the '
                . 'order given, each with found=true/false and any matching lead_id, title, contact, owner and stage. '
                . 'Searches leads in ANY status by default so an existing closed lead still counts as found. '
                . 'For a single lookup, or to filter by owner, use search_leads.',
        );
    }

    #[Override]
    protected function noun(): string
    {
        return 'lead';
    }

    #[Override]
    protected function baseQuery(): Builder
    {
        return Lead::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->with(['owner', 'people', 'stage']);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function present(Model $record, int $score): array
    {
        /** @var Lead $record */
        return [
            'lead_id' => $record->getId(),
            'title' => $record->title,
            'contact' => $record->people?->getName(),
            'owner' => $this->ownerName($record),
            'stage' => $record->stage?->name,
            'is_open' => $record->isOpen(),
            'last_updated' => $record->updated_at?->toDateString(),
            'matched_tokens' => $score,
        ];
    }
}
