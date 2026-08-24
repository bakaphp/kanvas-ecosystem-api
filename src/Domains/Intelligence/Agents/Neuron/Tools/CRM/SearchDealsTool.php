<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Free-text deal lookup so the agent can find deals itself instead of asking for a deal_id. Matches
 * the query against the deal title, the contact's name, and their email/phone contacts. Company-wide
 * by design — an internal-teammate capability, NOT for the customer-facing prospect surface (a
 * prospect must never be able to search other prospects' deals; see Agents/CLAUDE.md audience rule).
 */
#[AgentTool(name: 'Search Deals', category: 'crm')]
class SearchDealsTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'search_deals',
            description: 'Find deals for ONE contact by (partial) name, email, phone, or deal title. Use this whenever '
                . 'you need to locate a deal but do not have its deal_id — e.g. "find the deal for Ana", "which deal '
                . 'is this email on", "look up deals for Acme". Returns deal_id, title, contact, owner, pipeline stage '
                . 'and status so you can act on the right one. Filter by status (open/closed/all) and by owner. '
                . 'For MORE THAN ONE name — a spreadsheet column, a CSV, any list — use find_deals_bulk instead and '
                . 'pass every name in a single call; do not call this tool once per row.',
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
                name: 'query',
                type: PropertyType::STRING,
                description: 'The text to search for: a contact name, email, phone number, or deal title.',
                required: true,
            ),
            new ToolProperty(
                name: 'status',
                type: PropertyType::STRING,
                description: 'Which deals to include: "open" (default), "closed", or "all".',
                required: false,
            ),
            new ToolProperty(
                name: 'owner',
                type: PropertyType::STRING,
                description: 'Filter to a deal owner (sales rep) by partial name or email. Omit for all owners.',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Max deals to return, most recently updated first. Defaults to 25, max 100.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $query,
        ?string $status = null,
        ?string $owner = null,
        ?int $limit = null,
    ): array {
        $query = trim($query);
        if ($query === '') {
            return ['count' => 0, 'deals' => [], 'error' => 'Provide a name, email, phone, or deal title to search for.'];
        }

        $status = strtolower($status ?? 'open');
        $limit = max(1, min(100, $limit ?? 25));
        $like = '%' . $query . '%';

        $deals = Deal::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->when($status === 'open', fn ($q) => $q->where(
                fn ($s) => $s->whereNull('status')->orWhere('status', '<', 2),
            ))
            ->when($status === 'closed', fn ($q) => $q->where('status', '>=', 2))
            ->where(function ($q) use ($like): void {
                $q->where('title', 'like', $like)
                    ->orWhereHas(
                        'people',
                        fn ($p) => $p->where('firstname', 'like', $like)
                            ->orWhere('lastname', 'like', $like)
                            ->orWhereHas('contacts', fn ($c) => $c->where('value', 'like', $like)),
                    );
            })
            ->when($owner !== null && $owner !== '', function ($q) use ($owner): void {
                // owner is a Users relation on the `ecosystem` connection — resolve ids first
                // instead of whereHas(), which would try to join `users` on the `crm` connection.
                $ownerIds = Users::query()
                    ->where(
                        fn ($o) => $o->where('firstname', 'like', '%' . $owner . '%')
                            ->orWhere('lastname', 'like', '%' . $owner . '%')
                            ->orWhere('email', 'like', '%' . $owner . '%'),
                    )
                    ->pluck('id');

                $q->whereIn('owner_id', $ownerIds);
            })
            ->with(['owner', 'people', 'pipelineStage'])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        return [
            'count' => $deals->count(),
            'deals' => $deals->map(fn (Deal $deal): array => [
                'deal_id' => $deal->getId(),
                'title' => $deal->title,
                'contact' => $deal->people?->getName(),
                'owner' => $deal->owner
                    ? trim($deal->owner->firstname . ' ' . $deal->owner->lastname)
                    : null,
                'stage' => $deal->pipelineStage?->name,
                'is_open' => $deal->status === null || $deal->status < 2,
                'last_updated' => $deal->updated_at?->toDateString(),
            ])->all(),
        ];
    }
}
