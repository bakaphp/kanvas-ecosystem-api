<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use InvalidArgumentException;
use Kanvas\Connectors\WaSender\Services\MessageService;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Models\Lead;

class SendMessageToLeadAction
{
    public function __construct(
        protected Lead $lead,
    ) {
    }

    public function execute(string $channel, string $message): array
    {
        //TODO. we need to add this message to the lead channel

        return match ($channel) {
            LeadCommunicationChannelEnum::WHATSAPP->value => $this->sendWhatsAppMessage($message),
            LeadCommunicationChannelEnum::SMS->value => $this->sendSmsMessage($message),
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

        // Define the callback to send each chunk in real time
        return $whatsAppMessageService->sendTextMessage($cellphone, $message);
    }

    protected function sendSmsMessage(string $message): array
    {
        //TODO implement SMS sending
        return [];
    }

    protected function sendEmailMessage(string $message): array
    {
        //TODO implement Email sending
        return [];
    }
}
