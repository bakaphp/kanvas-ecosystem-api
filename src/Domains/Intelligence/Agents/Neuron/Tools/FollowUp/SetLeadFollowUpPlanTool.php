<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\FollowUp;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsLeadOwnerForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use Kanvas\Intelligence\FollowUp\Actions\SetLeadFollowUpPlanAction;
use Kanvas\Intelligence\FollowUp\DataTransferObject\FollowUpConfig;
use Kanvas\Intelligence\FollowUp\DataTransferObject\ManualFollowUpPlan;
use Kanvas\Intelligence\FollowUp\Jobs\LeadFollowUpJob;
use Kanvas\Intelligence\FollowUp\Services\LeadFollowUpEligibilityService;
use Kanvas\Intelligence\FollowUp\Services\LeadOutboundChannelResolver;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * A person's own drip on one lead, run by the follow-up engine rather than by scheduled agent wakes:
 * the engine owns the stop on reply, the opt-outs, the attempt cap and the lead's activity record.
 * The recipient is never a parameter; the engine resolves the number or address from the lead's
 * contacts, so the person cannot point the message anywhere else.
 */
#[AgentTool(name: 'Set Lead Follow-up Plan', category: 'crm')]
class SetLeadFollowUpPlanTool extends Tool implements RequiresSystemAgent
{
    use GuardsLeadOwnerForTool;
    use ReportsToolOutcome;
    use ResolvesLeadForTool;
    use TrackByInputs;

    private const array CHANNELS = [
        LeadCommunicationChannelEnum::SMS,
        LeadCommunicationChannelEnum::EMAIL,
        LeadCommunicationChannelEnum::WHATSAPP,
    ];

    protected string $name = 'set_lead_follow_up_plan';

