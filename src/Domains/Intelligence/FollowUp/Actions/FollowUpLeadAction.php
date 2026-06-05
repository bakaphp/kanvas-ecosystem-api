<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum as TwilioConfigurationEnum;
use Kanvas\Connectors\WaSender\Enums\MessageTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Intelligence\FollowUp\DataTransferObject\AgentFollowUpResult;
use Kanvas\Intelligence\FollowUp\DataTransferObject\ChannelConfig;
use Kanvas\Intelligence\FollowUp\DataTransferObject\FollowUpConfig;
use Kanvas\Intelligence\FollowUp\DataTransferObject\FollowUpOutcome;
use Kanvas\Intelligence\FollowUp\DataTransferObject\ResolvedChannel;
use Kanvas\Intelligence\FollowUp\Enums\ChannelSelectionEnum;
use Kanvas\Intelligence\FollowUp\Enums\ExhaustedActionEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpModeEnum;
use Kanvas\Intelligence\FollowUp\Services\LeadOutboundChannelResolver;
use Kanvas\Intelligence\Sessions\DataTransferObject\AiChatMessagePayload;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction as CreateSocialMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Templates\Models\Templates;
use Throwable;

/**
 * Brain of the v1 follow-up engine. Full pipeline + outcome semantics:
 * docs/intelligence/follow-up-v1-spec.md.
 *
 * `force: true` bypasses the silence gate only — for manual UI triggers.
 */
final class FollowUpLeadAction
{
    public function __construct(
        protected readonly Apps $app,
        protected readonly Companies $company,
        protected readonly Lead $lead,
        protected readonly Agent $agent,
        protected readonly bool $force = false,
    ) {
    }

    public function execute(): FollowUpOutcome
    {
        $config = FollowUpConfig::fromStage($this->lead->stage);
        if ($config === null || ! $config->enabled) {
            return $this->skip('follow_up_disabled');
        }

        // V1 ships time_based only; goal_based shape is reserved.
        if ($config->mode !== FollowUpModeEnum::TIME_BASED || $config->timeBased === null) {
            return $this->skip('unsupported_mode_v1');
        }

        if ($this->lead->isFollowUpExhausted()) {
            return $this->skip('exhausted');
        }

        if ($this->lead->getFollowUpStateCount() >= $config->maxRetries) {
            $this->handleExhaustedAction($config);

            return $this->exhaust('max_retries');
        }

        // Handoff is terminal — operator must reset follow_up_state to bring
        // the lead back into the automated flow even if the handoff clears.
        if ((bool) $this->lead->get(IntelligenceConfigurationEnum::AGENT_HAND_OFF->value)) {
            return $this->exhaust('handed_off');
        }

        // Manual mode is pausable — humans toggle off, next tick resumes.
        if ((bool) $this->lead->get(LeadConfigurationEnum::AI_MODE_IS_MANUAL->value)) {
            return $this->skip('ai_mode_manual');
        }

        // Channel resolution lives on the Lead's contacts
        $candidates = new LeadOutboundChannelResolver()->resolve($this->lead, $config);
        if ($candidates === []) {
            return $this->skip('no_reachable_channel');
        }

        $session = $this->resolveSession();
        if (! $session) {
            return $this->skip('no_session');
        }

        $lastInboundAt = $this->getLastInboundAt($session);
        $targets = $this->selectTargets($candidates, $config, $session);
        // Primary target drives prompt + template + ledger summary. For
        // FAN_OUT_ALL the same body is dispatched to every target below.
        $primary = $targets[0];
        $channelType = $primary->channelType;
        $channelConfig = $config->channelByType($channelType);

        $silenceMin = $this->lead->followUpSilenceMinutesSince($lastInboundAt);

        if (! $this->force && $silenceMin < $config->timeBased->intervalMinutes) {
            return $this->skip('too_soon');
        }

        [$template, $metaTemplate, $skipReason] = $this->resolveTemplateForChannel(
            $channelType,
            $channelConfig,
            $lastInboundAt
        );

        if ($skipReason !== null) {
            return $this->skip($skipReason);
        }

        $prompt = $this->buildAgentPrompt(
            $config,
            $channelType,
            $template?->name,
            $metaTemplate,
            $silenceMin
        );

        try {
            $raw = new AgentChatKernel(
                agent: $this->agent,
                session: $session,
                message: $prompt,
                user: $this->company->getAiAgentUserOrFail(),
                currentLead: $this->lead,
                persistConversation: false,
            )->execute();
        } catch (Throwable $e) {
            report($e);

            return $this->skip('agent_call_failed: ' . $e->getMessage());
        }

        $result = AgentFollowUpResult::fromKernelResponse($raw);

        if (! $result->shouldRespond && ! $result->advanceStage) {
            return $this->exhaust('agent: ' . ($result->reason ?? 'declined'));
        }

        $sentBody = null;
        $channelsForBump = [];
        if ($result->shouldRespond && $result->message !== null) {
            $sentBody = $this->resolveOutboundBody(
                channelType: $channelType,
                template: $template,
                agentMessage: $result->message,
            );

            foreach ($targets as $target) {
                $this->persistMessage($session, $target->channelType, $sentBody);
                $this->dispatchOutbound($target->channelType, $sentBody, $target->contact);
                $channelsForBump[] = $target->channelType;
            }

            // One touch = one bump regardless of N channels dispatched.
            $this->lead->bumpFollowUp($channelsForBump, $template?->name);
        }

        $advanced = $result->advanceStage && $this->advanceLeadStage();

        $this->emitSent(
            $channelsForBump !== [] ? $channelsForBump : [$channelType],
            $template?->name,
            $metaTemplate,
            $result->reason,
            $advanced,
            $primary,
            $config->channelSelection,
        );

        return FollowUpOutcome::sent($sentBody);
    }

