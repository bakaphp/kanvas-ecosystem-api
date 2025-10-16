<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\PipelinesStages\Actions;

use Carbon\Carbon;
use Illuminate\Support\Facades\Blade;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Sessions\Models\Session;

class FollowUpEngagementAction
{
    public function __construct(
        public Lead $lead
    ) {
    }

    public function execute(): ?array
    {
        $config = $this->lead->stage->config;
        $session = Session::where('entity_namespace', '=', get_class($this->lead))
                ->where('entity_id', '=', $this->lead->getId())
                ->where('is_deleted', 0)
                ->first();

        $content = $session->content;

        $rules = $config['notification_engagement_rules'];
        $lastMessageTime = $this->lead->get(ConfigurationEnum::LAST_MESSAGE_TIME->value) ?? $content['additional_context_information']['work_hours_status']['current_time'];
        $timezone = $this->lead->company->get('timezone') ?? 'UTC';
        $now = Carbon::now($timezone);
        $lastMessageTime = Carbon::parse($lastMessageTime, $timezone);
        $timeDiff = $lastMessageTime->diffInMinutes($now);
        if (! $this->lead->get(ConfigurationEnum::AGENT_HAND_OFF->value) && $timeDiff >= $rules['minutes_no_response']) {
            $promptText = is_array($rules['prompt']) ? implode(' ', $rules['prompt']) : (string) $rules['prompt'];
            $prompt = Blade::render($promptText, $content);

            $message = new CreateMessageAction($prompt, $session)->execute();
            if (isset($rules['send_message']) && $rules['send_message']) {
                new SendMessageToLeadAction($this->lead)->execute(
                    $this->lead->get(ConfigurationEnum::AGENT_CHANNEL_TYPE->value),
                    $message,
                    $this->lead->company->get('twilio_phone_number')
                );
            }
            $this->lead->set(ConfigurationEnum::LAST_MESSAGE_TIME->value, Carbon::now($timezone)->toDateTimeString());
            $this->lead->set(ConfigurationEnum::LAST_MESSAGE->value, $message);
            $intentNumber = $this->lead->get('intent_number') ?? 0;
            $intentNumber++;
            $this->lead->set('intent_number', $intentNumber);
            $this->lead->moveToNextPipelineStage();

            return [
                'first_message' => $message,
                'last_message_time' => Carbon::now($timezone)->toDateTimeString(),
            ];
        }

        return null;
    }
}
