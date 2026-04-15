<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ElevenLabs\Webhooks;

use Baka\Support\Str;
use Kanvas\ActionEngine\Engagements\Actions\CreateEngagementAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement as EngagementData;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum as TwilioConfigurationEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;
use Throwable;

class ProcessElevenLabsProductShareWebhookJob extends ProcessWebhookJob
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
        $normalizedPhone = Str::normalizePhoneNumber($phone);

        $lead = $this->findLeadByPhone($normalizedPhone, $phone);

        if (! $lead) {
            $this->failedReturnHttpCode = 404;

            return ['status' => 404, 'message' => 'No lead found for phone: ' . $normalizedPhone];
        }

        /** @var Variants $variant */
        $variant = Variants::getByIdFromCompanyApp((int) $variantId, $company, $app);

        $channel = Channels::getDefault($company, $app);

        $engagementDto = EngagementData::from(
            app: $app,
            company: $company,
            user: $company->user,
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

        $engagement = new CreateEngagementAction($engagementDto, false)->execute();
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
                $sendMessage->execute('sms', $smsMessage, (string) $fromPhone);
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

    protected function findLeadByPhone(string $normalizedPhone, string $rawPhone): ?Lead
    {
        $phoneWithCountryCode = str_replace('+', '', $rawPhone);

        $query = PeoplesRepository::getByPhoneNumber(
            app: $this->receiver->app,
            company: $this->receiver->company,
            phoneNumbers: [$normalizedPhone, $phoneWithCountryCode],
        );

        $allCustomers = $query->get();

        $people = $allCustomers->first(function (People $customer): bool {
            return LeadsRepository::getPeopleActiveLead($customer) !== null;
        }) ?? $allCustomers->first();

        if (! $people) {
            return null;
        }

        return LeadsRepository::getPeopleActiveLead($people);
    }
}
