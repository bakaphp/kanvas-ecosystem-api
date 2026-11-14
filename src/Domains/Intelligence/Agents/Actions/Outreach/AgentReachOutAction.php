<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Outreach;

use Kanvas\Companies\Enums\ConfigurationEnum as CompanyConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Enums\ConsentConfigurationEnum;
use Kanvas\Guild\Customers\Services\ConsentKeywordService;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Leads\Enums\AgentReachOutConfigEnum;
use Throwable;

/**
 * Outbound-first reach-out orchestrator: idempotency, source allowlist, AI-mode
 * mute, agent resolution, channel walk.
 *
 * The LLM prompt lives on the agent row (role / soul / instructions /
 * output_format) — not here.
 */
class AgentReachOutAction
{
    /**
     * @param array<string, mixed> $params
     * @param Agent|null $agent the agent a caller already holds; otherwise the one `agent_id`
     *                          names in the params, else the one with reach-out switched on
     */
    public function __construct(
        protected readonly Lead $lead,
        protected readonly array $params = [],
        protected readonly ?bool $deferDelivery = null,
        protected readonly ?Agent $agent = null,
    ) {
    }

    /**
     * Whether a result of this action means a message went out or is waiting to: every other
     * outcome (skipped, muted, already done, failed) left the lead untouched.
     *
     * @param array<string, mixed> $result
     */
    public static function reachedTheLead(array $result): bool
    {
        return ($result['channels_sent'] ?? []) !== [] || ($result['channels_scheduled'] ?? []) !== [];
    }

