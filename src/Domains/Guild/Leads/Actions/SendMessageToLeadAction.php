<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Exception;
use InvalidArgumentException;
use Kanvas\Connectors\Twilio\Client;
use Kanvas\Connectors\WaSender\Services\MessageService;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Models\Lead;

class SendMessageToLeadAction
{
    public function __construct(
        protected Lead $lead,
    ) {
    }

    public function execute(string $channel, string $message, ?string $from = ''): array
    {
        //TODO. we need to add this message to the lead channel

        return match ($channel) {
            LeadCommunicationChannelEnum::WHATSAPP->value => $this->sendWhatsAppMessage($message),
            LeadCommunicationChannelEnum::SMS->value => $this->sendSmsMessage($from, $message),
            LeadCommunicationChannelEnum::EMAIL->value => $this->sendEmailMessage($message),
            default => throw new InvalidArgumentException('Unsupported communication channel ' . $channel),
        };
    }

    protected function sendWhatsAppMessage(string $message): array
    {
        $whatsAppMessageService = new MessageService(
            $this->lead->app,
            $this->lead->company
        );

        $cellphone = $this->lead->people->getCellPhones()->first()?->value;

        if (! $cellphone) {
            throw new InvalidArgumentException('Lead does not have a cellphone number');
        }
        $cellphone = $this->hijackPhoneNumber($cellphone, '@s.whatsapp.net');

        // Define the callback to send each chunk in real time
        return $whatsAppMessageService->sendTextMessage($cellphone, $message);
    }

    protected function sendSmsMessage(string $from, string $message): array
    {
        $client = Client::getInstanceByCompany($this->lead->company);

        $cellphone = $this->lead->people->getCellPhones()->first()?->value;

        if (! $cellphone) {
            throw new InvalidArgumentException('Lead does not have a cellphone number');
        }

        $cellphone = $this->hijackPhoneNumber($cellphone, 'twilio-');

        $message = $client->messages->create(
            $cellphone, // to
            [
                'from' => $from,
                'body' => $message,
            ]
        );

        return [$message->body];
    }

    protected function sendEmailMessage(string $message): array
    {
        //TODO implement Email sending
        return [];
    }

    protected function hijackPhoneNumber(string $cellphone, string $replace): string
    {
        if ($this->lead->company->get('allow_session_hijack', false)
          && $this->lead->company->get('overwrite_phone_number') !== null
        ) {
            $overwriteConfig = $this->lead->company->get('overwrite_phone_number');

            $phone = array_filter($overwriteConfig, function ($value) use ($cellphone) {
                return str_contains($value, str_replace('+', '', $cellphone));
            });
            if (! $phone) {
                throw new Exception('No hijack number found for this phone number');
            }
            $cellphone = array_keys($phone)[0];
            $cellphone = str_replace($replace, '', $cellphone);
        }

        return $cellphone;
    }
}
