<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Illuminate\Support\Carbon;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\BatchRecipientResolverService;
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
 * Finds leads that share traits and returns a vetted batch-outreach recipient list — the first step
 * of the manager batch-messaging flow. Read-only: it NEVER sends anything. It resolves who is
 * eligible to be contacted (dedups people, drops do-not-contact and undeliverable/opted-out) and
 * reports why each excluded lead was dropped, so the manager can review before confirming a send.
 *
 * Company-wide, internal-teammate capability (managers), not the customer-facing surface. v1 filters
 * the reliable structured traits only (source, status, stage, salesperson, rooftop, dates,
 * staleness); vehicle make/model/price filtering is a planned follow-up.
 */
#[AgentTool(name: 'Find Leads By Traits', category: 'crm')]
class FindLeadsByTraitsTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct(
        private readonly BatchRecipientResolverService $resolver = new BatchRecipientResolverService(),
    ) {
        parent::__construct(
            name: 'find_leads_by_traits',
            description: 'Find a group of leads that share traits and build a reviewable batch-outreach recipient '
                . 'list. Use for "find leads from the last 14 days with no appointment set", "show me open leads for '
                . 'rep Ana at the downtown store", "leads with no activity in 7 days". Filters: status, source, stage, '
                . 'salesperson, rooftop/store, created date range, and days-since-last-update. Returns the eligible '
                . 'recipients plus the leads that were excluded (opted-out, do-not-contact, no contact info, duplicate) '
                . 'with reasons. THIS DOES NOT SEND ANYTHING — it is the review step. After the manager confirms the '
                . 'list and the message, call send_batch_message with the eligible lead_ids.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'status', type: PropertyType::STRING, description: 'open (default), closed, or all.', required: false),
            new ToolProperty(name: 'source', type: PropertyType::STRING, description: 'Lead source name (partial match), e.g. "internet", "walk-in".', required: false),
            new ToolProperty(name: 'stage', type: PropertyType::STRING, description: 'Pipeline stage name (partial match).', required: false),
            new ToolProperty(name: 'salesperson', type: PropertyType::STRING, description: 'Assigned salesperson by partial name or email.', required: false),
            new ToolProperty(name: 'rooftop', type: PropertyType::STRING, description: 'Store / rooftop (branch) name, partial match.', required: false),
            new ToolProperty(name: 'created_after', type: PropertyType::STRING, description: 'Only leads created on/after this date (YYYY-MM-DD).', required: false),
            new ToolProperty(name: 'created_before', type: PropertyType::STRING, description: 'Only leads created on/before this date (YYYY-MM-DD).', required: false),
            new ToolProperty(name: 'no_update_since_days', type: PropertyType::INTEGER, description: 'Only leads with no record activity in at least this many days (proxy for "no response since").', required: false),
            new ToolProperty(name: 'channel', type: PropertyType::STRING, description: 'Channel the batch will use: sms (default) or email. Determines which contacts must be deliverable.', required: false),
            new ToolProperty(name: 'limit', type: PropertyType::INTEGER, description: 'Max candidate leads to evaluate, most recently updated first. Default 100, max 500.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $status = null,
        ?string $source = null,
        ?string $stage = null,
        ?string $salesperson = null,
        ?string $rooftop = null,
        ?string $created_after = null,
        ?string $created_before = null,
        ?int $no_update_since_days = null,
        ?string $channel = null,
        ?int $limit = null,
    ): array {
        $status = strtolower(trim((string) $status)) ?: 'open';
        $channel = strtolower(trim((string) $channel)) ?: 'sms';
        if (! in_array($channel, ['sms', 'email'], true)) {
            return ['status' => 'error', 'message' => 'Invalid channel. Use "sms" or "email".'];
        }
        $limit = max(1, min(500, $limit ?? 100));

        $query = Lead::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->when($status === 'open', fn ($q) => $q->where(fn ($s) => $s->whereNull('status')->orWhere('status', '<', 2)))
            ->when($status === 'closed', fn ($q) => $q->where('status', '>=', 2));

        $criteria = ['status' => $status, 'channel' => $channel];

        if (($source = trim((string) $source)) !== '') {
            $query->whereHas('source', fn ($q) => $q->where('name', 'like', "%{$source}%"));
            $criteria['source'] = $source;
        }

        if (($stage = trim((string) $stage)) !== '') {
            $query->whereHas('stage', fn ($q) => $q->where('name', 'like', "%{$stage}%"));
            $criteria['stage'] = $stage;
        }

        if (($salesperson = trim((string) $salesperson)) !== '') {
            // owner is a Users relation on the ecosystem connection — resolve ids first (whereHas would
            // cross-join a different DB), mirroring SearchLeadsTool.
            $ownerIds = Users::query()
                ->where(fn ($o) => $o->where('firstname', 'like', "%{$salesperson}%")
                    ->orWhere('lastname', 'like', "%{$salesperson}%")
                    ->orWhere('email', 'like', "%{$salesperson}%"))
                ->pluck('id');
            $query->whereIn('leads_owner_id', $ownerIds);
            $criteria['salesperson'] = $salesperson;
        }

        if (($rooftop = trim((string) $rooftop)) !== '') {
            $branchIds = CompaniesBranches::query()
                ->where('companies_id', $this->company->getId())
                ->where('name', 'like', "%{$rooftop}%")
                ->pluck('id');
            $query->whereIn('companies_branches_id', $branchIds);
            $criteria['rooftop'] = $rooftop;
        }

        if (($created_after = trim((string) $created_after)) !== '') {
            $query->where('created_at', '>=', Carbon::parse($created_after)->startOfDay());
            $criteria['created_after'] = $created_after;
        }

        if (($created_before = trim((string) $created_before)) !== '') {
            $query->where('created_at', '<=', Carbon::parse($created_before)->endOfDay());
            $criteria['created_before'] = $created_before;
        }

        if ($no_update_since_days !== null && $no_update_since_days > 0) {
            $query->where('updated_at', '<=', Carbon::now()->subDays($no_update_since_days));
            $criteria['no_update_since_days'] = $no_update_since_days;
        }

        $leads = $query
            ->with(['people', 'owner', 'stage'])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $resolved = $this->resolver->resolve($leads, $channel);

        $excludedReasons = [];
        foreach ($resolved['excluded'] as $row) {
            $reason = (string) $row['compliance_status'];
            $excludedReasons[$reason] = ($excludedReasons[$reason] ?? 0) + 1;
        }

        return [
            'status' => 'success',
            'interpreted_criteria' => $criteria,
            'summary' => [
                'candidates_evaluated' => $resolved['total_candidates'],
                'eligible' => $resolved['eligible_count'],
                'excluded' => $resolved['excluded_count'],
                'excluded_reasons' => $excludedReasons,
                'capped' => $leads->count() === $limit,
            ],
            'recipients' => $resolved['eligible'],
            'excluded_sample' => array_slice($resolved['excluded'], 0, 25),
            'note' => 'This is a REVIEW list — nothing has been sent. Show the manager the interpreted_criteria and '
                . 'the recipient count, let them remove anyone, then only on explicit confirmation call '
                . 'send_batch_message (or schedule_batch_message) with the confirmed eligible lead_ids. The send tool '
                . 're-checks eligibility, so already-excluded leads cannot be messaged even if passed.',
        ];
    }
}