    /**
     * @param ResolvedChannel[] $candidates
     * @return ResolvedChannel[] single-element for pick-one strategies, all candidates for fan_out_all
     */
    private function selectTargets(array $candidates, FollowUpConfig $config, Session $session): array
    {
        // AGENT_PICKS aliases to sticky_then_priority pending v1.5 (see FollowUp CLAUDE.md).
        return match ($config->channelSelection) {
            ChannelSelectionEnum::FAN_OUT_ALL => $candidates,
            ChannelSelectionEnum::PRIORITY_ONLY => [$candidates[0]],
            ChannelSelectionEnum::STICKY_THEN_PRIORITY,
            ChannelSelectionEnum::AGENT_PICKS => [$this->pickStickyOrFallback($candidates, $session)],
        };
    }

    /**
     * @param ResolvedChannel[] $candidates
     */
    private function pickStickyOrFallback(array $candidates, Session $session): ResolvedChannel
    {
        $stickyChannel = (string) $session->getChannel();

        foreach ($candidates as $candidate) {
            if ($candidate->channelType === $stickyChannel) {
                return $candidate;
            }
        }

        return $candidates[0];
    }

    // Sessions are keyed to People (not Lead) in the new sales-agent infra —
    // same human spans multiple leads across pipelines but one session.
    private function resolveSession(): ?Session
    {
        if (! $this->lead->people_id) {
            return null;
        }

        return Session::query()
            ->where('entity_namespace', People::class)
            ->where('entity_id', $this->lead->people_id)
            ->where('is_deleted', 0)
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->latest('id')
            ->first();
    }

    private function getLastInboundAt(Session $session): ?Carbon
    {
        $channel = $session->channel;
        if (! $channel instanceof Channel) {
            return null;
        }

        $message = $channel->messages()
            ->where('message->from_me', false)
            ->where('messages.is_deleted', 0)
            ->whereHas(
                'messageType',
                fn ($q) => $q->where('verb', MessageTypeEnum::TEXT->value),
            )
            ->latest('messages.created_at')
            ->first();

        return $message?->created_at ? Carbon::parse($message->created_at) : null;
    }

