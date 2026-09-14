<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\PipelinesStages\Actions;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Blade;
use Kanvas\Connectors\Twilio\Actions\StoreMessageSidAction;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum as TwilioConfigurationEnum;
use Kanvas\Connectors\WaSender\Enums\MessageTypeEnum;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpTypeEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpValueEnum;
use Kanvas\Intelligence\FollowUp\Exceptions\FollowUpException;
use Kanvas\Intelligence\FollowUp\Models\FollowUp;
use Kanvas\Intelligence\FollowUp\Models\FollowUpLog;
use Kanvas\Intelligence\FollowUp\Repositories\FollowUpRepository;
use Kanvas\Intelligence\PipelinesStages\Contracts\FollowUpTimeGateOverridable;
use Kanvas\Intelligence\Services\LeadConfigurationService;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Tools\CompanyWorkHoursTool;
use Kanvas\Services\DailyReportService;
use Kanvas\Social\Channels\Models\Channel;
use Override;

use function Sentry\captureException;

/**
 * @deprecated v1 follow-up engine replaced this. Use FollowUpLeadAction in
 *             Kanvas\Intelligence\FollowUp\Actions\ — see
 *             docs/intelligence/follow-up-deprecation-spec.md kill list.
 */
final class FollowUpEngagementAction implements FollowUpTimeGateOverridable
{
    protected ?FollowUp $followUp = null;
    protected ?FollowUpLog $log = null;
    protected array $skippedReasons = [];
    protected bool $ignoreTimeGate = false;

    /**
     * Hard bound on how long a lead keeps receiving follow-ups, measured from the lead's
     * created_at. The drip advances day-stages and keeps messaging (varied copy) until this
     * window closes; after it, no further follow-ups are sent.
     */
    private const int FOLLOW_UP_WINDOW_DAYS = 90;

    #[Override]
    public function withIgnoreTimeGate(bool $ignore = true): static
    {
        $this->ignoreTimeGate = $ignore;

        return $this;
    }

    public function __construct(
        public Lead $lead,
        ?FollowUpLog $log = null,
    ) {
        $this->log = $log;
        $configService = new LeadConfigurationService();

        if (! $lead->isAiFollowUpEnabled()) {
            throw new FollowUpException('ai_follow_up is not enabled for this lead');
        }

        $followUpKey = $configService->getFollowUpModeKey($lead);
        $followUpValue = $lead->get($followUpKey);

        if ($followUpValue == FollowUpValueEnum::OFF()->value) {
            throw new FollowUpException('Follow up is disabled for this lead type');
        }

        $aiFollowUpType = $this->lead->get(IntelligenceModeEnum::AI_FOLLOW_UP->value);

        // @todo: create new logic for old field IntelligenceModeEnum::DEFAULT_AI_FOLLOW_UP_TYPE
        if (! $aiFollowUpType) {
            $aiFollowUpType = $this->lead->get(IntelligenceModeEnum::DEFAULT_AI_FOLLOW_UP_TYPE->value)
                ?? FollowUpTypeEnum::LEAD_FOLLOW_UP->value;
        }

        if ($aiFollowUpType === FollowUpTypeEnum::NO_FOLLOW_UP->value) {
            throw new FollowUpException('No follow up type set on lead');
        }

        $this->followUp = FollowUpRepository::getFollowUpFromLead($lead, $aiFollowUpType);
    }

