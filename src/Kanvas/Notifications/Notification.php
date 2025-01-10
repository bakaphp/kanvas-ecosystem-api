<?php

declare(strict_types=1);

namespace Kanvas\Notifications;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification as LaravelNotification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Support\SmtpRuntimeConfiguration;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Notifications\Channels\KanvasDatabase as KanvasDatabaseChannel;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Notifications\Interfaces\EmailInterfaces;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Notifications\Traits\NotificationOneSignalTrait;
use Kanvas\Notifications\Traits\NotificationRenderTrait;
use Kanvas\Notifications\Traits\NotificationStorageTrait;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;

class Notification extends LaravelNotification implements EmailInterfaces, ShouldQueue
{
    use Queueable;
    use NotificationStorageTrait;
    use NotificationRenderTrait;
    use NotificationOneSignalTrait;

    protected Model $entity;
    protected AppInterface $app;
    protected ?string $subject = null;
    protected ?NotificationTypes $type = null;
    protected ?UserInterface $fromUser = null;
    protected ?UserInterface $toUser = null;
    protected ?CompanyInterface $company = null;
    public ?array $pathAttachment = null;

    public array $channels = [
        'mail',
    ];

    public function __construct(Model|NotificationTypes $entity, array $options = [])
    {
        $this->onQueue('notifications');
        $this->entity = $entity;

        $this->app = $entity->app ?? (($options['app'] ?? null) instanceof AppInterface ? $options['app'] : app(Apps::class));

        $this->data = [
            'entity' => $this->entity,
            'app' => $this->app,
            'options' => $options,
        ];

        $this->handleFromUserOption($options);
        /**
         * @psalm-suppress MixedAssignment
         */
        $this->subject = $options['subject'] ?? null;
    }

    public function setSubject(?string $subject = null): self
    {
        $this->subject = $subject;

        return $this;
    }

    /**
     * Notification via channels
     */
    public function channels(): array
    {
        return $this->channels;
    }

    /**
     * @psalm-suppress MixedAssignment
     */
    protected function handleFromUserOption(array $options): void
    {
        $options = collect($options);

        if ($options->get('fromUser') instanceof UserInterface) {
            $this->setFromUser($options['fromUser']);
        }

        if (isset($this->templateName)) {
            $this->templateName = optional($options->get('template'), function ($template) {
                return (string) $template;
            });
        }

        $this->company = $options->get('company') instanceof CompanyInterface ? $options['company'] : null;
    }

    /**
     * Create a new notification channel.
     *
     * @return array<array-key, mixed>
     */
    public function via(object $notifiable): array
    {
        $notificationTypeChannels = $this->type instanceof NotificationTypes ? $this->type->getChannelsInNotificationFormat() : [];
        $channels = ! empty($notificationTypeChannels) ? $notificationTypeChannels : $this->channels();
        if (! empty($channels) && $this->type instanceof NotificationTypes && $notifiable instanceof UserInterface) {
            /**
             * @psalm-suppress MissingClosureReturnType
             */
            $enabledChannels = array_filter($channels, function ($channel) use ($notifiable) {
                return $notifiable->isNotificationSettingEnable(
                    $this->type,
                    $this->app,
                    NotificationChannelEnum::getChannelIdByClassReference($channel)
                );
            });
            $channels = array_values($enabledChannels);
        }

        //set the user
        $this->data['user'] = $notifiable;
        if ($notifiable instanceof UserInterface && $notifiable->getId() > 0) {
            $this->toUser = $notifiable; //we do this validation because user invite temp user deserialize the user
        }

        /* return [
             KanvasDatabaseChannel::class,
             ...$channels,
        ]; */
        return $channels;
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     */
    public function toMail($notifiable): Mailable
    {
        $smtpConfiguration = new SmtpRuntimeConfiguration($this->app, $this->company);
        $mailConfig = $smtpConfiguration->loadSmtpSettings();
        $fromMail = $smtpConfiguration->getFromEmail();

        $fromEmail = $fromMail['address'];
        $fromName = $fromMail['name'];

        $toEmail = $notifiable instanceof AnonymousNotifiable ? $notifiable->routes['mail'] : $notifiable->email;
        $mailMessage = (new KanvasMailable($mailConfig, $this->getEmailContent()))
                ->from($fromEmail, $fromName)
                ->to($toEmail);

        $this->subject = $this->subject ?? $this->getNotificationTitle();

        if ($this->subject) {
            $mailMessage->subject($this->subject);
        }

        if (isset($this->pathAttachment) && $this->pathAttachment !== null) {
            $mailMessage->attachMany($this->pathAttachment);
        }

        return $mailMessage;
    }

    /**
     * setType.
     */
    public function setType(string $type): void
    {
        $this->type = NotificationTypes::where('apps_id', $this->app->getId())
            ->where('name', $type)
            ->firstOrFail();
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
            'key' => static::class,
            'name' => Str::simpleSlug(static::class),
            'system_modules_id' => SystemModulesRepository::getByModelName(static::class, $this->app)->getId(),
            'is_deleted' => 0,
        ], [
            'template' => $this->templateName ?? null,
        ]);
    }

    /**
     * Set the user who is sending the notification
     */
    public function setFromUser(UserInterface $user): void
    {
        $this->fromUser = $user;
        $this->data['fromUser'] = $user;
    }

    /**
     * Get the user who is sending the notification
     */
    public function getFromUser(): UserInterface
    {
        if ($this->fromUser === null && ! $this->app->get(AppSettingsEnums::NOTIFICATION_FROM_USER_ID->getValue())) {
            throw new ValidationException('Please contact admin to configure the notification_from_user_id');
        }

        return $this->fromUser !== null
                ? $this->fromUser
                : Users::getById($this->app->get(AppSettingsEnums::NOTIFICATION_FROM_USER_ID->getValue()));
    }
}