    /**
     * @return array{0: ?Templates, 1: ?string, 2: ?string}  [template, metaTemplateName, skipReason]
     */
    private function resolveTemplateForChannel(
        string $channelType,
        ChannelConfig $channelConfig,
        ?Carbon $lastInboundAt,
    ): array {
        $template = null;
        if ($channelConfig->templateName !== null) {
            // Scoped to BOTH app+company so tenants can ship same-named templates.
            $template = Templates::query()
                ->where('name', $channelConfig->templateName)
                ->fromApp($this->app)
                ->fromCompany($this->company)
                ->notDeleted()
                ->first();

            if ($template === null) {
                return [null, null, 'template_not_found: ' . $channelConfig->templateName];
            }
        }

        if ($channelType !== 'whatsapp') {
            return [$template, null, null];
        }

        $within24h = $lastInboundAt !== null && $lastInboundAt->diffInHours(Carbon::now()) < 24;
        if ($within24h) {
            return [$template, null, null];
        }

        if ($template === null) {
            return [null, null, 'outside_24h_no_template'];
        }

        // Meta-registered name lives on the Templates row — `title` column OR
        // `whatsapp_meta_name` custom field (ops convention TBD).
        $metaName = $template->title ?: $template->get('whatsapp_meta_name');
        if (! is_string($metaName) || $metaName === '') {
            return [$template, null, 'outside_24h_no_meta_name'];
        }

        return [$template, $metaName, null];
    }