    public function execute(): ?array
    {
        if (! $this->followUp) {
            return null;
        }

        if ($this->isPastFollowUpWindow()) {
            $this->logSkip(
                'follow_up_window_expired',
                sprintf('Lead is older than the %d-day follow-up window', self::FOLLOW_UP_WINDOW_DAYS)
            );

            return null;
        }

        $followUpDay = $this->followUp->days()
            ->where('pipeline_stages_id', $this->lead->stage->getId())
            ->where('is_deleted', 0)
            ->orderBy('weight', 'ASC')
            ->first();

        if (! $followUpDay) {
            $this->logSkip('no_follow_up_day', 'No follow-up day found for current pipeline stage');

            return null;
        }

        // Get available channels from follow-up config
        $followUpConfig = $this->followUp?->config ?? [];
        $channelsAvailable = $followUpConfig['channels_available'] ?? ['sms', 'email', 'whatsapp'];

        // Get lead's preferred channel
        $preferredChannel = $this->lead->get(LeadsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value);

        $sessions = Session::where('entity_namespace', '=', get_class($this->lead))
                ->where('entity_id', '=', $this->lead->getId())
                ->where('is_deleted', 0)
                ->fromApp($this->lead->app)
                ->fromCompany($this->lead->company)
                ->get();

        $processedChannels = [];
        foreach ($sessions as $session) {
            $messageTemplateChannel = $session->getChannel();
            if (! $messageTemplateChannel) {
                continue;
            }
            // Skip if this channel has already been processed
            if (in_array($messageTemplateChannel, $processedChannels)) {
                continue;
            }
            $processedChannels[] = $messageTemplateChannel;

            // Validate channel is in channels_available config
            if (! in_array($messageTemplateChannel, $channelsAvailable)) {
                $this->logSkip(
                    'channel_not_available',
                    "Channel '{$messageTemplateChannel}' is not in available channels: " . implode(', ', $channelsAvailable),
                    $session
                );

                continue;
            }

            // Validate channel matches lead's preferred channel (if set)
            if ($preferredChannel && $messageTemplateChannel !== $preferredChannel) {
                $this->logSkip(
                    'channel_not_preferred',
                    "Channel '{$messageTemplateChannel}' does not match lead's preferred channel: {$preferredChannel}",
                    $session
                );

                continue;
            }

            if (! $this->leadCanReceiveOnChannel($messageTemplateChannel)) {
                $this->logSkip(
                    'no_reachable_contact',
                    "Lead has no reachable contact for channel '{$messageTemplateChannel}' (sms/whatsapp require a cellphone, email requires an email)",
                    $session
                );

                continue;
            }

            $lastMessage = $session->channel->getLastMessage();
            if (! $lastMessage) {
                $this->logSkip('no_last_message', 'No last message found in channel', $session);

                continue;
            }
            $isWhatsApp = $messageTemplateChannel === 'whatsapp';

            // WhatsApp validation: check if last message was not from Lead entity
            if ($isWhatsApp) {
                $totalMessages = $session->channel->messages()->where('messages.is_deleted', 0)->count();

                if ($totalMessages > 2 && $lastMessage) {
                    $entity = $lastMessage->entity();

                    if ($lastMessage->message['from_me']) {
                        return [
                            'message' => 'Last message was not responded',
                            'reason' => 'last_message_not_from_lead',
                            'channel' => $messageTemplateChannel,
                            'total_messages' => $totalMessages,
                            'entity_type' => \get_class($entity),
                        ];
                    }
                }
            }

            //$lastMessageTime = $this->lead->get(ConfigurationEnum::LAST_MESSAGE_TIME->value) ?? $content['additional_context_information']['work_hours_status']['current_time'];
            $timezone = $this->lead->company->timezone ?? 'UTC';

            $hoursTool = new CompanyWorkHoursTool($this->lead)->execute();

            if ($hoursTool['status'] !== 'work_hours') {
                continue;
            }

            $useWhatsAppTemplate = false;
            $whatsAppTemplate = null;

            if ($isWhatsApp) {
                $lastClientMessageTime = $this->getLastClientMessageTime($session->channel);

                // WhatsApp rule: check if last client message is within 24h
                if (! $lastClientMessageTime || $lastClientMessageTime->lt(now($timezone)->subDay())) {
                    // Check if we have a WhatsApp template configured for 24h+ messages
                    $whatsAppTemplate = $followUpConfig['whatsapp_template'] ?? null;

                    if (! $whatsAppTemplate) {
                        $this->logSkip(
                            'whatsapp_24h_no_template',
                            'WhatsApp message outside 24h window and no template configured',
                            $session
                        );

                        continue;
                    }

                    // Mark that we need to use WhatsApp template instead of regular message
                    $useWhatsAppTemplate = true;
                } else {
                    $lastMessageTime = $lastClientMessageTime;
                }
            }

            $now = Carbon::now($timezone);
            $lastMessageCreatedAt = $lastMessage ? $lastMessage->created_at : null;

            if ($lastMessageCreatedAt) {
                if ($followUpDay?->calendar_day !== null) {
                    $this->lead->pipeline_stage_id = $followUpDay->move_to_stage_id ?? $this->lead->pipeline_stage_id;
                    $this->lead->saveOrFail();
                    $followUpDay = $this->followUp->days()
                        ->where('pipeline_stages_id', $this->lead->stage->getId())
                        ->where('is_deleted', 0)
                        ->orderBy('weight', 'ASC')
                        ->first();

                    if (! $followUpDay) {
                        continue;
                    }
                }

                $lastMessageTime = Carbon::parse($lastMessageCreatedAt, $timezone);
                $timeDiff = $lastMessageTime->diffInMinutes($now);
                $contacted = $this->lead->hasBeenContacted();
                $isActive = $this->lead->isActive();
            }

            if (! $this->lead->get(ConfigurationEnum::AGENT_HAND_OFF->value)
                && ($this->ignoreTimeGate || $timeDiff > $followUpDay->time_value)
                && $contacted === false
                && $isActive) {
                $message = null;
                $messageTemplate = $followUpDay->templates()
                    ->where('communication_channel', $messageTemplateChannel)
                    ->where('is_deleted', 0)
                    ->first()?->template;

                if (! $messageTemplate) {
                    continue;
                }

                $creator = new CreateMessageFollowUpAction(
                    $this->lead,
                    $this->lead->stage,
                    $session,
                    $messageTemplate,
                    (float) $followUpDay->pipelineStage->weight,
                    $messageTemplateChannel
                );

                try {
                    $candidate = $creator->generateMessageText();
                } catch (Exception $e) {
                    captureException($e);

                    continue;
                }

                if ($candidate === null) {
                    continue;
                }

                // Same message as a prior touch: do NOT resend it. Mark the day complete by
                // advancing the pipeline so the next run uses the next day-stage's template
                // (the drip keeps moving toward the 90-day window instead of repeating).
                if ($creator->isDuplicate($candidate)) {
                    $this->lead->moveToNextPipelineStage();
                    $this->logSkip(
                        'duplicate_message_advanced_stage',
                        'Generated follow-up duplicated a prior message; advanced stage instead of resending',
                        $session
                    );

                    continue;
                }

                $creator->persistMessage($candidate);
                $message = $candidate;
                $this->logSuccess('message_created', 'Follow-up message created', $session, $message);

                if ($followUpDay->send_message) {
                    $emailTitle = $this->lead->get('title_email_follow_up') ?? $this->lead->company->name;
                    $messageToSend = $message;

                    // If WhatsApp and outside 24h window, use template processed with Blade
                    if ($useWhatsAppTemplate && $whatsAppTemplate) {
                        $messageToSend = $this->renderTemplate($whatsAppTemplate);
                    }

                    $providerResponse = new SendMessageToLeadAction($this->lead)->execute(
                        $messageTemplateChannel,
                        $messageToSend,
                        $this->lead->company->get(TwilioConfigurationEnum::TWILIO_PHONE_NUMBER->value),
                        $emailTitle
                    );
                    $createdMessage = $creator->getCreatedMessage();
                    if ($createdMessage !== null) {
                        new StoreMessageSidAction($createdMessage)->execute($providerResponse);
                    }

                    $this->logSuccess('message_sent', 'Follow-up message sent to lead', $session, $messageToSend);

                    DailyReportService::track(
                        $this->lead->app,
                        $this->lead->company,
                        'ai_follow_up_engagement_sent'
                    );
                }

                $intentNumber = (int) ($this->lead->get('intent_number') ?? 0);
                $intentNumber++;

                $this->lead->set('intent_number', $intentNumber);
            }
        }

        if (isset($message) && $message) {
            $this->lead->moveToNextPipelineStage();

            return [
                'follow_up_message' => $message,
                'last_message_time' => Carbon::now($timezone)->toDateTimeString(),
            ];
        }

        return null;
    }

