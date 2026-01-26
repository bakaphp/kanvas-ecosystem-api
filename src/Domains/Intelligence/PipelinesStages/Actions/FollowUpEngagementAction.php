<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\PipelinesStages\Actions;

use Carbon\Carbon;
use Exception;
use Kanvas\Connectors\WaSender\Enums\MessageTypeEnum;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as EnumsConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpTypeEnum;
use Kanvas\Intelligence\FollowUp\Models\FollowUp;
use Kanvas\Intelligence\FollowUp\Repositories\FollowUpRepository;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Tools\CompanyWorkHoursTool;
use Kanvas\Services\DailyReportService;
use Kanvas\Social\Channels\Models\Channel;

use function Sentry\captureException;

class FollowUpEngagementAction
{
    protected FollowUp $followUp;

    public function __construct(
        public Lead $lead
    ) {
        $aiFollowUpType = $this->lead->get(IntelligenceModeEnum::AI_FOLLOW_UP->value);

        if (! $aiFollowUpType) {
            $aiFollowUpType = $this->lead->get(IntelligenceModeEnum::DEFAULT_AI_FOLLOW_UP_TYPE->value)
                ?? FollowUpTypeEnum::LEAD_FOLLOW_UP->value;
        }

        if ($aiFollowUpType === FollowUpTypeEnum::NO_FOLLOW_UP->value) {
            throw new Exception('No follow up type set on lead');
        }

        $this->followUp = FollowUpRepository::getFollowUpFromLead($lead, $aiFollowUpType);
    }

    public function execute(): ?array
    {
        $followUpDay = $this->followUp->days()
            ->where('pipeline_stages_id', $this->lead->stage->getId())
            ->where('is_deleted', 0)
            ->orderBy('weight', 'ASC')
            ->first();

        $sessions = Session::where('entity_namespace', '=', get_class($this->lead))
                ->where('entity_id', '=', $this->lead->getId())
                ->where('is_deleted', 0)
                ->fromApp($this->lead->app)
                ->fromCompany($this->lead->company)
                ->get();

        foreach ($sessions as $session) {
            if (! $session) {
                continue;
            }

            $messageTemplateChannel = $session->getChannel();
            $lastMessage = $session->channel->getLastMessage();
            $isWhatsApp = $messageTemplateChannel === 'whatsapp';

            // WhatsApp validation: check if last message was not from Lead entity
            if ($isWhatsApp) {
                $totalMessages = $session->channel->messages()->where('is_deleted', 0)->count();

                if ($totalMessages > 2 && $lastMessage) {
                    $entity = $lastMessage->entity();

                    if ($entity && ! ($entity instanceof Lead)) {
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

            if ($isWhatsApp) {
                $lastClientMessageTime = $this->getLastClientMessageTime($session->channel);

                // WhatsApp rule: we can only follow up within 24h of the last client message
                if (! $lastClientMessageTime || $lastClientMessageTime->lt(now($timezone)->subDay())) {
                    continue;
                }

                $lastMessageTime = $lastClientMessageTime;
            }

            $now = Carbon::now($timezone);
            $lastMessageCreatedAt = $lastMessage ? $lastMessage->created_at : null;

            if ($lastMessageCreatedAt) {
                if ($followUpDay->calendar_day) {
                    $this->lead->pipeline_stage_id = $followUpDay->move_to_stage_id ?? $this->lead->pipeline_stage_id;
                    $this->lead->saveOrFail();
                    $followUpDay = $this->followUp->days()
                        ->where('pipeline_stages_id', $this->lead->stage->getId())
                        ->where('is_deleted', 0)
                        ->orderBy('weight', 'ASC')
                        ->first();
                }
                $lastMessageTime = Carbon::parse($lastMessageCreatedAt, $timezone);
                $timeDiff = $lastMessageTime->diffInMinutes($now);
                $contacted = $this->lead->hasBeenContacted();
                $isActive = $this->lead->isActive();
            }

            if (! $lastMessageCreatedAt || (! $this->lead->get(ConfigurationEnum::AGENT_HAND_OFF->value)
                && $timeDiff >= $followUpDay->time_value
                && $contacted === false
                && $isActive)) {
                $message = null;
                $messageTemplateChannel = $followUpDay->templates()
                    ->where('communication_channel', $messageTemplateChannel)
                    ->where('is_deleted', 0)
                    ->inRandomOrder()
                    ->first()?->template;

                try {
                    $message = new CreateMessageFollowUpAction(
                        $this->lead,
                        $this->lead->stage,
                        $session,
                        $messageTemplateChannel,
                        $followUpDay->pipelineStage->weight
                    )->execute();
                } catch (Exception $e) {
                    captureException($e);
                }

                //if message is null, we should response
                if ($message === null) {
                    continue;
                }

                if ($followUpDay->send_message) {
                    new SendMessageToLeadAction($this->lead)->execute(
                        $messageTemplateChannel, //$this->lead->get(EnumsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value),
                        $message,
                        $this->lead->company->get('twilio_phone_number')
                    );

                    DailyReportService::track(
                        $this->lead->app,
                        $this->lead->company,
                        'ai_follow_up_engagement_sent'
                    );
                }

                $intentNumber = (int) ($this->lead->get('intent_number') ?? 0);
                $intentNumber++;

                $this->lead->set('intent_number', $intentNumber);
                $this->lead->moveToNextPipelineStage();
            }
        }

        if (isset($message) && $message) {
            return [
                'first_message' => $message,
                'last_message_time' => Carbon::now($timezone)->toDateTimeString(),
            ];
        }

        return null;
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
}
