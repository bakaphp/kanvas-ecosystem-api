<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Outreach;

use Kanvas\Companies\Enums\ConfigurationEnum as CompanyConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\Leads\Enums\AgentReachOutConfigEnum;
use Kanvas\Intelligence\Services\LeadConfigurationService;
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
        if ($status === AgentReachOutConfigEnum::STATUS_IN_PROGRESS) {
            return ['message' => 'Reach-out already in flight (concurrent)', 'status' => $status];
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
        $leadAiMode = IntelligenceModeEnum::tryFrom(
            (string) $this->lead->get(new LeadConfigurationService()->getAiModeKey($this->lead))
        );

        if ($leadAiMode?->isOff()) {
            $this->lead->set(AgentReachOutConfigEnum::STATUS->value, AgentReachOutConfigEnum::STATUS_MUTED);

            return ['message' => 'Lead AI mode is off', 'status' => 'muted'];
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
        $errors = [];

        foreach ($channels as $pair) {
            try {
                new AgentReachOutOnChannelAction(
                    lead: $this->lead,
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
            $this->lead->set(AgentReachOutConfigEnum::STATUS->value, AgentReachOutConfigEnum::STATUS_FAILED);
            $this->lead->set(AgentReachOutConfigEnum::LAST_ERROR->value, json_encode($errors));

            return ['message' => 'All channels failed', 'errors' => $errors];
        }

        $this->lead->set(AgentReachOutConfigEnum::STATUS->value, AgentReachOutConfigEnum::STATUS_SENT);
        $this->lead->set(AgentReachOutConfigEnum::SENT_AT->value, now()->toDateTimeString());
        $this->lead->set(AgentReachOutConfigEnum::CHANNELS_SENT->value, $sentChannels);

        return [
            'message' => 'Reach-out sent',
            'status' => AgentReachOutConfigEnum::STATUS_SENT,
            'channels_sent' => $sentChannels,
            'errors' => $errors,
        ];
    }
}
