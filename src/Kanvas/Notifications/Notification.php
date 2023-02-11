<?php
declare(strict_types=1);

namespace Kanvas\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification as LaravelNotification;
use Illuminate\Support\Facades\Blade;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Channels\KanvasDatabase as KanvasDatabaseChannel;
use Kanvas\Notifications\Interfaces\EmailInterfaces;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Templates\Repositories\TemplatesRepository;

class Notification extends LaravelNotification implements EmailInterfaces
{
    use Queueable;

    public object $entity;
    public object $type;
    public string $templateName = 'default';

    /**
     * Create a new notification channel.
     *
     * @return array
     */
    public function via() : array
    {
        return [
            KanvasDatabaseChannel::class
        ];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     *
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable) : MailMessage
    {
        return (new MailMessage)
                ->from(config('mail.from.address'), config('mail.from.name'))
                ->view('emails.layout', ['html' => $this->message()]);
    }

    /**
     * toKanvasDatabase.
     *
     * @param object $notifiable
     *
     * @return array
     */
    public function toKanvasDatabase(object $notifiable) : array
    {
        return [
            'users_id' => $notifiable->id ?? auth()->user()->id,
            'from_users_id' => auth()->user()->id ?? $notifiable->id,
            'companies_id' => $notifiable->default_company ?? auth()->user()->defaultCompany->id,
            'apps_id' => app(Apps::class)->id,
            'system_modules_id' => $this->type->system_modules_id,
            'notification_type_id' => $this->type->id,
            'entity_id' => $this->entity->id,
            'content' => $this->message(),
            'read' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'is_deleted' => 0,
        ];
    }

    /**
     * generateHtml.
     *
     * @return string
     */
    public function message() : string
    {
        return Blade::render(
            TemplatesRepository::getByName($this->templateName)->template,
            $this->getData(),
            true
        );
    }

    /**
     * getDataMail.
     *
     * @return array
     */
    public function getData() : array
    {
        return [
        ];
    }

    /**
     * setType.
     *
     * @param string $type
     *
     * @return void
     */
    public function setType(string $type) : void
    {
        $this->type = NotificationTypes::getByName($type);
    }
}
