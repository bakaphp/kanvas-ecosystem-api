<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VoiceBridge\Workflows;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VoiceBridge\Actions\InitVoiceSessionAction;
use Kanvas\Connectors\VoiceBridge\Actions\TriggerVoiceCallAction;
use Kanvas\Connectors\VoiceBridge\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class SendVoiceMessageActivity extends KanvasActivity
{
    public $tries = 1;

    public function execute(Message $message, Apps $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function () use ($message, $app) {
                $lead = $message->entity();

                if (! $lead instanceof Lead) {
                    return ['success' => false, 'message' => 'Message entity is not a Lead'];
                }

                if (empty($app->get(ConfigurationEnum::API_KEY->value))) {
                    return ['success' => false, 'message' => 'VoiceBridge API key not configured'];
                }

                $messageContent = is_array($message->message)
                    ? ($message->message['content'] ?? $message->message['raw'] ?? '')
                    : (string) $message->message;

                $agent = Agent::fromApp($app)
                    ->fromCompany($lead->company)
                    ->where('name', 'voiceOutreachAgent')
                    ->firstOrFail();

                InitVoiceSessionAction::fromLead($lead, $agent, $messageContent)->execute();

                $result = TriggerVoiceCallAction::fromLead($lead)->execute();

                return [
                    'success' => true,
                    'call_sid' => $result['call_sid'] ?? null,
                    'status' => $result['status'] ?? null,
                    'message_id' => $message->getId(),
                    'lead_id' => $lead->getId(),
                ];
            }
        );
    }
}