    protected ?string $description = 'Set up repeated follow-up messages to a lead on the person\'s behalf: one channel, the exact '
        . 'text they want sent, every N hours, up to a number of attempts, stopping as soon as the lead replies. '
        . 'Use it when someone asks you to "follow up with this lead for 7 days", "text them daily until they '
        . 'answer" or similar. It replaces the stage\'s automatic follow-up for that lead while it runs, then the '
        . 'stage follow-up resumes. If the result says the lead\'s follow-up is switched off, tell the person and '
        . 'call again with confirm_enable_follow_up=true once they agree to switch it back on.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'lead_id',
                type: PropertyType::INTEGER,
                description: 'The lead to follow up with (from search_leads or the lead in context).',
                required: true,
            ),
            new ToolProperty(
                name: 'channel',
                type: PropertyType::STRING,
                description: 'sms, email or whatsapp.',
                required: true,
            ),
            new ToolProperty(
                name: 'message',
                type: PropertyType::STRING,
                description: 'The exact text to send each time, as the person wrote or approved it. Sent as is.',
                required: true,
            ),
            new ToolProperty(
                name: 'every_hours',
                type: PropertyType::INTEGER,
                description: 'Hours between messages. Empty means 24.',
                required: false,
            ),
            new ToolProperty(
                name: 'max_attempts',
                type: PropertyType::INTEGER,
                description: 'How many messages at most. Empty means 7; the cap is 30.',
                required: false,
            ),
            new ToolProperty(
                name: 'start_at',
                type: PropertyType::STRING,
                description: 'When the first message goes out, as "YYYY-MM-DD HH:MM" in the company\'s local time. '
                    . 'Empty means at the next hourly pass.',
                required: false,
            ),
            new ToolProperty(
                name: 'stop_on_reply',
                type: PropertyType::BOOLEAN,
                description: 'Stop as soon as the lead replies on any channel. Empty means true.',
                required: false,
            ),
            new ToolProperty(
                name: 'confirm_enable_follow_up',
                type: PropertyType::BOOLEAN,
                description: 'Pass true only after the person agreed to switch the lead\'s follow-up back on.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $lead_id,
        string $channel,
        string $message,
        ?int $every_hours = null,
        ?int $max_attempts = null,
        ?string $start_at = null,
        ?bool $stop_on_reply = null,
        ?bool $confirm_enable_follow_up = null,
    ): array {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('follow-up plan');
        }

        $lead = $this->resolveLeadOrError($lead_id);
        if (is_array($lead)) {
            return $lead;
        }

        $refusal = $this->requireLeadOwnerOrAdminOrError($lead, 'set its follow-up');
        if ($refusal !== null) {
            return $refusal;
        }

        $channelEnum = LeadCommunicationChannelEnum::tryFrom(strtolower(trim($channel)));
        if ($channelEnum === null || ! in_array($channelEnum, self::CHANNELS, true)) {
            return $this->invalidArgs('channel must be sms, email or whatsapp.');
        }

        $body = Str::trimToNull($message);
        if ($body === null) {
            return $this->invalidArgs('Say what to send: the message is empty.');
        }

        if (mb_strlen($body) > ManualFollowUpPlan::MAX_BODY_LENGTH) {
            return $this->invalidArgs(sprintf('The message is too long: keep it under %d characters.', ManualFollowUpPlan::MAX_BODY_LENGTH));
        }

        $pausedReason = new LeadFollowUpEligibilityService()->pausedReason($lead);
        if ($pausedReason !== null) {
            return $this->denied(
                $pausedReason === 'ai_mode_manual'
                    ? 'This lead is in manual AI mode, so nothing automated messages this customer.'
                    : 'AI is switched off for this lead, so nothing automated messages this customer.',
                ['lead_id' => $lead->getId(), 'reason' => $pausedReason],
                guidance: 'Tell the person: switch AI on for the lead and ask again, or send the first message themselves.',
            );
        }

        if (! $lead->hasOpenLeadStatus()) {
            return $this->denied(
                'This lead is closed; follow-ups only run on open leads.',
                ['lead_id' => $lead->getId(), 'reason' => 'lead_not_active'],
            );
        }

        try {
            Agent::getByNameFromCompanyApp(LeadFollowUpJob::agentNameFor($lead), $this->company, $this->app);
        } catch (ModelNotFoundException) {
            return $this->failed('No follow-up agent is set up for this company, so the engine cannot send on its behalf.');
        }

        $startAt = $this->parseStartAt($start_at);
        if (is_array($startAt)) {
            return $startAt;
        }

        $plan = new ManualFollowUpPlan(
            channel: $channelEnum->value,
            body: $body,
            intervalMinutes: max(1, $every_hours ?? 24) * 60,
            maxAttempts: min(max(1, $max_attempts ?? 7), ManualFollowUpPlan::MAX_ATTEMPTS_CAP),
            startAt: $startAt,
            setAt: Carbon::now(),
            setByUsersId: $this->user->getId(),
            stopOnReply: $stop_on_reply ?? true,
        );

        if (new LeadOutboundChannelResolver()->resolve($lead, FollowUpConfig::fromPlan($plan)) === []) {
            return $this->denied(
                sprintf('The lead has no usable %s contact, or has opted out of being contacted.', $channelEnum->value),
                ['lead_id' => $lead->getId(), 'reason' => 'no_reachable_channel'],
                guidance: 'Offer another channel the lead has, or that the person adds the contact first.',
            );
        }

        $followUpOff = new LeadFollowUpEligibilityService()->aiFollowUpDisabled($lead);
        if ($followUpOff && ! $confirm_enable_follow_up) {
            return $this->noop(
                ['lead_id' => $lead->getId(), 'needs_confirmation' => true, 'reason' => 'lead_follow_up_disabled'],
                guidance: 'Automatic follow-up is switched off on this lead. Ask the person: to run their plan it will '
                    . 'be switched back on, and their plan replaces the stage default for this lead while it runs. '
                    . 'Nothing was set yet; call again with confirm_enable_follow_up=true once they agree.',
            );
        }

        try {
            new SetLeadFollowUpPlanAction($lead, $plan, $this->user)->execute();
        } catch (Throwable $e) {
            report($e);

            return $this->failed('The plan could not be saved: ' . $e->getMessage());
        }

        return $this->ok(
            [
                'lead_id' => $lead->getId(),
                'channel' => $plan->channel,
                'message' => $plan->body,
                'every_hours' => intdiv($plan->intervalMinutes, 60),
                'max_attempts' => $plan->maxAttempts,
                'start_at_local' => $plan->startAt->copy()->setTimezone($this->companyTimezone())->format('Y-m-d H:i'),
                'stop_on_reply' => $plan->stopOnReply,
                'follow_up_turned_on' => $followUpOff,
                'first_sms_carries_opt_out_notice' => $plan->channel === LeadCommunicationChannelEnum::SMS->value,
            ],
            guidance: 'Confirm to the person: the channel, the exact text, the cadence, the attempt cap, the start, that '
                . 'it stops when the lead replies, and that they can cancel by asking you or by switching follow-up '
                . 'off on the lead.',
        );
    }

    /**
     * @return Carbon|array<string, mixed>
     */
    private function parseStartAt(?string $startAt): Carbon|array
    {
        $startAt = Str::trimToNull($startAt);
        if ($startAt === null) {
            return Carbon::now();
        }

        try {
            return Carbon::parse($startAt, $this->companyTimezone())->utc();
        } catch (Throwable) {
            return $this->invalidArgs('start_at must be "YYYY-MM-DD HH:MM" in the company\'s local time.');
        }
    }

    private function companyTimezone(): string
    {
        return $this->company->getTimezone() ?? 'UTC';
    }
}
