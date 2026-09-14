<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ElevenLabs\Webhooks;

use Baka\Support\Str;
use Kanvas\ActionEngine\Engagements\Actions\CreateEngagementAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement as EngagementData;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Connectors\Twilio\Actions\StoreMessageSidAction;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum as TwilioConfigurationEnum;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Override;
use Throwable;

#[WorkflowAction(
    name: 'ElevenLabs Share Product With Caller',
    description: 'One of the endpoints an ElevenLabs VOICE agent calls back into Kanvas mid-call. These are '
        . 'wired as that agent\'s server-side tools, not chosen as workflow steps — the caller on the '
        . 'phone triggers them. This one TEXTS the caller a product link during the call and records '
        . 'the engagement. It CONTACTS the customer, so it is the one to be careful with.',
)]
class ProcessElevenLabsProductShareWebhookJob extends ProcessElevenLabsWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = (array) $this->webhookRequest->payload;
        $phone = isset($payload['phone']) ? (string) $payload['phone'] : null;
        $variantId = isset($payload['variant_id']) ? (string) $payload['variant_id'] : null;

        if ($phone === null || $phone === '') {
            $this->failedReturnHttpCode = 422;

            return ['status' => 422, 'message' => 'Phone number is required'];
        }

        if ($variantId === null || $variantId === '') {
            $this->failedReturnHttpCode = 422;

            return ['status' => 422, 'message' => 'Variant ID is required'];
        }

        $app = $this->receiver->app;
        $company = $this->receiver->company;

        $lead = $this->resolveLeadByPhone($phone);

        /** @var Variants $variant */
        $variant = Variants::getByIdFromCompanyApp((int) $variantId, $company, $app);

        $channel = Channels::getDefault($company, $app);

        $engagementDto = EngagementData::from(
            app: $app,
            company: $company,
            user: $this->resolveUser(),
            lead: $lead,
            request: [
                'action' => 'view-vehicle',
                'request_id' => (string) Str::uuid(),
                'source' => 'elevenlabs',
                'status' => ActionStatusEnum::SENT->value,
                'data' => [
                    'product_id' => [$variant->uuid],
                    'channel_id' => $channel->uuid,
                ],
            ],
            people: $lead->people,
        );

        $engagement = new CreateEngagementAction($engagementDto)->execute();
        /** @var array $messageContent */
        $messageContent = $engagement->message->message ?? [];
        $shareLink = isset($messageContent['action_link']) ? (string) $messageContent['action_link'] : null;

        $smsMessage = isset($payload['message']) && (string) $payload['message'] !== ''
            ? (string) $payload['message']
            : 'Check out this vehicle: ' . (string) $variant->name;

        if ($shareLink !== null && $shareLink !== '') {
            $smsMessage .= "\n\n" . $shareLink;
        }

        $smsSent = false;
        $emailSent = false;
        $sendMessage = new SendMessageToLeadAction($lead);

        $fromPhone = $company->get(TwilioConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value)
            ?? $company->get(TwilioConfigurationEnum::TWILIO_PHONE_NUMBER->value);

        try {
            if ($fromPhone) {
                $providerResponse = $sendMessage->execute(
                    channel: 'sms',
                    message: $smsMessage,
                    from: (string) $fromPhone,
                    to: $phone,
                );
                new StoreMessageSidAction($engagement->message)->execute($providerResponse);
                $smsSent = true;
            }
        } catch (Throwable $e) {
            report($e);
        }

        $email = $lead->people->getEmails()->first()?->value;
        if ($email !== null && $email !== '' && $shareLink !== null && $shareLink !== '') {
            try {
                $sendMessage->execute(
                    'email',
                    $smsMessage,
                    null,
                    'Vehicle of Interest: ' . (string) $variant->name,
                );
                $emailSent = true;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return [
            'message' => 'Product share engagement created',
            'lead_id' => $lead->getId(),
            'variant_id' => $variant->getId(),
            'variant_name' => $variant->name,
            'engagement_id' => $engagement->getId(),
            'share_link' => $shareLink,
            'sms_sent' => $smsSent,
            'email_sent' => $emailSent,
        ];
    }
}
