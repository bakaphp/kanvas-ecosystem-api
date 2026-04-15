<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ElevenLabs\Webhooks;

use Baka\Support\Str;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum as TwilioConfigurationEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;
use Throwable;

class ProcessElevenLabsSendMessageWebhookJob extends ProcessWebhookJob
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
        $normalizedPhone = Str::normalizePhoneNumber($phone);

        $lead = $this->findLeadByPhone($normalizedPhone, $phone);

        if (! $lead) {
            $this->failedReturnHttpCode = 404;

            return ['status' => 404, 'message' => 'No lead found for phone: ' . $normalizedPhone];
        }

        $channel = isset($payload['channel']) && (string) $payload['channel'] !== ''
            ? strtolower((string) $payload['channel'])
            : $this->resolveLeadChannel($lead);

        $title = isset($payload['title']) && (string) $payload['title'] !== ''
            ? (string) $payload['title']
            : null;

        $fromPhone = $company->get(TwilioConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value)
            ?? $company->get(TwilioConfigurationEnum::TWILIO_PHONE_NUMBER->value);

        $sendMessage = new SendMessageToLeadAction($lead);
        $sent = [];

        try {
            $sendMessage->execute(
                $channel,
                $message,
                (string) ($fromPhone ?? ''),
                $title,
            );
            $sent[] = ['channel' => $channel, 'success' => true];
        } catch (Throwable $e) {
            $sent[] = ['channel' => $channel, 'success' => false, 'error' => $e->getMessage()];
        }

        return [
            'message' => 'Message processed',
            'lead_id' => $lead->getId(),
            'lead_uuid' => $lead->uuid,
            'channel_used' => $channel,
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

    protected function findLeadByPhone(string $normalizedPhone, string $rawPhone): ?Lead
    {
        $digitsOnly = Str::sanitizePhoneNumber($rawPhone);

        $query = PeoplesRepository::getByPhoneNumber(
            app: $this->receiver->app,
            company: $this->receiver->company,
            phoneNumbers: array_unique([$digitsOnly, $normalizedPhone]),
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
