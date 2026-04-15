<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Notifications;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Mail\Mailable;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Support\SmtpRuntimeConfiguration;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\NotificationChannelEnum;
use Kanvas\Notifications\KanvasMailable;
use Kanvas\Notifications\Notification;
use Kanvas\Users\Models\Users;

class LeadNotification extends Notification
{
    public function __construct(
        protected Lead $lead,
        protected string $message,
        protected array $enabledChannels,
        Apps $app,
        Companies $company,
        ?Users $fromUser = null
    ) {
        parent::__construct($lead, [
            'app' => $app,
            'company' => $company,
            'fromUser' => $fromUser,
        ]);

        $this->channels = $this->filterChannels($enabledChannels);
    }

    public function toMail($notifiable): Mailable
    {
        $smtpConfig = new SmtpRuntimeConfiguration($this->app, $this->company);
        $mailConfig = $smtpConfig->loadSmtpSettings();
        $fromMail = $smtpConfig->getFromEmail();

        $toEmail = $notifiable instanceof UserInterface ? $notifiable->email : $notifiable->routes['mail'];

        return (new KanvasMailable($mailConfig, $this->message))
            ->from($fromMail['address'], $fromMail['name'])
            ->to($toEmail)
            ->subject('Lead Notification - ' . $this->lead->people->name);
    }

    public function toOneSignal($notifiable): array
    {
        return [
            'user_id' => $notifiable instanceof UserInterface ? $notifiable->getId() : null,
            'message' => $this->message,
            'title' => 'Lead Notification',
            'subtitle' => $this->lead->people->name,
            'apps_id' => $this->app->getId(),
            'data' => [
                'lead_id' => $this->lead->getId(),
            ],
        ];
    }

    private function filterChannels(array $enabledChannels): array
    {
        return array_values(
            array_filter(
                $enabledChannels,
                fn ($channel) => NotificationChannelEnum::isSupported($channel)
            )
        );
    }
}
