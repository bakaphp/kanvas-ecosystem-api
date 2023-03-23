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
    public array $data = [];
    public array $via = [
        KanvasDatabaseChannel::class,
    ];

    /**
     * setVia
     */
    public function setVia(array $via): self
    {
        $this->via = array_merge($via, KanvasDatabaseChannel::class);

        return $this;
    }

    /**
     * Create a new notification channel.
     */
    public function via(): array
    {
        return $this->via;
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     */
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage())
                ->from(config('mail.from.address'), config('mail.from.name'))
                ->view('emails.layout', ['html' => $this->message()]);
    }

    /**
     * toKanvasDatabase.
     */
    public function toKanvasDatabase(object $notifiable): array
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
     */
    public function message(): string
    {
        return Blade::render(
            TemplatesRepository::getByName($this->templateName)->template,
            $this->getData()
        );
    }

    /**
     * setTemplateName
     *
     * @param  mixed $name
     */
    public function setTemplateName(string $name): self
    {
        $this->templateName = $name;

        return $this;
    }

    /**
     * setData
     */
    public function setData(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    /**
     * getData.
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * setType.
     */
    public function setType(string $type): void
    {
        $this->type = NotificationTypes::getByName($type);
    }
}