    /**
     * Prompt override hierarchy (broken override falls through to default):
     *   1. stage.config.follow_up.prompt_template
     *   2. company.config.follow_up_prompt_template
     *   3. built-in default
     */
    private function buildAgentPrompt(
        FollowUpConfig $config,
        string $channelType,
        ?string $templateName,
        ?string $metaTemplate,
        int $silenceMin,
    ): string {
        $override = $config->promptTemplate
            ?? (is_string($this->company->get('follow_up_prompt_template'))
                ? (string) $this->company->get('follow_up_prompt_template')
                : null);

        $context = [
            'lead' => $this->lead,
            'people' => $this->lead->people ?? null,
            'stage' => $this->lead->stage,
            'stage_name' => $this->lead->stage->name ?? 'unknown',
            'config' => $config,
            'channel' => $channelType,
            'template_name' => $templateName,
            'meta_template' => $metaTemplate,
            'template_locked' => $metaTemplate !== null,
            'silence_minutes' => $silenceMin,
            'follow_up_count' => $this->lead->getFollowUpStateCount(),
            'max_retries' => $config->maxRetries,
        ];

        if (is_string($override) && $override !== '') {
            try {
                return Blade::render($override, $context);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $this->defaultAgentPrompt($context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function defaultAgentPrompt(array $context): string
    {
        $templateLocked = (bool) $context['template_locked'];
        $templateName = $context['template_name'];

        return implode("\n", [
            'Decide what to do for this lead in pipeline stage "' . $context['stage_name'] . '".',
            'Silence since last inbound: ' . $context['silence_minutes'] . ' minutes.',
            'Follow-up count so far in this stage: ' . $context['follow_up_count'] . ' of ' . $context['max_retries'] . '.',
            'Outbound channel: ' . $context['channel'] . '.',
            $templateName !== null
                ? 'Stage template configured: ' . $templateName . '.'
                : 'No template configured for this stage.',
            $templateLocked
                ? 'CONSTRAINT: outside WhatsApp 24h window. Use a tone consistent with the registered template (meta_name: ' . $context['meta_template'] . ').'
                : 'CONSTRAINT: inside WhatsApp 24h window (or non-WhatsApp). Free body allowed.',
            'Use conversation history to decide should_respond / advance_stage / message / reason.',
            'Respond with the JSON object only.',
        ]);
    }

    // For WhatsApp outside 24h, the rendered Blade is the LOCAL thread record;
    // Meta substitutes its own registered body server-side from the meta name.
    private function resolveOutboundBody(
        string $channelType,
        ?Templates $template,
        string $agentMessage,
    ): string {
        return $template !== null
            ? $this->renderTemplateBlade($template, $agentMessage)
            : $agentMessage;
    }

    private function renderTemplateBlade(Templates $template, string $agentMessage): string
    {
        $body = (string) ($template->template ?? '');
        if ($body === '') {
            return $agentMessage;
        }

        try {
            return Blade::render($body, [
                'lead' => $this->lead,
                'people' => $this->lead->people ?? null,
                'company' => $this->company,
                'agent_message' => $agentMessage,
            ]);
        } catch (Throwable $e) {
            report($e);

            return $agentMessage;
        }
    }

    private function persistMessage(Session $session, string $channelType, string $body): Message
    {
        $messageType = MessageTypeService::getOrCreate($this->app, $this->messageTypeVerbFor($channelType));

        $payload = new AiChatMessagePayload(
            content: $body,
            from_me: true,
            from_ia: true,
            session_id: $session->uuid,
            agent_id: (int) $this->agent->getId(),
            raw_data: $body,
        );

        // ->toArray() — DB driver can't string-convert a Data instance.
        $messageInput = MessageInput::from([
            'app' => $this->app,
            'company' => $this->company,
            'user' => $this->company->getAiAgentUserOrFail(),
            'type' => $messageType,
            'message' => $payload->toArray(),
            'is_public' => 1,
        ]);

        $message = new CreateSocialMessageAction(
            $messageInput,
            SystemModulesRepository::getByModelName(get_class($this->lead), $this->app),
            $this->lead->getId(),
        )->execute();

        $session->channel?->addMessage($message);
        $message->addTag('followup');

        return $message;
    }

    private function dispatchOutbound(string $channelType, string $body, Contact $outboundContact): void
    {
        $emailTitle = (string) ($this->lead->get('title_email_follow_up') ?? $this->company->name);
        $twilioFrom = (string) $this->company->get(TwilioConfigurationEnum::TWILIO_PHONE_NUMBER->value);

        try {
            new SendMessageToLeadAction($this->lead)->execute(
                channel: $channelType,
                message: $body,
                from: $twilioFrom,
                title: $emailTitle,
                to: (string) $outboundContact->value,
            );
        } catch (Throwable $e) {
            // Outbound failure does NOT roll back the persisted message — the
            // record of attempt is the audit truth; the queue layer retries.
            report($e);
        }
    }

    private function messageTypeVerbFor(string $channelType): string
    {
        return match ($channelType) {
            'whatsapp' => 'whatsapp',
            'email' => 'email',
            default => 'twilio-sms',
        };
    }

    private function handleExhaustedAction(FollowUpConfig $config): void
    {
        if ($config->exhaustedAction === ExhaustedActionEnum::ADVANCE) {
            $this->advanceLeadStage();
        }
    }

    private function advanceLeadStage(): bool
    {
        $before = $this->lead->pipeline_stage_id;
        $this->lead->moveToNextPipelineStage();

        return $this->lead->pipeline_stage_id !== $before;
    }

    private function skip(string $reason): FollowUpOutcome
    {
        $this->lead->emitLedgerEvent('lead.follow_up.skipped', payload: [
            'stage_id' => $this->lead->pipeline_stage_id,
            'reason' => $reason,
        ]);

        return FollowUpOutcome::skipped($reason);
    }

    private function exhaust(string $reason): FollowUpOutcome
    {
        $this->lead->markFollowUpExhausted($reason);
        $this->lead->emitLedgerEvent('lead.follow_up.exhausted', payload: [
            'stage_id' => $this->lead->pipeline_stage_id,
            'reason' => $reason,
        ]);

        return FollowUpOutcome::exhausted($reason);
    }

    /**
     * @param string[] $channels  one element for pick-one strategies, N for fan_out_all
     */
    private function emitSent(
        array $channels,
        ?string $templateName,
        ?string $metaTemplate,
        ?string $reason,
        bool $advanced,
        ResolvedChannel $primary,
        ChannelSelectionEnum $strategy,
    ): void {
        $this->lead->emitLedgerEvent('lead.follow_up.sent', payload: [
            'stage_id' => $this->lead->pipeline_stage_id,
            'channels' => $channels,
            'template' => $templateName,
            'meta_template' => $metaTemplate,
            'follow_up_count' => $this->lead->getFollowUpStateCount(),
            'by_agent_id' => $this->agent->getId(),
            'reason' => $reason,
            'advanced_stage' => $advanced,
            'channel_selection_strategy' => $strategy->value,
            'channel_selection_reason' => $primary->reason,
            'contact_id' => $primary->contact->getKey(),
        ]);
    }
}
