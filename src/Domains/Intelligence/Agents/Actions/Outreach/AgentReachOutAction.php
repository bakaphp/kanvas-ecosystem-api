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
 * mute, agent resolution, channel walk. Called by AgentReachOutActivity (the
 * workflow-engine adapter) but lives here as a plain Action so it's directly
 * testable without workflow-engine internals.
 *
 * The LLM prompt lives on the agent row (role / soul / instructions /
 * output_format) — not here.
 */
class AgentReachOutAction
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        protected readonly Lead $lead,
        protected readonly array $params = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $allowResend = (bool) ($this->params['allow_resend'] ?? false);

        // === Idempotency ===
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

        // === Active-lead guard ===
        // Only reach out to open leads (status < 2). A lead already closed
        // (won/lost/inactive) must not receive an outbound agent touch even if a
        // workflow rule re-fires CREATED on it.
        if (! $this->lead->isOpen()) {
            $this->lead->set(AgentReachOutConfigEnum::STATUS->value, AgentReachOutConfigEnum::STATUS_SKIPPED);
            $this->lead->set(AgentReachOutConfigEnum::REASON->value, 'lead_not_active');

            return ['message' => 'Lead is not active', 'status' => 'skipped'];
        }

        // === Source allowlist ===
        $allowlist = (array) ($this->params['lead_source_allowlist'] ?? []);
        if ($allowlist !== []) {
            $source = strtolower((string) ($this->lead->source?->name ?? ''));
            if (! in_array($source, array_map('strtolower', $allowlist), true)) {
                $this->lead->set(AgentReachOutConfigEnum::STATUS->value, AgentReachOutConfigEnum::STATUS_SKIPPED);
                $this->lead->set(AgentReachOutConfigEnum::REASON->value, 'source_not_allowed:' . $source);

                return ['message' => 'Lead source not in allowlist', 'source' => $source];
            }
        }

        // === AI-mode mute check ===
        if ($this->lead->isAiMuted()) {
            $this->lead->set(AgentReachOutConfigEnum::STATUS->value, AgentReachOutConfigEnum::STATUS_MUTED);

            return ['message' => 'Lead AI mode is off', 'status' => 'muted'];
        }

        // === Do-not-contact guard ===
        // The flag first: this action names 'do_not_contact' as a skip reason but used to check only
        // the description, so a prospect who actually asked to stop could still be cold-reached.
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

        // === Agent resolution: params > company config > throw ===
        $agentId = (int) (
            $this->params['agent_id']
            ?? $this->lead->company->get(CompanyConfigurationEnum::AGENT_REACH_OUT_DEFAULT_AGENT_ID->value)
        );

        if ($agentId === 0) {
            throw new ValidationException(sprintf(
                'No agent configured for reach-out on lead #%d. Pass agent_id in workflow rule '
                . 'params or set company config %s.',
                $this->lead->getId(),
                CompanyConfigurationEnum::AGENT_REACH_OUT_DEFAULT_AGENT_ID->value,
            ));
        }

        $agent = Agent::getById($agentId, $this->lead->app);

        // === Channel walk ===
        $channels = new ResolveLeadChannelPreferencesAction($this->lead)->execute();
        if ($channels === []) {
            $this->lead->set(AgentReachOutConfigEnum::STATUS->value, AgentReachOutConfigEnum::STATUS_SKIPPED);
            $this->lead->set(AgentReachOutConfigEnum::REASON->value, 'no_contact_info');

            return ['message' => 'Lead has no usable contact info', 'status' => 'skipped'];
        }

        $this->lead->set(AgentReachOutConfigEnum::STATUS->value, AgentReachOutConfigEnum::STATUS_IN_PROGRESS);

        $sentChannels = [];
        $sentMessageIds = [];
        $scheduledChannels = [];
        $scheduledMessageIds = [];
        $errors = [];
        $deferDelivery = $this->lead->isAiSupport();

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

            return ['message' => 'All channels failed', 'errors' => $errors];
        }

        if ($scheduledChannels !== []) {
            $this->lead->set(AgentReachOutConfigEnum::STATUS->value, AgentReachOutConfigEnum::STATUS_SCHEDULED);
            $this->lead->set(AgentReachOutConfigEnum::CHANNELS_SENT->value, []);

            return [
                'message' => 'Reach-out scheduled for support mode',
                'status' => AgentReachOutConfigEnum::STATUS_SCHEDULED,
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
