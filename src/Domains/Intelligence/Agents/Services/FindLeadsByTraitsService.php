<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\BatchRecipientResolverService;
use Kanvas\Intelligence\Agents\Filters\CommunicationLeadFilter;
use Kanvas\Intelligence\Agents\Filters\EngagementLeadFilter;
use Kanvas\Intelligence\Agents\Filters\LeadBaseFilter;
use Kanvas\Intelligence\Agents\Filters\VariantInterestLeadFilter;

class FindLeadsByTraitsService
{
    public function __construct(
        private readonly LeadBaseFilter $baseFilter = new LeadBaseFilter(),
        private readonly VariantInterestLeadFilter $variantFilter = new VariantInterestLeadFilter(),
        private readonly EngagementLeadFilter $engagementFilter = new EngagementLeadFilter(),
        private readonly CommunicationLeadFilter $communicationFilter = new CommunicationLeadFilter(),
        private readonly BatchRecipientResolverService $recipientResolver = new BatchRecipientResolverService(),
    ) {
    }

    /** @return array<string, mixed> */
    public function execute(Apps $app, Companies $company, array $filters): array
    {
        $channel = strtolower(trim((string) ($filters['channel'] ?? ''))) ?: 'sms';
        if (! in_array($channel, ['sms', 'email'], true)) {
            return ['status' => 'error', 'message' => 'Invalid channel. Use "sms" or "email".'];
        }
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        $query = Lead::query()->fromApp($app)->fromCompany($company)->notDeleted();
        $criteria = $this->baseFilter->apply($query, $company, $filters) + ['channel' => $channel];

        try {
            [$engagementCriteria, $engagementAuthority] = $this->applyEngagement(
                $query,
                $app,
                $company,
                $filters
            );
        } catch (InvalidArgumentException $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }
        if ($engagementCriteria !== null) {
            $criteria['engagement'] = $engagementCriteria;
        }

        try {
            $communication = $this->communicationFilter->apply(
                $query,
                $app,
                $company,
                $filters
            );
        } catch (InvalidArgumentException $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }
        if ($communication['active']) {
            $criteria['communication'] = $communication['criteria'];
        }

        $variant = $this->variantFilter->apply(
            $query,
            $app,
            $company,
            $filters
        );
        if ($variant['active']) {
            $criteria['variant_interest'] = $variant['criteria'];
        }

        $leads = $query
            ->with([
                'people', 'owner', 'stage', 'variantInterests.variant.product',
                'variantInterests.variant.channels', 'variantInterests.variant.variantAttributes.attribute',
            ])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();
        $resolved = $this->recipientResolver->resolve($leads, $channel);
        $resolved['eligible'] = $this->variantFilter->attachMatches($leads, $resolved['eligible'], $variant);
        $resolved['excluded'] = $this->variantFilter->attachMatches($leads, $resolved['excluded'], $variant);
        $resolved['eligible'] = $this->communicationFilter->attachMatches($resolved['eligible'], $communication);
        $resolved['excluded'] = $this->communicationFilter->attachMatches($resolved['excluded'], $communication);

        return [
            'status' => 'success',
            'interpreted_criteria' => $criteria,
            'summary' => [
                'candidates_evaluated' => $resolved['total_candidates'],
                'eligible' => $resolved['eligible_count'],
                'excluded' => $resolved['excluded_count'],
                'excluded_reasons' => collect($resolved['excluded'])->countBy('compliance_status')->all(),
                'capped' => $leads->count() === $limit,
                'matching_inventory_variants' => count($variant['variants']),
                'matching_last_messages' => $communication['matching_messages'],
            ],
            'recipients' => $resolved['eligible'],
            'excluded_sample' => array_slice($resolved['excluded'], 0, 25),
            'engagement_authority' => $engagementAuthority,
            'note' => 'This is a REVIEW list — nothing has been sent. Show the manager the interpreted_criteria and '
                . 'recipient count. Only after explicit confirmation call send_batch_message or schedule_batch_message. '
                . 'The send tool re-checks eligibility.',
        ];
    }

    /** @return array{0: array<string, mixed>|null, 1: string|null} */
    private function applyEngagement(
        Builder $query,
        Apps $app,
        Companies $company,
        array $filters
    ): array {
        $action = trim((string) ($filters['engagement_action'] ?? ''));
        $completion = strtolower(trim((string) ($filters['engagement_completion'] ?? '')));
        if ($action === '' && $completion === '') {
            return [null, null];
        }
        if ($action === '' || $completion === '') {
            throw new InvalidArgumentException('Both engagement_action and engagement_completion are required together.');
        }

        $selection = $this->engagementFilter->resolve(
            $app,
            $company,
            $action,
            $completion
        );
        $leadIds = $selection['lead_ids'];
        $selection['exclude']
            ? $query->whereNotIn('id', $leadIds)
            : $query->whereIn('id', $leadIds === [] ? [-1] : $leadIds);

        return [[
            'action' => $action,
            'resolved_slugs' => $selection['slugs'],
            'completion' => $completion,
            'matching_engagements' => $selection['matching_engagements'],
        ], 'Action Engine Engagement records are the source of truth. A zero result means no lead satisfies this '
            . 'engagement state. Lead titles, messages and RAG context are not evidence that an engagement was started or submitted.'];
    }
}
