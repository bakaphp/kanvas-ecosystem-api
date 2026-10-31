<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Jobs;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\FollowUp\DataTransferObject\ChannelConfig;
use Kanvas\Intelligence\FollowUp\DataTransferObject\FollowUpConfig;
use Kanvas\Intelligence\FollowUp\Jobs\LeadFollowUpJob;
use Kanvas\Intelligence\FollowUp\Services\LeadFollowUpEligibilityService;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;

#[WorkflowAction(
    name: 'Zoho Trigger Lead Follow Up',
    description: 'Receiver for a Zoho cadence step: matches the campaign, step and template to a Kanvas pipeline '
        . 'stage, moves the lead there and runs the follow-up agent. This CONTACTS the customer. Stops for good '
        . 'once the customer has replied. Writes nothing back to Zoho.',
    integration: IntegrationsEnum::ZOHO,
)]
class TriggerZohoLeadFollowUpWebhookJob extends ProcessWebhookJob
{
    public const string CUSTOMER_REPLIED = 'customer_replied';
    private const int RETRY_LOCK_SECONDS = 300;

    /**
     * This receiver messages customers, so an unset secret refuses the call instead of trusting it.
     */
    #[Override]
    public static function authenticateRequest(Request $request, ReceiverWebhook $receiver): bool
    {
        $secret = (string) (($receiver->configuration ?? [])['shared_secret'] ?? '');
        $sent = $request->header('X-Webhook-Secret') ?? $request->input('secret');

        return $secret !== '' && is_string($sent) && hash_equals($secret, $sent);
    }

    #[Override]
    public function execute(): array
    {
        $payload = is_array($this->webhookRequest->payload) ? $this->webhookRequest->payload : [];

        $lead = $this->findLead((string) ($payload['zoho_lead_id'] ?? ''));
        if ($lead === null) {
            return $this->stop('lead_not_found');
        }

        $pipeline = Pipeline::queryBySlug((string) ($payload['campaign_key'] ?? ''), $this->receiver->company)
            ->fromApp($this->receiver->app)
            ->first();
        if ($pipeline === null) {
            return $this->stop('campaign_not_found', $lead);
        }

        $stage = $this->findStage($pipeline, (string) ($payload['step_key'] ?? ''));
        if ($stage === null) {
            return $this->stop('step_not_found', $lead);
        }

        if (! $this->stageUsesTemplate($stage, (string) ($payload['template_key'] ?? ''))) {
            return $this->stop('template_mismatch', $lead);
        }

        $currentStage = $lead->stage;
        if ($currentStage?->pipelines_id !== $pipeline->getId()) {
            return $this->stop('lead_on_another_pipeline', $lead);
        }

        // A late or retried webhook for an earlier step must not undo a newer one. Moving back would
        // also reset the follow-up state and resend that step.
        if ($stage->weight < $currentStage->weight) {
            return $this->stop('step_behind_current_stage', $lead);
        }

        if ($lead->getFollowUpExhaustedReason() === self::CUSTOMER_REPLIED) {
            return $this->stop(self::CUSTOMER_REPLIED, $lead);
        }

        // Before any move: a stage change resets the last touch this is compared against.
        if (new LeadFollowUpEligibilityService()->customerRepliedSinceLastTouch($lead)) {
            $lead->markFollowUpExhausted(self::CUSTOMER_REPLIED);

            return $this->stop(self::CUSTOMER_REPLIED, $lead);
        }

        // Never released: a retry arriving before the first job has bumped the count would otherwise
        // queue a second forced send, and two workers can pass the max_retries gate together.
        if (! Cache::lock('zoho-lead-follow-up:' . $lead->getId() . ':' . $stage->getId(), self::RETRY_LOCK_SECONDS)->get()) {
            return $this->stop('already_queued', $lead);
        }

        if ($lead->pipeline_stage_id !== $stage->getId()) {
            $lead->pipeline_stage_id = $stage->getId();
            $lead->saveOrFail();
        }

        // Forced because entering a stage restarts the wait interval. Zoho owns the timing here.
        LeadFollowUpJob::dispatch(
            $this->receiver->app,
            $this->receiver->company,
            $lead,
            force: true
        )->onQueue('lead_follow_ups');

        return [
            'message' => 'Lead follow-up queued',
            'lead_id' => $lead->getId(),
            'stage_id' => $stage->getId(),
        ];
    }

    private function findLead(string $zohoLeadId): ?Lead
    {
        if ($zohoLeadId === '') {
            return null;
        }

        return Lead::getByCustomFieldBuilderTransactionSafe(CustomFieldEnum::ZOHO_LEAD_ID->value, $zohoLeadId, $this->receiver->company)
            ->fromApp($this->receiver->app)
            ->notDeleted()
            ->first();
    }

    private function findStage(Pipeline $pipeline, string $stepKey): ?PipelineStage
    {
        if ($stepKey === '') {
            return null;
        }

        return $pipeline->stages()->where('config->stage_meta->step_key', $stepKey)->first();
    }

    private function stageUsesTemplate(PipelineStage $stage, string $templateKey): bool
    {
        $channels = FollowUpConfig::fromStage($stage)?->channels ?? [];

        return collect($channels)->contains(
            fn (ChannelConfig $channel) => $channel->enabled && $channel->templateName === $templateKey
        );
    }

    private function stop(string $reason, ?Lead $lead = null): array
    {
        return [
            'message' => 'Lead follow-up not queued',
            'reason' => $reason,
            'lead_id' => $lead?->getId(),
        ];
    }
}
