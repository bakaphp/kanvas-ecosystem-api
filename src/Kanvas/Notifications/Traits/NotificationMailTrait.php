<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Traits;

use Illuminate\Mail\Mailable;
use Illuminate\Notifications\AnonymousNotifiable;
use Kanvas\Apps\Support\SmtpRuntimeConfiguration;
use Kanvas\Notifications\KanvasMailable;

trait NotificationMailTrait
{
    public function toMail(object $notifiable): Mailable
    {
        $smtpConfig = new SmtpRuntimeConfiguration($this->app, $this->company);
        $mailConfig = $smtpConfig->loadSmtpSettings();
        $fromMail = $smtpConfig->getFromEmail();

        $toEmail = $this->resolveRecipientEmail($notifiable);

        $mailMessage = new KanvasMailable($mailConfig, $this->getEmailContent())
            ->from($fromMail['address'], $fromMail['name'])
            ->to($toEmail)
            ->subject($this->subject ?? $this->getNotificationTitle() ?? $this->app->name . ' Notification');

        if ($this->pathAttachment) {
            $mailMessage->attachMany($this->pathAttachment);
        }

        return $mailMessage;
    }

    private function resolveRecipientEmail(object $notifiable): array|string
    {
        $primaryEmail = $notifiable instanceof AnonymousNotifiable
            ? $notifiable->routes['mail']
            : $notifiable->email;

        if (method_exists($notifiable, 'getAlternativeEmail') && $notifiable->getAlternativeEmail()) {
            return [$primaryEmail, $notifiable->getAlternativeEmail()];
        }

        return $primaryEmail;
    }
}
