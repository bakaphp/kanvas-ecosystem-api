<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Override;

#[AgentTool(name: 'Find Deals Bulk', category: 'crm')]
class FindDealsBulkTool extends FindCrmRecordsBulkTool
{
    public function __construct()
    {
        parent::__construct(
            name: 'find_deals_bulk',
            description: 'Look up MANY deals at once by contact name or deal title. ALWAYS use this instead of '
                . 'calling search_deals once per name whenever you have more than one to check — cross-referencing a '
                . 'spreadsheet/CSV column, "which of these accounts already have a deal", reviewing a target list. '
                . 'Pass every name in one call, separated by commas or new lines. Returns one row per name, in the '
                . 'order given, each with found=true/false and any matching deal_id, title, contact, owner and stage. '
                . 'Searches deals in ANY status by default so an existing closed deal still counts as found. '
                . 'For a single lookup, or to filter by owner, use search_deals.',
        );
    }

    #[Override]
    protected function noun(): string
    {
        return 'deal';
    }

    #[Override]
    protected function baseQuery(): Builder
    {
        return Deal::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->with(['owner', 'people', 'pipelineStage']);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function present(Model $record, int $score): array
    {
        /** @var Deal $record */
        return [
            'deal_id' => $record->getId(),
            'title' => $record->title,
            'contact' => $record->people?->getName(),
            'owner' => $this->ownerName($record),
            'stage' => $record->pipelineStage?->name,
            'is_open' => $this->isOpen($record),
            'last_updated' => $record->updated_at?->toDateString(),
            'matched_tokens' => $score,
        ];
    }
}
