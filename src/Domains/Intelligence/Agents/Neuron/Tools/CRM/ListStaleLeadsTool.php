<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Illuminate\Support\Carbon;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Lists OPEN leads (status < 2) with little recent movement so the agent can propose who to
 * follow up on. "Movement" is proxied by leads.updated_at — the last time the lead record itself
 * changed (edit, disposition, stage move). The last follow-up touch and its count are surfaced per
 * lead so the agent can tell "no update in 12 days AND we never followed up" from "old update but
 * nudged yesterday". Company-wide by design — an internal-teammate capability, not for the
 * customer-facing prospect surface (see Agents/CLAUDE.md audience rule).
 */
#[AgentTool(name: 'List Stale Leads', category: 'crm')]
class ListStaleLeadsTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'list_stale_leads',
            description: 'Lists open leads with little recent movement (no record update in the last N days) so you '
                . 'can summarize who needs a follow-up. Returns each lead\'s contact, owner, pipeline stage, days '
                . 'since last update, follow-up count and last follow-up date, and whether it was handed off to a '
                . 'human. Use this for "which leads are cold", "who should I follow up with", or "open leads with '
                . 'no movement". Filter by owner name/email to scope to one rep\'s pipeline.',
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
                name: 'idle_days',
                type: PropertyType::INTEGER,
                description: 'Only include leads with no update in at least this many days. Defaults to 7.',
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
                description: 'Max leads to return, stalest first. Defaults to 25, max 100.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?int $idle_days = null, ?string $owner = null, ?int $limit = null): array
    {
        $idleDays = max(0, $idle_days ?? 7);
        $limit = max(1, min(100, $limit ?? 25));
        $cutoff = Carbon::now()->subDays($idleDays);

        $leads = Lead::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '<', 2))
            ->where('updated_at', '<=', $cutoff)
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
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        $now = Carbon::now();

        return [
            'count' => $leads->count(),
            'idle_days_threshold' => $idleDays,
            'leads' => $leads->map(function (Lead $lead) use ($now): array {
                $lastFollowUp = $lead->getFollowUpLastAt();

                return [
                    'lead_id' => $lead->getId(),
                    'title' => $lead->title,
                    'contact' => $lead->people?->getName(),
                    'owner' => $lead->owner
                        ? trim($lead->owner->firstname . ' ' . $lead->owner->lastname)
                        : null,
                    'stage' => $lead->stage?->name,
                    'last_updated' => $lead->updated_at?->toDateString(),
                    'days_since_last_update' => $lead->updated_at
                        ? (int) $lead->updated_at->diffInDays($now)
                        : null,
                    'follow_up_count' => $lead->getFollowUpStateCount(),
                    'last_follow_up_at' => $lastFollowUp?->toIso8601String(),
                    'follow_up_exhausted' => $lead->isFollowUpExhausted(),
                    'is_handed_off' => (bool) ($lead->get(ConfigurationEnum::AGENT_HAND_OFF->value) ?? false),
                ];
            })->all(),
        ];
    }
}
