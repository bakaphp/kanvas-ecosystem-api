<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\PipelinesStages\Actions;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Blade;
use Kanvas\Connectors\WaSender\Enums\MessageTypeEnum;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpTypeEnum;
use Kanvas\Intelligence\FollowUp\Exceptions\FollowUpException;
use Kanvas\Intelligence\FollowUp\Models\FollowUp;
use Kanvas\Intelligence\FollowUp\Models\FollowUpLog;
use Kanvas\Intelligence\FollowUp\Repositories\FollowUpRepository;
use Kanvas\Intelligence\PipelinesStages\Contracts\FollowUpTimeGateOverridable;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Tools\CompanyWorkHoursTool;
use Kanvas\Services\DailyReportService;
use Kanvas\Social\Channels\Models\Channel;
use Override;

use function Sentry\captureException;

/**
 * @deprecated v1 follow-up engine (Kanvas\Intelligence\FollowUp\Actions\FollowUpLeadAction)
 *             replaced this V1 path. Slated for deletion — see
 *             docs/intelligence/follow-up-deprecation-spec.md kill list.
 */
class FollowUpEngagementV1Action implements FollowUpTimeGateOverridable
{
    protected ?FollowUp $followUp = null;
    protected ?FollowUpLog $log = null;
    protected array $skippedReasons = [];
    protected bool $ignoreTimeGate = false;

    /**
     * Backstop only. The primary stop is the stage-advance gate below; this caps the
     * unanswered outbound streak in case state is inconsistent. WhatsApp already stops
     * at the first unanswered touch (24h-window policy).
     */
    private const int MAX_UNANSWERED_FOLLOW_UPS = 2;

    /**
     * Lead custom field holding the pipeline stage id of the last follow-up we sent.
     * If the lead is still on that same stage on a later run it never advanced (terminal
     * stage / no next stage / move_to_stage_id null), so we must NOT re-send — the drip is
     * done for that stage. A follow-up fires at most once per day-stage.
     */
    private const string LAST_FOLLOW_UP_STAGE_KEY = 'follow_up_last_stage_id';

    #[Override]
    public function withIgnoreTimeGate(bool $ignore = true): static
    {
        $this->ignoreTimeGate = $ignore;

        return $this;
    }

