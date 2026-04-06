<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Notifications;

use Illuminate\Mail\Mailable;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Support\SmtpRuntimeConfiguration;
use Kanvas\Connectors\SalesAssist\Mail\LeadAdfXmlMailable;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Notifications\Notification;
use Override;

class LeadAdfXmlNotification extends Notification
{
    public function __construct(
        protected Lead $lead,
        protected Apps $app,
        protected ?string $subject,
        protected string $emailContent,
        protected string $xmlContent,
        protected string $attachmentName,
        protected bool $sendAsAttachment = false,
    ) {
        parent::__construct($lead, [
            'app' => $app,
            'company' => $lead->company,
        ]);

        $this->channels = ['mail'];
    }

    #[Override]
    public function toMail($notifiable): Mailable
    {
        $smtpConfig = new SmtpRuntimeConfiguration($this->app, $this->lead->company);
        $mailConfig = $smtpConfig->loadSmtpSettings();
        $fromMail = $smtpConfig->getFromEmail();

        $mailable = new LeadAdfXmlMailable(
            $mailConfig,
            $this->emailContent,
            $this->attachmentName,
            $this->xmlContent,
            $this->sendAsAttachment,
        );

        $mailable
            ->from($fromMail['address'], $fromMail['name'])
            ->subject($this->subject ?? 'New ADF Lead from Sales Assist');

        return $mailable;
    }
}
