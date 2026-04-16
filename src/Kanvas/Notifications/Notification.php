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
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Notifications\Interfaces\EmailInterfaces;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Notifications\Traits\NotificationExpoTrait;
use Kanvas\Notifications\Traits\NotificationOneSignalTrait;
use Kanvas\Notifications\Traits\NotificationRenderTrait;
use Kanvas\Notifications\Traits\NotificationSmsTrait;
use Kanvas\Notifications\Traits\NotificationStorageTrait;
use Kanvas\Social\Interactions\Models\Interactions;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use Override;

class Notification extends LaravelNotification implements EmailInterfaces, ShouldQueue
{
    use Queueable;
    use NotificationStorageTrait;
    use NotificationRenderTrait;
    use NotificationOneSignalTrait;
    use NotificationExpoTrait;
    use NotificationSmsTrait;

    protected Model $entity;
    protected Apps $app;
    protected ?string $subject = null;
    protected ?NotificationTypes $type = null;
    protected ?Interactions $interaction = null;
    protected ?UserInterface $fromUser = null;
    protected ?UserInterface $toUser = null;
    protected ?CompanyInterface $company = null;
    public ?array $pathAttachment = null;

    public array $channels = ['mail'];

    public function __construct(
        Model|NotificationTypes $entity,
        array $options = []
    ) {
        $this->onQueue('notifications');
        $this->entity = $entity;
        $this->app = $this->resolveApp($entity, $options);

        $this->data = [
            'entity' => $this->entity,
            'app' => $this->app,
            'options' => $options,
        ];

        $this->configureFromOptions($options);
    }

    public function setSubject(?string $subject = null): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function channels(): array
    {
        return $this->channels;
    }

    /**
     * Set notification channels using slugs (mail, email, push, expo, sms, database, twilio).
     * Validates each slug against NotificationChannelEnum.
     */
    public function setChannels(string ...$channels): self
    {
        foreach ($channels as $channel) {
            NotificationChannelEnum::getNotificationChannelBySlug($channel);
        }

        $this->channels = $channels;

        return $this;
    }

    /**
     * Determine which channels the notification should be delivered on.
     *
     * Resolves slug-based channels (e.g. 'sms', 'push') to their class implementations,
     * then filters out any channels the user has disabled in their notification settings.
     *
     * @return array<array-key, mixed> Resolved channel class names (e.g. TwilioSmsChannel::class)
     */
    public function via(object $notifiable): array
    {
        $channels = $this->getNotificationChannels();

        if ($this->shouldFilterChannelsByUserSettings($notifiable)) {
            $channels = $this->filterEnabledChannels($channels, $notifiable);
        }

        $this->setNotifiableData($notifiable);

        return $channels;
    }

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

    /**
     * Set or create the NotificationType record for this notification.
     * This controls per-user notification settings (enable/disable per channel)
     * and is used by filterEnabledChannels() to respect user preferences.
     */
    public function setType(string $type): void
    {
        $this->type = NotificationTypes::firstOrCreate([
            'apps_id' => $this->app->getId(),
            'name' => $type,
            'is_deleted' => 0,
        ], [
            'key' => $type,
            'template' => $type,
            'system_modules_id' => SystemModulesRepository::getByModelName(static::class, $this->app)->getId(),
        ]);
    }

    #[Override]
    public function getType(): NotificationTypes
    {
        return $this->type ??= NotificationTypes::firstOrCreate([
            'apps_id' => $this->app->getId(),
            'key' => static::class,
            'name' => Str::simpleSlug(static::class),
            'system_modules_id' => SystemModulesRepository::getByModelName(static::class, $this->app)->getId(),
            'is_deleted' => 0,
        ], [
            'template' => $this->templateName ?? null,
        ]);
    }

    public function setFromUser(UserInterface $user): void
    {
        $this->fromUser = $user;
        $this->data['fromUser'] = $user;
    }

    public function getFromUser(): UserInterface
    {
        if ($this->fromUser) {
            return $this->fromUser;
        }

        $defaultUserId = $this->app->get(AppSettingsEnums::NOTIFICATION_FROM_USER_ID->getValue());

        if (! $defaultUserId) {
            throw new ValidationException('Please contact admin to configure the notification_from_user_id');
        }

        return Users::getById($defaultUserId);
    }

    private function resolveApp(Model $entity, array $options): AppInterface
    {
        return $entity->app
            ?? (($options['app'] ?? null) instanceof AppInterface ? $options['app'] : app(Apps::class));
    }

    private function configureFromOptions(array $options): void
    {
        if (($options['fromUser'] ?? null) instanceof UserInterface) {
            $this->setFromUser($options['fromUser']);
        }

        if (isset($this->templateName) && isset($options['template'])) {
            $this->templateName = (string) $options['template'];
        }

        $this->company = ($options['company'] ?? null) instanceof CompanyInterface
            ? $options['company']
            : null;

        $this->subject = $options['subject'] ?? null;
    }

    /**
     * Resolve channel slugs ('mail', 'sms', 'push', etc.) to their Laravel channel
     * class implementations (e.g. TwilioSmsChannel::class). This is the single
     * resolution point — subclasses and callers should set $channels with slugs,
     * and this method handles the mapping via NotificationChannelEnum.
     */
    private function getNotificationChannels(): array
    {
        return array_map(
            fn ($via): string => NotificationChannelEnum::getNotificationChannelBySlug($via),
            $this->channels()
        );
    }

    /**
     * Only filter channels by user preferences when: we have channels to send,
     * a NotificationType is set (so we can look up settings), and the recipient is a user.
     */
    private function shouldFilterChannelsByUserSettings(object $notifiable): bool
    {
        return ! empty($this->getNotificationChannels())
            && $this->type instanceof NotificationTypes
            && $notifiable instanceof UserInterface;
    }

    /**
     * Remove channels the user has explicitly disabled in their per-notification-type settings.
     * If no settings exist for this notification type, all channels are kept (enabled by default).
     */
    private function filterEnabledChannels(array $channels, UserInterface $notifiable): array
    {
        $enabledChannels = array_filter($channels, function ($channel) use ($notifiable) {
            return $notifiable->isNotificationSettingEnable(
                $this->type,
                $this->app,
                NotificationChannelEnum::getChannelIdByClassReference($channel)
            );
        });

        return array_values($enabledChannels);
    }

    private function setNotifiableData(object $notifiable): void
    {
        $this->data['user'] = $notifiable;

        if ($notifiable instanceof UserInterface && $notifiable->getId() > 0) {
            $this->toUser = $notifiable;
        }
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

    /**
     * Link this notification to a social interaction (e.g. 'follow', 'new_message').
     * Used by NotificationStorageTrait to store the interaction reference in the DB.
     * Silently ignores unknown interaction names.
     */
    public function setInteraction(string $name): void
    {
        try {
            $this->interaction = Interactions::getByName($name, $this->app);
        } catch (ModelNotFoundException $e) {
        }
    }
}
