<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Illuminate\Notifications\AnonymousNotifiable;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Notifications\LeadReceivedConfirmationNotification;

class SendLeadConfirmationToContactAction
{
    private const array ALLOWED_CHANNELS = ['mail', 'sms'];

    /**
     * @param array<int,string> $channels  requested delivery channels (mail and/or sms)
     */
    public function __construct(
        private readonly Lead $lead,
        private readonly array $channels = ['mail'],
    ) {
    }

    /**
     * Sends the lead's contact (the submitter) a confirmation carrying a short reference code
     * derived from the last 6 characters of the lead uuid, over email and/or SMS depending on the
     * requested channels and which destinations the contact actually has.
     *
     * @return array{reference: string, channels: array<int,string>}
     */
    public function execute(): array
    {
        $reference = $this->confirmationReference();
        $destinations = $this->resolveDestinations();

        if ($destinations === []) {
            return ['reference' => $reference, 'channels' => []];
        }

        $notification = new LeadReceivedConfirmationNotification(
            $this->lead,
            [
                'app' => $this->lead->app,
                'company' => $this->lead->company,
                'lead' => $this->lead,
                'reference' => $reference,
            ],
        );
        $notification->channels = array_keys($destinations);

        $notifiable = new AnonymousNotifiable();
        foreach ($destinations as $channel => $route) {
            $notifiable->route($channel, $route);
        }
        $notifiable->notify($notification);

        return [
            'reference' => $reference,
            'channels' => array_keys($destinations),
        ];
    }

    /**
     * @return array<string,string> channel => destination address (email or phone)
     */
    private function resolveDestinations(): array
    {
        $destinations = [];

        foreach (array_intersect(self::ALLOWED_CHANNELS, $this->channels) as $channel) {
            $route = $channel === 'sms' ? $this->resolvePhone() : $this->resolveEmail();

            if ($route !== null) {
                $destinations[$channel] = $route;
            }
        }

        return $destinations;
    }

    private function resolveEmail(): ?string
    {
        $email = (string) ($this->lead->people?->getEmails()->first()?->value ?? '');

        return $email === '' ? null : $email;
    }

    private function resolvePhone(): ?string
    {
        $phone = (string) ($this->lead->people?->getAllPhones()->first()?->value ?? '');

        return $phone === '' ? null : $phone;
    }

    private function confirmationReference(): string
    {
        return strtoupper(substr($this->lead->uuid, -6));
    }
}
