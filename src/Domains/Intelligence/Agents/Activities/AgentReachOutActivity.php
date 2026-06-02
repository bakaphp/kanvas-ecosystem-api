<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum as CompanyConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\Outreach\AgentReachOutOnChannelAction;
use Kanvas\Intelligence\Agents\Actions\Outreach\ResolveLeadChannelPreferencesAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\Leads\Enums\AgentReachOutConfigEnum;
use Kanvas\Intelligence\Services\LeadConfigurationService;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Throwable;

/**
 * Outbound-first reach-out orchestrator. Fired by a workflow rule bound to
 * WorkflowEnum::CREATED + system_module=Lead (Apollo / web form / CSV import
 * create Lead → activity dispatched). Idempotent via AgentReachOutConfigEnum::STATUS
 * on the Lead — set $params['allow_resend'] = true to override for cadence touches.
 *
 * Replaces the deprecated LeadAgentFirstMessageOutreachActivity. Routes text
 * generation through AgentChatKernel (single agent invocation path) and
 * delivery through SendMessageToLeadAction (canonical outbound dispatcher).
 *
 * Params:
 *   - agent_id (int, optional) — falls back to AGENT_REACH_OUT_DEFAULT_AGENT_ID company config
 *   - allow_resend (bool, default false) — bypass STATUS=sent idempotency guard
 *   - lead_source_allowlist (array, optional) — if set, skip leads whose source isn't in list
 *
 * The LLM prompt is NOT configured here — it lives on the agent row (role / soul /
 * instructions / output_format columns). The agent designated for reach-out has its
 * job baked into its own configuration. On skip/fail/mute the activity records the cause
 * on AgentReachOutConfigEnum::REASON (internally derived); on success only STATUS=sent +
 * SENT_AT + CHANNELS_SENT are set.
 */
#[WorkflowAction]
class AgentReachOutActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Lead $lead, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            additionalParams: $params,
            integrationOperation: fn () => $this->reachOut($lead, $params),
            company: $lead->company,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function reachOut(Lead $lead, array $params): array
    {
        $allowResend = (bool) ($params['allow_resend'] ?? false);

        // === Idempotency ===
        $status = (string) $lead->get(AgentReachOutConfigEnum::STATUS->value);
        if ($status === AgentReachOutConfigEnum::STATUS_SENT && ! $allowResend) {
            return ['message' => 'Already reached out', 'status' => $status];
        }
        if ($status === AgentReachOutConfigEnum::STATUS_IN_PROGRESS) {
            return ['message' => 'Reach-out already in flight (concurrent)', 'status' => $status];
        }

        // === Source allowlist ===
        $allowlist = (array) ($params['lead_source_allowlist'] ?? []);
        if ($allowlist !== []) {
            $source = strtolower((string) ($lead->source?->name ?? ''));
            if (! in_array($source, array_map('strtolower', $allowlist), true)) {
                $lead->set(AgentReachOutConfigEnum::STATUS->value, AgentReachOutConfigEnum::STATUS_SKIPPED);
                $lead->set(AgentReachOutConfigEnum::REASON->value, 'source_not_allowed:' . $source);

                return ['message' => 'Lead source not in allowlist', 'source' => $source];
            }
        }

        // === AI-mode mute check ===
        $leadAiMode = IntelligenceModeEnum::tryFrom(
            (string) $lead->get(new LeadConfigurationService()->getAiModeKey($lead))
        );

        if ($leadAiMode?->isOff()) {
            $lead->set(AgentReachOutConfigEnum::STATUS->value, AgentReachOutConfigEnum::STATUS_MUTED);

            return ['message' => 'Lead AI mode is off', 'status' => 'muted'];
        }

        // === Agent resolution: params > company config > throw ===
        $agentId = (int) (
            $params['agent_id']
            ?? $lead->company->get(CompanyConfigurationEnum::AGENT_REACH_OUT_DEFAULT_AGENT_ID->value)
        );

        if ($agentId === 0) {
            throw new ValidationException(sprintf(
                'No agent configured for reach-out on lead #%d. Pass agent_id in workflow rule '
                . 'params or set company config %s.',
                $lead->getId(),
                CompanyConfigurationEnum::AGENT_REACH_OUT_DEFAULT_AGENT_ID->value,
            ));
        }

        $agent = Agent::getById($agentId, $lead->app);

        // === Channel walk ===
        $channels = new ResolveLeadChannelPreferencesAction($lead)->execute();
        if ($channels === []) {
            $lead->set(AgentReachOutConfigEnum::STATUS->value, AgentReachOutConfigEnum::STATUS_SKIPPED);
            $lead->set(AgentReachOutConfigEnum::REASON->value, 'no_contact_info');

            return ['message' => 'Lead has no usable contact info', 'status' => 'skipped'];
        }

        $lead->set(AgentReachOutConfigEnum::STATUS->value, AgentReachOutConfigEnum::STATUS_IN_PROGRESS);

        $sentChannels = [];
        $errors = [];

        foreach ($channels as $pair) {
            try {
                new AgentReachOutOnChannelAction(
                    lead: $lead,
                    agent: $agent,
                    channelType: $pair['channel_type'],
                    recipient: $pair['recipient'],
                )->execute();
                $sentChannels[] = $pair['channel_type'];
            } catch (Throwable $e) {
                report($e);
                $errors[$pair['channel_type']] = $e->getMessage();
            }
        }

        if ($sentChannels === []) {
            $lead->set(AgentReachOutConfigEnum::STATUS->value, AgentReachOutConfigEnum::STATUS_FAILED);
            $lead->set(AgentReachOutConfigEnum::LAST_ERROR->value, json_encode($errors));

            return ['message' => 'All channels failed', 'errors' => $errors];
        }

        $lead->set(AgentReachOutConfigEnum::STATUS->value, AgentReachOutConfigEnum::STATUS_SENT);
        $lead->set(AgentReachOutConfigEnum::SENT_AT->value, now()->toDateTimeString());
        $lead->set(AgentReachOutConfigEnum::CHANNELS_SENT->value, $sentChannels);

        return [
            'message' => 'Reach-out sent',
            'status' => AgentReachOutConfigEnum::STATUS_SENT,
            'channels_sent' => $sentChannels,
            'errors' => $errors,
        ];
    }
}
