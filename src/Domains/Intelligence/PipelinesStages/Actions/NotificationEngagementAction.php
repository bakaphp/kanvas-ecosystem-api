<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\PipelinesStages\Actions;

use Carbon\Carbon;
use Illuminate\Support\Facades\Blade;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Sessions\Models\Session;

class NotificationEngagementAction
{
    public function __construct(
        public PipelineStage $pipelineStage
    ) {
    }

    public function execute(): void
    {
        $config = $this->pipelineStage->config;
        if (isset($config['notification_engagement_rules']) && $config['notification_engagement_rules']) {
            $leads = Lead::where('pipeline_stage_id', '=', $this->pipelineStage->id)->cursor();
            foreach ($leads as $lead) {
                $session = Session::where('entity_namespace', '=', get_class($lead))
                    ->where('entity_id', '=', $lead->getId())
                    ->where('is_deleted', 0)
                    ->first();

                $content = $session->content;

                $rules = $config['notification_engagement_rules'];
                $lastMessageTime = $lead->get(ConfigurationEnum::LAST_MESSAGE_TIME->value) ?? $content['additional_context_information']['work_hours_status']['current_time'];
                $timezone = $lead->company->get('timezone') ?? 'UTC';
                $now = Carbon::now($timezone);
                $lastMessageTime = Carbon::parse($lastMessageTime, $timezone);
                $timeDiff = $lastMessageTime->diffInMinutes($now);
                if (! $lead->get(ConfigurationEnum::AGENT_HAND_OFF->value) && $timeDiff >= $rules['minutes_no_response']) {
                    $promptText = is_array($rules['prompt']) ? implode(' ', $rules['prompt']) : (string) $rules['prompt'];
                    $prompt = Blade::render($promptText, []);

                    $message = new CreateMessageAction($prompt, $session)->execute();
                    if ($rules['send_message']) {
                        new SendMessageToLeadAction($lead)->execute(
                            $lead->get(ConfigurationEnum::AGENT_CHANNEL_TYPE->value),
                            $message,
                            $lead->company->get('twilio_phone_number')
                        );
                    }
                    $lead->set(ConfigurationEnum::LAST_MESSAGE_TIME->value, Carbon::now($timezone)->toDateTimeString());
                    $content['first_message']['message'] = $message;
                    $content['last_message_time'] = Carbon::now($timezone)->toDateTimeString();
                    $content['last_message'] = ['message' => $message];
                    $session->update(['content' => $content]);
                    $lead->moveToNextPipelineStage();
                }
            }
        }
    }
}
