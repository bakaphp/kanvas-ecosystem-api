<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Outreach;

use Kanvas\Companies\Enums\ConfigurationEnum as CompanyConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
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
        // Humans drop free-text like "do not reach out" / "no llamar" in the lead
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
            'errors' => $errors,
        ];
    }

    /**
     * Scan the free-text lead description for human-written do-not-contact intent.
     * Matches common English/Spanish phrasings ("do not reach out", "no contact",
     * "don't call", "no llamar", "remove me", "dnc") regardless of spacing,
     * apostrophe style, or punctuation.
     */
    private function descriptionRequestsNoContact(): bool
    {
        $description = (string) $this->lead->description;
        if (trim($description) === '') {
            return false;
        }

        // Normalize: lowercase, strip apostrophes (don't → dont), collapse any
        // non-alphanumeric run to a single space so "do-not-contact" == "do not contact".
        $normalized = strtolower($description);
        $normalized = str_replace(["'", '’', '`'], '', $normalized);
        $normalized = (string) preg_replace('/[^a-z0-9]+/', ' ', $normalized);
        $normalized = trim((string) preg_replace('/\s+/', ' ', $normalized));

        $patterns = [
            'do not contact',
            'dont contact',
            'do not reach out',
            'dont reach out',
            'do not reachout',
            'dont reachout',
            'do not call',
            'dont call',
            'do not text',
            'dont text',
            'do not email',
            'dont email',
            'do not message',
            'dont message',
            'no contact',
            'no llamar',
            'no contactar',
            'stop contacting',
            'stop reaching out',
            'remove me',
            'unsubscribe',
            'dnc',
        ];

        foreach ($patterns as $pattern) {
            // Word-boundary match so "dnc" doesn't fire inside "abcdncxyz" and
            // "no contact" doesn't match a substring of a longer unrelated token.
            if (preg_match('/\b' . preg_quote($pattern, '/') . '\b/', $normalized) === 1) {
                return true;
            }
        }

        return false;
    }
}
