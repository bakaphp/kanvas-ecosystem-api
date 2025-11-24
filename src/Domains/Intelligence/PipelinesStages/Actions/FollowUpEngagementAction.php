<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\PipelinesStages\Actions;

use Carbon\Carbon;
use Exception;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as EnumsConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Tools\CompanyWorkHoursTool;

use function Sentry\captureException;

class FollowUpEngagementAction
{
    public function __construct(
        public Lead $lead
    ) {
    }

    public function execute(): ?array
    {
        $config = $this->lead->stage->config;
        $sessions = Session::where('entity_namespace', '=', get_class($this->lead))
                ->where('entity_id', '=', $this->lead->getId())
                ->where('is_deleted', 0)
                ->fromApp($this->lead->app)
                ->fromCompany($this->lead->company)
                ->get();
        foreach ($sessions as $session) {
            if (! $session) {
                return null;
            }

            $emailChannel = str_contains($session->uuid, 'email');
            $twilioChannel = str_contains($session->uuid, 'twilio');

            $messageTemplateChannel = $emailChannel
                ? 'email'
                : ($twilioChannel ? 'sms' : null);
            $lastMessage = $session->channel->getLastMessage();

            if (! $lastMessage) {
                return null;
            }

            $rules = $config['notification_engagement_rules'];
            //$lastMessageTime = $this->lead->get(ConfigurationEnum::LAST_MESSAGE_TIME->value) ?? $content['additional_context_information']['work_hours_status']['current_time'];
            $timezone = $this->lead->company->timezone ?? 'UTC';

            $hoursTool = new CompanyWorkHoursTool($this->lead)->execute();

            if ($hoursTool['status'] !== 'work_hours') {
                return null;
            }

            $now = Carbon::now($timezone);

            $lastMessageTime = Carbon::parse($lastMessage->created_at, $timezone);
            $timeDiff = $lastMessageTime->diffInMinutes($now);
            $contacted = $this->lead->hasBeenContacted();

            if (! $this->lead->get(ConfigurationEnum::AGENT_HAND_OFF->value) && $timeDiff >= $rules['minutes_no_response'] && $contacted === false) {
                try {
                    $message = new CreateMessageFollowUpAction(
                        $this->lead,
                        $this->lead->stage,
                        $session,
                        $messageTemplateChannel
                    )->execute();
                } catch (Exception $e) {
                    captureException($e);
                }

                //if message is null, we should response
                if ($message === null) {
                    continue;
                }

                if (isset($rules['send_message']) && $rules['send_message']) {
                    new SendMessageToLeadAction($this->lead)->execute(
                        $this->lead->get(EnumsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value),
                        $message,
                        $this->lead->company->get('twilio_phone_number')
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
}
