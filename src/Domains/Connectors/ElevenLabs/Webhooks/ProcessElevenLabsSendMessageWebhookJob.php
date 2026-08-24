<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ElevenLabs\Webhooks;

use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum as TwilioConfigurationEnum;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsConfigurationEnum;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Override;
use Throwable;

#[WorkflowAction(
    name: 'ElevenLabs Send Message To Caller',
    description: 'One of the endpoints an ElevenLabs VOICE agent calls back into Kanvas mid-call. These are '
        . 'wired as that agent\'s server-side tools, not chosen as workflow steps — the caller on the '
        . 'phone triggers them. This one SENDS the caller a message mid-call — a confirmation, a link, '
        . 'whatever the agent composed. It CONTACTS the customer.',
)]
class ProcessElevenLabsSendMessageWebhookJob extends ProcessElevenLabsWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = (array) $this->webhookRequest->payload;
        $phone = isset($payload['phone']) ? (string) $payload['phone'] : null;
        $message = isset($payload['message']) ? (string) $payload['message'] : null;

        if ($phone === null || $phone === '') {
            $this->failedReturnHttpCode = 422;

            return ['status' => 422, 'message' => 'Phone number is required'];
        }

        if ($message === null || $message === '') {
            $this->failedReturnHttpCode = 422;

            return ['status' => 422, 'message' => 'Message is required'];
        }

        $company = $this->receiver->company;

        $lead = $this->resolveLeadByPhone($phone);

        $requestedChannel = isset($payload['channel']) && (string) $payload['channel'] !== ''
            ? strtolower((string) $payload['channel'])
            : $this->resolveLeadChannel($lead);

        // Phone is always present, so SMS is always attempted; add the requested channel if different.
        $channels = [LeadCommunicationChannelEnum::SMS->value];
        if ($requestedChannel !== LeadCommunicationChannelEnum::SMS->value) {
            $channels[] = $requestedChannel;
        }

        $title = isset($payload['title']) && (string) $payload['title'] !== ''
            ? (string) $payload['title']
            : null;

        $fromPhone = $company->get(TwilioConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value)
            ?? $company->get(TwilioConfigurationEnum::TWILIO_PHONE_NUMBER->value);

        $sendMessage = new SendMessageToLeadAction($lead);
        $sent = [];

        foreach ($channels as $channel) {
            try {
                $sendMessage->execute(
                    channel: $channel,
                    message: $message,
                    from: (string) ($fromPhone ?? ''),
                    title: $title,
                    to: $phone,
                );
                $sent[] = ['channel' => $channel, 'success' => true];
            } catch (Throwable $e) {
                $sent[] = ['channel' => $channel, 'success' => false, 'error' => $e->getMessage()];
            }
        }

        return [
            'message' => 'Message processed',
            'lead_id' => $lead->getId(),
            'lead_uuid' => $lead->uuid,
            'channels_used' => $channels,
            'sent' => $sent,
        ];
    }

    protected function resolveLeadChannel(Lead $lead): string
    {
        $preferredChannel = $lead->get(LeadsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value);

        if ($preferredChannel !== null && $preferredChannel !== '') {
            return (string) $preferredChannel;
        }

        return 'sms';
    }
}
