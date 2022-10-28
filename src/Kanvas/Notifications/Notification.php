<?php
namespace Kanvas\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification as LaravelNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Kanvas\Users\Users\Models\Users;
use Kanvas\Templates\Models\Templates;
use Illuminate\Support\Facades\Blade;
use Kanvas\Notifications\Interfaces\EmailInterfaces;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Channels\KanvasDatabase as KanvasDatabaseChannel;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Templates\Repositories\TemplatesRepository;
use Illuminate\Support\Facades\Log;

class Notification extends LaravelNotification implements EmailInterfaces
{
    public object $entity;
    public object $type;

    public function failed(Exception $exception)
    {
        Log::debug('MyNotification failed');
    }

    /**
     * via
     *
     * @return array
     */
    public function via(): array
    {
        return [KanvasDatabaseChannel::class];
    }

    /**
    * Get the mail representation of the notification.
    *
    * @param  mixed  $notifiable
    * @return \Illuminate\Notifications\Messages\MailMessage
    */
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
                ->from(config('mail.from.address'), config('mail.from.name'))
                ->view('emails.layout', ['html' => $this->message()]);
    }

    /**
     * toKanvasDatabase
     *
     * @param  mixed $notifiable
     * @return void
     */
    public function toKanvasDatabase($notifiable)
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
     * generateHtml
     *
     * @return string
     */
    public function message(): string
    {
        $template = new Templates();
        $html = Blade::render(TemplatesRepository::getByName($this->templateName)->template, $this->getData());
        return $html;
    }

    /**
     * getDataMail
     *
     * @return array
     */
    public function getData(): array
    {
        return [
        ];
    }

    /**
     * setType
     *
     * @param  string $type
     * @return void
     */
    public function setType(string $type): void
    {
        $this->type = NotificationTypes::getByName($type);
    }
}
