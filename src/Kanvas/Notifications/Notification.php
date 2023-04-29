<?php

declare(strict_types=1);

namespace Kanvas\Notifications;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification as LaravelNotification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Channels\KanvasDatabase as KanvasDatabaseChannel;
use Kanvas\Notifications\Interfaces\EmailInterfaces;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Notifications\Traits\NotificationRenderTrait;
use Kanvas\Notifications\Traits\NotificationStorageTrait;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;

class Notification extends LaravelNotification implements EmailInterfaces, ShouldQueue
{
    use Queueable;
    use NotificationStorageTrait;
    use NotificationRenderTrait;

    protected Model $entity;
    protected AppInterface $app;
    protected ?NotificationTypes $type = null;
    protected ?UserInterface $fromUser = null;
    protected ?UserInterface $toUser = null;

    public array $channels = [
        'mail'
    ];

    public function __construct(Model $entity)
    {
        $this->entity = $entity;
        $this->app = app(Apps::class);
        $this->data = [
            'entity' => $this->entity,
            'app' => $this->app,
            'user' => $this->toUser ? $this->toUser : null,
        ];
    }

    /**
     * Notification via channels
     */
    public function channels(): array
    {
        return $this->channels;
    }

    /**
     * Create a new notification channel.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = $this->channels();

        if (! empty($channels) && $this->type instanceof NotificationTypes) {
            $enabledChannels = array_filter($channels, function ($channel) use ($notifiable) {
                return $notifiable->isNotificationSettingEnable($this->type, $channel);
            });
            $channels = array_values($enabledChannels);
        }

        return [
             KanvasDatabaseChannel::class,
             ...$channels,
        ];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     */
    public function toMail($notifiable): ?MailMessage
    {
        $fromEmail = $this->app->get('from_email_address') ?? config('mail.from.address');
        $fromName = $this->app->get('from_email_name') ?? config('mail.from.name');
        $this->toUser = $notifiable;

        return (new MailMessage())
                ->from($fromEmail, $fromName)
                ->view('emails.layout', ['html' => $this->message()]);
    }

    /**
     * setType.
     */
    public function setType(string $type): void
    {
        $this->type = NotificationTypes::getByName($type);
    }

    /**
     * Get the notification type
     */
    public function getType(): NotificationTypes
    {
        if ($this->type !== null) {
            return $this->type;
        }

        /**
         * @var NotificationTypes
         */
        return NotificationTypes::firstOrCreate([
            'apps_id' => $this->app->getId(),
            'key' => self::class,
            'name' => Str::slug(self::class),
            'system_modules_id' => SystemModulesRepository::getByModelName(self::class, $this->app)->getId(),
            'is_deleted' => 0,
        ]);
    }

    /**
     * Set the user who is sending the notification
     */
    public function setFromUser(UserInterface $user): void
    {
        $this->fromUser = $user;
    }

    /**
     * Get the user who is sending the notification
     */
    public function getFromUser(): UserInterface
    {
        return $this->fromUser !== null
                ? $this->fromUser
                : Users::getById($this->app->get('notification_from_user_id'));
    }
}
