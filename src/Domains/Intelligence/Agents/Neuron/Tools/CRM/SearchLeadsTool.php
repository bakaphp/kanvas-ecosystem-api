<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Leads\Models\Lead;
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
 * Free-text lead lookup so the agent can find leads itself instead of asking for a lead_id. Matches
 * the query against the lead title, the contact's name, and their email/phone contacts. Company-wide
 * by design — an internal-teammate capability, NOT for the customer-facing prospect surface (a
 * prospect must never be able to search other prospects; see Agents/CLAUDE.md audience rule).
 */
#[AgentTool(name: 'Search Leads', category: 'crm')]
class SearchLeadsTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'search_leads',
            description: 'Find leads for ONE contact by (partial) name, email, phone, or lead title. Use this whenever '
                . 'you need to locate a lead but do not have its lead_id — e.g. "find the lead for Ana", "which lead '
                . 'has this email", "look up leads for Acme". Returns lead_id, contact, owner, stage and status so you '
                . 'can act on the right one. Filter by status (open/closed/all) and by owner name/email. '
                . 'For MORE THAN ONE name — a spreadsheet column, a CSV, any list — use find_leads_bulk instead and '
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
                description: 'The text to search for: a contact name, email, phone number, or lead title.',
                required: true,
            ),
            new ToolProperty(
                name: 'status',
                type: PropertyType::STRING,
                description: 'Which leads to include: "open" (default), "closed", or "all".',
                required: false,
            ),
            new ToolProperty(
                name: 'owner',
                type: PropertyType::STRING,
                description: 'Filter to a lead owner (sales rep) by partial name or email. Omit for all owners.',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Max leads to return, most recently updated first. Defaults to 25, max 100.',
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
            return ['count' => 0, 'leads' => [], 'error' => 'Provide a name, email, phone, or lead title to search for.'];
        }

        $status = strtolower($status ?? 'open');
        $limit = max(1, min(100, $limit ?? 25));
        $like = '%' . $query . '%';

        $leads = Lead::query()
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

                $q->whereIn('leads_owner_id', $ownerIds);
            })
            ->with(['owner', 'people', 'stage'])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        return [
            'count' => $leads->count(),
            'leads' => $leads->map(fn (Lead $lead): array => [
                'lead_id' => $lead->getId(),
                'title' => $lead->title,
                'contact' => $lead->people?->getName(),
                'owner' => $lead->owner
                    ? trim($lead->owner->firstname . ' ' . $lead->owner->lastname)
                    : null,
                'stage' => $lead->stage?->name,
                'is_open' => $lead->isOpen(),
                'last_updated' => $lead->updated_at?->toDateString(),
            ])->all(),
        ];
    }
}
