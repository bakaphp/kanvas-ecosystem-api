<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Illuminate\Support\Carbon;
use Kanvas\Guild\Campaigns\Actions\CreateBatchCampaignAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Services\BatchRecipientResolverService;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;
use Override;
use Throwable;

/**
 * Sends (or schedules) an email campaign to a set of standalone people (no lead attached) — the
 * CONFIRMED send step after import_people_list. It never trusts the given people_ids blindly: every
 * one is re-checked server-side (opted-out / do-not-contact / undeliverable / duplicate are dropped
 * no matter what is passed). Email only in v1. Admin-only.
 */
#[AgentTool(name: 'Create Lead Campaign', category: 'crm')]
class CreateLeadCampaignTool extends Tool
{
    use GuardsAdminForTool;
    use HasKanvasContext;

    protected string $name = 'create_lead_campaign';

    protected ?string $description = 'Send or schedule an email campaign to specific people by id — typically the '
        . 'people_ids returned by import_people_list. The tool re-verifies eligibility, so opted-out / '
        . 'do-not-contact / undeliverable / duplicate people are excluded even if passed. Email only in v1. '
        . 'Provide schedule_at (a future date-time) to schedule instead of sending now. Admin-only.';

    private const int MAX_RECIPIENTS = 5000;

    /**
     * @return array<int, ToolPropertyInterface>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ArrayProperty(
                name: 'people_ids',
                description: 'People ids to message, from import_people_list. At least one is required.',
                required: true,
                items: new ToolProperty(name: 'person_id', type: PropertyType::INTEGER, description: 'A person id.'),
            ),
            new ToolProperty(name: 'message', type: PropertyType::STRING, description: 'The message body to send to every recipient.', required: true),
            new ToolProperty(name: 'subject', type: PropertyType::STRING, description: 'Email subject line.', required: false),
            new ToolProperty(name: 'schedule_at', type: PropertyType::STRING, description: 'Optional future date-time (e.g. "2026-08-10 09:00") to schedule the send. Omit to send now.', required: false),
        ];
    }

    /**
     * @param  list<int>  $people_ids
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        array $people_ids,
        string $message,
        ?string $subject = null,
        ?string $schedule_at = null,
    ): array {
        if ($denied = $this->requireAdminOrError()) {
            return ['status' => 'error', 'message' => $denied['message']];
        }

        $message = trim($message);
        if ($message === '') {
            return ['status' => 'error', 'message' => 'A message body is required.'];
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $people_ids),
            static fn (int $id): bool => $id > 0,
        )));
        if ($ids === []) {
            return ['status' => 'error', 'message' => 'Provide at least one valid person id.'];
        }
        if (count($ids) > self::MAX_RECIPIENTS) {
            return ['status' => 'error', 'message' => 'Too many people in one batch (max ' . self::MAX_RECIPIENTS . '). Narrow the list.'];
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

        $people = People::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->whereIn('id', $ids)
            ->get();

        $resolved = new BatchRecipientResolverService()->resolvePeople($people, 'email');
        if ($resolved['eligible'] === []) {
            return [
                'status' => 'error',
                'message' => 'None of the given people are eligible to contact by email — nothing was sent.',
                'excluded' => $resolved['excluded'],
            ];
        }

        $campaign = new CreateBatchCampaignAction(
            app: $this->app,
            company: $this->company,
            user: $this->user,
            channel: 'email',
            message: $message,
            subject: $subject !== null && trim($subject) !== '' ? trim($subject) : null,
            eligibleRecipients: $resolved['eligible'],
            criteria: ['people_ids' => $ids, 'channel' => 'email'],
            scheduledAt: $scheduledAt,
        )->execute();

        return [
            'status' => 'success',
            'campaign_id' => $campaign->getId(),
            'campaign_uuid' => $campaign->uuid,
            'campaign_status' => $campaign->status,
            'scheduled_at' => $campaign->scheduled_at?->toIso8601String(),
            'total_recipients' => $resolved['eligible_count'],
            'excluded' => $resolved['excluded'],
            'note' => $this->buildNote($scheduledAt !== null, $resolved['excluded']),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $excluded
     */
    private function buildNote(bool $isScheduled, array $excluded): string
    {
        $note = $isScheduled
            ? 'Campaign scheduled. It will send at the scheduled time to the queued recipients.'
            : 'Campaign queued and sending now to the eligible people.';

        if ($excluded === []) {
            return $note;
        }

        return $note . ' ' . count($excluded) . ' of the given people were excluded and will NOT receive this '
            . 'email — always tell the user which ones and why (see excluded[].compliance_status; '
            . 'no_contact_info means that person has no email on file), even in a one-line summary. '
            . 'Never state or imply that everyone requested got the email.';
    }
}
