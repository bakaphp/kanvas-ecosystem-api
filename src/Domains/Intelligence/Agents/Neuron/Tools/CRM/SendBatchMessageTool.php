<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Illuminate\Support\Carbon;
use Kanvas\Guild\Campaigns\Actions\CreateBatchCampaignAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\BatchRecipientResolverService;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Sends (or schedules) a batch SMS/email to a set of leads — the CONFIRMED send step of the manager
 * batch flow. Only call this AFTER the manager has reviewed the recipient list from find_leads_by_traits
 * and explicitly approved both the recipients and the message. It never composes its own audience: it
 * takes explicit lead_ids, re-checks every one server-side (opted-out / do-not-contact / undeliverable /
 * duplicate are dropped no matter what is passed), records a campaign, and fans the send out on a queue.
 * Recipients are resolved from each lead's own deliverable contact — you cannot send to a free-typed
 * address or number. Admin-only.
 */
#[AgentTool(name: 'Send Batch Message', category: 'crm')]
class SendBatchMessageTool extends Tool
{
    use GuardsAdminForTool;
    use HasKanvasContext;

    private const int MAX_LEADS = 5000;

    public function __construct(
        private readonly BatchRecipientResolverService $resolver = new BatchRecipientResolverService(),
    ) {
        parent::__construct(
            name: 'send_batch_message',
            description: 'Send or schedule a batch SMS or email to specific leads by id — the confirmed send step. '
                . 'Pass the eligible lead_ids from find_leads_by_traits (comma-separated) after the manager approved '
                . 'the list and the message. Provide schedule_at (a future date-time) to schedule instead of sending '
                . 'now. The tool re-verifies eligibility, so opted-out / do-not-contact / undeliverable / duplicate '
                . 'leads are excluded even if passed. Do NOT call this from the initial request — the manager must '
                . 'confirm recipients and message first. Admin-only.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'lead_ids', type: PropertyType::STRING, description: 'Comma-separated lead ids to message, e.g. "12,45,88".', required: true),
            new ToolProperty(name: 'channel', type: PropertyType::STRING, description: 'sms or email.', required: true),
            new ToolProperty(name: 'message', type: PropertyType::STRING, description: 'The message body to send to every recipient.', required: true),
            new ToolProperty(name: 'subject', type: PropertyType::STRING, description: 'Email subject line (email channel only).', required: false),
            new ToolProperty(name: 'schedule_at', type: PropertyType::STRING, description: 'Optional future date-time (e.g. "2026-08-10 09:00") to schedule the send. Omit to send now.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $lead_ids,
        string $channel,
        string $message,
        ?string $subject = null,
        ?string $schedule_at = null,
    ): array {
        if ($denied = $this->requireAdminOrError()) {
            return ['status' => 'error', 'message' => $denied['message']];
        }

        $channel = strtolower(trim($channel));
        if (! in_array($channel, ['sms', 'email'], true)) {
            return ['status' => 'error', 'message' => 'Invalid channel. Use "sms" or "email".'];
        }

        $message = trim($message);
        if ($message === '') {
            return ['status' => 'error', 'message' => 'A message body is required.'];
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', explode(',', $lead_ids)),
            static fn (int $id): bool => $id > 0,
        )));
        if ($ids === []) {
            return ['status' => 'error', 'message' => 'Provide at least one valid lead id.'];
        }
        if (count($ids) > self::MAX_LEADS) {
            return ['status' => 'error', 'message' => 'Too many leads in one batch (max ' . self::MAX_LEADS . '). Narrow the segment.'];
        }

        $scheduledAt = null;
        if (($schedule_at = trim((string) $schedule_at)) !== '') {
            try {
                $scheduledAt = Carbon::parse($schedule_at);
            } catch (Throwable) {
                return ['status' => 'error', 'message' => 'Could not understand schedule_at. Use a date-time like "2026-08-10 09:00".'];
            }
            if ($scheduledAt->isPast()) {
                return ['status' => 'error', 'message' => 'schedule_at must be in the future.'];
            }
        }

        $leads = Lead::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->whereIn('id', $ids)
            ->with(['people', 'owner', 'stage'])
            ->get();

        $resolved = $this->resolver->resolve($leads, $channel);
        if ($resolved['eligible'] === []) {
            return [
                'status' => 'error',
                'message' => 'None of the given leads are eligible to contact on this channel — nothing was sent.',
                'excluded' => $resolved['excluded'],
            ];
        }

        $campaign = new CreateBatchCampaignAction(
            app: $this->app,
            company: $this->company,
            user: $this->user,
            channel: $channel,
            message: $message,
            subject: $subject !== null && trim($subject) !== '' ? trim($subject) : null,
            eligibleRecipients: $resolved['eligible'],
            criteria: ['lead_ids' => $ids, 'channel' => $channel],
            scheduledAt: $scheduledAt,
        )->execute();

        return [
            'status' => 'success',
            'campaign_id' => $campaign->getId(),
            'campaign_uuid' => $campaign->uuid,
            'campaign_status' => $campaign->status,
            'scheduled_at' => $campaign->scheduled_at?->toIso8601String(),
            'recipients_queued' => $resolved['eligible_count'],
            'excluded_count' => $resolved['excluded_count'],
            'note' => $scheduledAt !== null
                ? 'Campaign scheduled. Tell the manager it will send at the scheduled time to the queued recipients; '
                    . 'excluded leads were not included. They can review results later with get_batch_history.'
                : 'Campaign queued and sending now to the eligible recipients; excluded leads were skipped. '
                    . 'Results (sent/failed/skipped) become available via get_batch_history.',
        ];
    }
}