    protected function isPastFollowUpWindow(): bool
    {
        if ($this->lead->created_at === null) {
            return false;
        }

        return Carbon::parse($this->lead->created_at)
            ->addDays(self::FOLLOW_UP_WINDOW_DAYS)
            ->isPast();
    }

    protected function leadCanReceiveOnChannel(string $channel): bool
    {
        $value = match ($channel) {
            'sms', 'whatsapp' => $this->lead->people->getCellPhones()->first()?->value,
            'email' => $this->lead->people->getEmails()->first()?->value,
            default => 'n/a',
        };

        return $value !== null && $value !== '';
    }

    protected function getLastClientMessageTime(Channel $channel): ?Carbon
    {
        $messages = $channel->messages()
            ->where('message->from_me', false)
            ->where('messages.is_deleted', 0)
            ->whereHas('messageType', function ($query): void {
                $query->where('verb', '=', MessageTypeEnum::TEXT->value);
            })
            ->where('messages.created_at', '>=', now()->subDay())
            ->orderBy('messages.created_at', 'DESC')
            ->get();

        if (count($messages) === 0) {
            return null;
        }

        return Carbon::parse($messages->first()->created_at);
    }

    /**
     * Log skip reason for follow-up
     */
    protected function logSkip(string $reason, string $message, ?Session $session = null): void
    {
        $this->skippedReasons[] = [
            'reason' => $reason,
            'message' => $message,
            'session_id' => $session?->getId(),
            'channel' => $session?->getChannel(),
            'timestamp' => now()->toDateTimeString(),
        ];

        // Update FollowUpLog if available
        if ($this->log) {
            $metadata = $this->log->metadata ?? [];
            $metadata['skipped_reasons'] = $this->skippedReasons;
            $this->log->update([
                'metadata' => $metadata,
                'error_message' => $message,
            ]);
        }
    }

    /**
     * Log success action for follow-up
     */
    protected function logSuccess(
        string $action,
        string $message,
        ?Session $session = null,
        ?string $messageContent = null
    ): void {
        if ($this->log) {
            $metadata = $this->log->metadata ?? [];
            $metadata['success_actions'][] = [
                'action' => $action,
                'message' => $message,
                'session_id' => $session?->getId(),
                'channel' => $session?->getChannel(),
                'message_content' => $messageContent,
                'timestamp' => now()->toDateTimeString(),
            ];
            $this->log->update([
                'metadata' => $metadata,
            ]);
        }
    }

    /**
     * Get all skipped reasons
     */
    public function getSkippedReasons(): array
    {
        return $this->skippedReasons;
    }

    /**
     * Render a Blade template with lead data
     */
    protected function renderTemplate(string $template): string
    {
        return Blade::render($template, [
            'lead' => $this->lead,
            'people' => $this->lead->people,
            'company' => $this->lead->company,
            'lead_name' => $this->lead->people->firstname ?? 'Customer',
            'lead_full_name' => $this->lead->people->name ?? 'Customer',
            'company_name' => $this->lead->company->name,
        ]);
    }
}