    public function __construct(
        public Lead $lead,
        ?FollowUpLog $log = null
    ) {
        $this->log = $log;
        $aiFollowUpType = $this->lead->get(IntelligenceModeEnum::AI_FOLLOW_UP->value);

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

        $followUpDay = $this->followUp->days()
            ->where('pipeline_stages_id', $this->lead->stage->getId())
            ->where('is_deleted', 0)
            ->orderBy('weight', 'ASC')
            ->first();

        if (! $followUpDay) {
            $this->logSkip('no_follow_up_day', 'No follow-up day found for current pipeline stage');

            return null;
        }

        $followUpConfig = $this->followUp?->config ?? [];
        $channelsAvailable = $followUpConfig['channels_available'] ?? ['sms', 'email', 'whatsapp'];
        $preferredChannel = $this->lead->get(LeadsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value);

        $sessions = Session::where('entity_namespace', '=', get_class($this->lead))
                ->where('entity_id', '=', $this->lead->getId())
                ->where('is_deleted', 0)
                ->fromApp($this->lead->app)
                ->fromCompany($this->lead->company)
                ->get();

        $processedChannels = [];
        foreach ($sessions as $session) {
            try {
                $messageTemplateChannel = $session->getChannel();
            } catch (Exception $e) {
                //we don't support this channel type
                continue;
            }

            if (in_array($messageTemplateChannel, $processedChannels)) {
                continue;
            }

            $processedChannels[] = $messageTemplateChannel;

            if (! in_array($messageTemplateChannel, $channelsAvailable)) {
                $this->logSkip(
                    'channel_not_available',
                    "Channel '{$messageTemplateChannel}' is not in available channels: " . implode(', ', $channelsAvailable),
                    $session
                );

                continue;
            }

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
            } elseif (is_array($lastMessage->message) && ($lastMessage->message['from_me'] ?? false) === true) {
                // twilio-sms / email: unlike WhatsApp these have no unanswered guard, so a lead
                // who never replies got a near-identical nudge every cron tick. Stop once our own
                // outbound has gone unanswered MAX_UNANSWERED_FOLLOW_UPS times in a row.
                $unansweredStreak = $this->countTrailingUnansweredOutbound($session->channel);

                if ($unansweredStreak >= self::MAX_UNANSWERED_FOLLOW_UPS) {
                    $this->logSkip(
                        'max_unanswered_follow_ups',
                        "Skipping '{$messageTemplateChannel}': last {$unansweredStreak} outbound messages went unanswered",
                        $session
                    );

                    continue;
                }
            }

            $timezone = $this->lead->company->timezone ?? 'UTC';
            $hoursTool = new CompanyWorkHoursTool($this->lead)->execute();

            if ($hoursTool['status'] !== 'work_hours') {
                continue;
            }

            $useWhatsAppTemplate = false;
            $whatsAppTemplate = null;

            if ($isWhatsApp) {
                $lastClientMessageTime = $this->getLastClientMessageTime($session->channel);

                if (! $lastClientMessageTime || $lastClientMessageTime->lt(now($timezone)->subDay())) {
                    $whatsAppTemplate = $followUpConfig['whatsapp_template'] ?? null;

                    if (! $whatsAppTemplate) {
                        $this->logSkip(
                            'whatsapp_24h_no_template',
                            'WhatsApp message outside 24h window and no template configured',
                            $session
                        );

                        continue;
                    }

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

            $currentStageId = (int) $this->lead->pipeline_stage_id;
            $lastFollowUpStageId = $this->lead->get(self::LAST_FOLLOW_UP_STAGE_KEY);

            if ($lastFollowUpStageId !== null && (int) $lastFollowUpStageId === $currentStageId) {
                $this->logSkip(
                    'stage_not_advanced',
                    "Lead has not advanced past stage {$currentStageId} since the last follow-up; skipping to avoid repeating the same day",
                    $session
                );

                continue;
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

                try {
                    $message = new CreateMessageFollowUpAction(
                        $this->lead,
                        $this->lead->stage,
                        $session,
                        $messageTemplate,
                        (float)$followUpDay->pipelineStage->weight,
                        $messageTemplateChannel
                    )->execute();

                    $this->logSuccess('message_created', 'Follow-up message created', $session, $message);
                } catch (Exception $e) {
                    captureException($e);
                }

                if ($message === null) {
                    continue;
                }

                if ($followUpDay->send_message) {
                    $emailTitle = $this->lead->get('title_email_follow_up') ?? $this->lead->company->name;
                    $messageToSend = $message;

                    if ($useWhatsAppTemplate && $whatsAppTemplate) {
                        $messageToSend = $this->renderTemplate($whatsAppTemplate);
                    }

                    new SendMessageToLeadAction($this->lead)->execute(
                        $messageTemplateChannel,
                        $messageToSend,
                        $this->lead->company->get('twilio_phone_number'),
                        $emailTitle
                    );

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
                $this->lead->set(self::LAST_FOLLOW_UP_STAGE_KEY, $currentStageId);
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

    protected function leadCanReceiveOnChannel(string $channel): bool
    {
        $value = match ($channel) {
            'sms', 'whatsapp' => $this->lead->people->getCellPhones()->first()?->value,
            'email' => $this->lead->people->getEmails()->first()?->value,
            default => 'n/a',
        };

        return $value !== null && $value !== '';
    }

    protected function countTrailingUnansweredOutbound(Channel $channel): int
    {
        $messages = $channel->messages()
            ->where('messages.is_deleted', 0)
            ->orderBy('messages.created_at', 'DESC')
            ->get();

        $streak = 0;
        foreach ($messages as $message) {
            if (! is_array($message->message) || ($message->message['from_me'] ?? false) !== true) {
                break;
            }

            $streak++;
        }

        return $streak;
    }

    protected function getLastClientMessageTime(Channel $channel): ?Carbon
    {
        $messages = $channel->messages()
            ->where('message->from_me', false)
            ->where('messages.is_deleted', 0)
            ->whereHas(
                'messageType',
                fn ($query) => $query->where('verb', '=', MessageTypeEnum::TEXT->value)
            )
            ->where('messages.created_at', '>=', now()->subDay())
            ->orderBy('messages.created_at', 'DESC')
            ->get();

        if (count($messages) === 0) {
            return null;
        }

        return Carbon::parse($messages->first()->created_at);
    }

    protected function logSkip(string $reason, string $message, ?Session $session = null): void
    {
        $this->skippedReasons[] = [
            'reason' => $reason,
            'message' => $message,
            'session_id' => $session?->getId(),
            'channel' => $session?->getChannel(),
            'timestamp' => now()->toDateTimeString(),
        ];

        if ($this->log) {
            $metadata = $this->log->metadata ?? [];
            $metadata['skipped_reasons'] = $this->skippedReasons;
            $this->log->update([
                'metadata' => $metadata,
                'error_message' => $message,
            ]);
        }
    }

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

    public function getSkippedReasons(): array
    {
        return $this->skippedReasons;
    }

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