    /**
     * @return array<string, mixed> with `agent_id` once an agent was resolved
     */
    public function execute(): array
    {
        $allowResend = (bool) ($this->params['allow_resend'] ?? false);

        $status = (string) $this->lead->get(AgentReachOutConfigEnum::STATUS->value);
        if ($status === AgentReachOutConfigEnum::STATUS_SENT && ! $allowResend) {
            return ['message' => 'Already reached out', 'status' => $status];
        }
        if ($status === AgentReachOutConfigEnum::STATUS_SCHEDULED && ! $allowResend) {
            return ['message' => 'Reach-out already scheduled', 'status' => $status];
        }
        if ($status === AgentReachOutConfigEnum::STATUS_IN_PROGRESS) {
            return ['message' => 'Reach-out already in flight (concurrent)', 'status' => $status];
        }

        // Only reach out to open leads (named status). A lead already closed
        // (won/lost/inactive) must not receive an outbound agent touch even if a
        // workflow rule re-fires CREATED on it.
        if (! $this->lead->isOpen()) {
            $this->lead->set(AgentReachOutConfigEnum::STATUS->value, AgentReachOutConfigEnum::STATUS_SKIPPED);
            $this->lead->set(AgentReachOutConfigEnum::REASON->value, 'lead_not_active');

            return ['message' => 'Lead is not active', 'status' => 'skipped'];
        }

        $allowlist = (array) ($this->params['lead_source_allowlist'] ?? []);
        if ($allowlist !== []) {
            $source = strtolower((string) ($this->lead->source?->name ?? ''));
            if (! in_array($source, array_map('strtolower', $allowlist), true)) {
                $this->lead->set(AgentReachOutConfigEnum::STATUS->value, AgentReachOutConfigEnum::STATUS_SKIPPED);
                $this->lead->set(AgentReachOutConfigEnum::REASON->value, 'source_not_allowed:' . $source);

                return ['message' => 'Lead source not in allowlist', 'source' => $source];
            }
        }

        if ($this->lead->isAiMuted()) {
            $this->lead->set(AgentReachOutConfigEnum::STATUS->value, AgentReachOutConfigEnum::STATUS_MUTED);

            return ['message' => 'Lead AI mode is off', 'status' => 'muted'];
        }

        // The flag first: a prospect who asked to stop is never cold-reached, whatever the
        // description says.
        if ((bool) $this->lead->get(ConsentConfigurationEnum::DO_NOT_CONTACT->value)
            || (bool) $this->lead->people?->get(ConsentConfigurationEnum::DO_NOT_CONTACT->value)
        ) {
            $this->lead->set(AgentReachOutConfigEnum::STATUS->value, AgentReachOutConfigEnum::STATUS_SKIPPED);
            $this->lead->set(AgentReachOutConfigEnum::REASON->value, 'do_not_contact');

            return ['message' => 'Lead is flagged do-not-contact', 'status' => 'skipped'];
        }

        // Humans also drop free-text like "do not reach out" / "no llamar" in the lead
        // description; honor it before spending an agent turn on the lead.
        if ($this->descriptionRequestsNoContact()) {
            $this->lead->set(AgentReachOutConfigEnum::STATUS->value, AgentReachOutConfigEnum::STATUS_SKIPPED);
            $this->lead->set(AgentReachOutConfigEnum::REASON->value, 'do_not_contact');

            return ['message' => 'Lead description requests no contact', 'status' => 'skipped'];
        }

        $agent = $this->agent ?? (
            isset($this->params['agent_id'])
                ? Agent::getById((int) $this->params['agent_id'], $this->lead->app)
                : Agent::forNewLeads($this->lead->app, $this->lead->company)
        );

        if ($agent === null) {
            throw new ValidationException(sprintf(
                'No agent reaches out to new leads for lead #%d. Switch it on for an agent '
                . '(reach_out_config.new_leads) or pass agent_id in the workflow rule params.',
                $this->lead->getId(),
            ));
        }

        $agentId = $agent->getId();

        $channels = new ResolveLeadChannelPreferencesAction($this->lead)->execute();
        if ($channels === []) {
            $this->lead->set(AgentReachOutConfigEnum::STATUS->value, AgentReachOutConfigEnum::STATUS_SKIPPED);
            $this->lead->set(AgentReachOutConfigEnum::REASON->value, 'no_contact_info');

            return ['message' => 'Lead has no usable contact info', 'status' => 'skipped', 'agent_id' => $agentId];
        }

        $this->lead->set(AgentReachOutConfigEnum::STATUS->value, AgentReachOutConfigEnum::STATUS_IN_PROGRESS);

        $sentChannels = [];
        $sentMessageIds = [];
        $scheduledChannels = [];
        $scheduledMessageIds = [];
        $errors = [];
        $deferDelivery = $this->deferDelivery ?? $this->lead->isAiSupport();

        foreach ($channels as $pair) {
            try {
                $outbound = new AgentReachOutOnChannelAction(
                    lead: $this->lead,
                    agent: $agent,
                    channelType: $pair['channel_type'],
                    recipient: $pair['recipient'],
                    deferDelivery: $deferDelivery,
                    from: isset($this->params['from']) ? (string) $this->params['from'] : null,
                )->execute();
                if ($deferDelivery) {
                    $scheduledChannels[] = $pair['channel_type'];
                    $scheduledMessageIds[] = $outbound->getId();
                } else {
                    $sentChannels[] = $pair['channel_type'];
                    if (! $outbound->isLocked()) {
                        $sentMessageIds[] = $outbound->getId();
                    }
                }
            } catch (Throwable $e) {
                report($e);
                $errors[$pair['channel_type']] = $e->getMessage();
            }
        }

        if ($sentChannels === [] && $scheduledChannels === []) {
            $this->lead->set(AgentReachOutConfigEnum::STATUS->value, AgentReachOutConfigEnum::STATUS_FAILED);
            $this->lead->set(AgentReachOutConfigEnum::LAST_ERROR->value, json_encode($errors));

            return ['message' => 'All channels failed', 'errors' => $errors, 'agent_id' => $agentId];
        }

        $this->lead->moveToNextPipelineStage();

        if ($scheduledChannels !== []) {
            $this->lead->set(AgentReachOutConfigEnum::STATUS->value, AgentReachOutConfigEnum::STATUS_SCHEDULED);
            $this->lead->set(AgentReachOutConfigEnum::CHANNELS_SENT->value, []);

            return [
                'message' => 'Reach-out scheduled for support mode',
                'status' => AgentReachOutConfigEnum::STATUS_SCHEDULED,
                'agent_id' => $agentId,
                'channels_scheduled' => $scheduledChannels,
                'message_ids_scheduled' => $scheduledMessageIds,
                'message_ids_sent' => [],
                'delay_minutes' => (int) ($this->lead->company->get(
                    CompanyConfigurationEnum::MESSAGE_MINUTES_INTERVAL->value
                ) ?? 60),
                'errors' => $errors,
            ];
        }

        $this->lead->set(AgentReachOutConfigEnum::STATUS->value, AgentReachOutConfigEnum::STATUS_SENT);
        $this->lead->set(AgentReachOutConfigEnum::SENT_AT->value, now()->toDateTimeString());
        $this->lead->set(AgentReachOutConfigEnum::CHANNELS_SENT->value, $sentChannels);

        return [
            'message' => 'Reach-out sent',
            'status' => AgentReachOutConfigEnum::STATUS_SENT,
            'agent_id' => $agentId,
            'channels_sent' => $sentChannels,
            'message_ids_sent' => $sentMessageIds,
            'message_ids_scheduled' => [],
            'errors' => $errors,
        ];
    }

    /**
     * Staff type do-not-contact intent straight into the lead description ("do not reach out",
     * "no llamar"). Same matcher the inbound channels use — see ConsentKeywordService.
     */
    private function descriptionRequestsNoContact(): bool
    {
        return ConsentKeywordService::matchesNoContactPhrase((string) $this->lead->description);
    }
}
