<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VoiceBridge\Workflows;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VoiceBridge\Actions\InitVoiceSessionAction;
use Kanvas\Connectors\VoiceBridge\Actions\TriggerVoiceCallAction;
use Kanvas\Connectors\VoiceBridge\Enums\ConfigurationEnum;
use Kanvas\Connectors\VoiceBridge\Enums\CustomFieldEnum;
use Kanvas\Connectors\VoiceBridge\Jobs\SaveVoiceTranscriptJob;
use Kanvas\Connectors\VoiceBridge\Services\VoiceBridgeService;
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

                $phone = Str::normalizePhoneNumber(
                    $lead->people->getCellPhones()->first()?->value
                    ?? $lead->people->getAllPhones()->first()?->value
                    ?? ''
                );

                $sessionId = VoiceBridgeService::buildOutboundSessionId(
                    (string) $lead->getId(),
                    $phone,
                    (string) $app->get(ConfigurationEnum::COMPANY_ID->value),
                );

                InitVoiceSessionAction::fromLead($lead, $agent, $messageContent)->execute();

                $result = TriggerVoiceCallAction::fromLead($lead)->execute();

                if (! empty($result['call_sid'])) {
                    $lead->set(CustomFieldEnum::CALL_SID->value, $result['call_sid']);
                }

                $transcriptDelayMinutes = (int) ($lead->company->get(ConfigurationEnum::TRANSCRIPT_DELAY_MINUTES->value) ?? 2);

                SaveVoiceTranscriptJob::dispatch($lead, $sessionId)
                    ->delay(now()->addMinutes($transcriptDelayMinutes));

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
