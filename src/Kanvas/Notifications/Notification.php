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
use Illuminate\Notifications\Notification as LaravelNotification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Notifications\Interfaces\EmailInterfaces;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Notifications\Traits\NotificationChannelResolutionTrait;
use Kanvas\Notifications\Traits\NotificationExpoTrait;
use Kanvas\Notifications\Traits\NotificationMailTrait;
use Kanvas\Notifications\Traits\NotificationOneSignalTrait;
use Kanvas\Notifications\Traits\NotificationRenderTrait;
use Kanvas\Notifications\Traits\NotificationSmsTrait;
use Kanvas\Notifications\Traits\NotificationStorageTrait;
use Kanvas\Social\Interactions\Models\Interactions;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use NotificationChannels\Expo\ExpoChannel;
use Override;

class Notification extends LaravelNotification implements EmailInterfaces, ShouldQueue
{
    use Queueable;
    use NotificationChannelResolutionTrait;
    use NotificationStorageTrait;
    use NotificationRenderTrait;
    use NotificationMailTrait;
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
    protected array $cc = [];
    public ?array $pathAttachment = null;

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

    /**
     * @param array<int, string> $emails
     */
    public function setCc(array $emails): self
    {
        $this->cc = array_values(
            array_filter(
                $emails,
                static fn ($email): bool => is_string($email) && trim($email) !== ''
            )
        );

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getCc(): array
    {
        return $this->cc;
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
        if (! $this->isNotifiableReceivable($notifiable)) {
            return [];
        }

        $channels = $this->getNotificationChannels();

        if ($this->shouldFilterChannelsByUserSettings($notifiable)) {
            $channels = $this->filterEnabledChannels($channels, $notifiable);
        }

        $this->setNotifiableData($notifiable);

        return $channels;
    }

    /**
     * Laravel checks this per channel right before delivering, letting us drop a channel
     * whose content didn't render instead of failing the queued job inside the driver.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        if ($channel === ExpoChannel::class) {
            return $this->hasExpoContent();
        }

        return true;
    }

    private function isNotifiableReceivable(object $notifiable): bool
    {
        if (! $notifiable instanceof UserInterface) {
            return true;
        }

        // Globally removed users never receive anything for any app.
        if ($notifiable instanceof Users && $notifiable->is_deleted) {
            return false;
        }

        try {
            return $notifiable->getAppProfile($this->app)->isActive();
        } catch (ModelNotFoundException) {
            // No membership row for this app — fall back to the global flags.
            return $notifiable->isActive() && ! $notifiable->isBanned();
        }
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

    private function setNotifiableData(object $notifiable): void
    {
        $this->data['user'] = $notifiable;

        if ($notifiable instanceof UserInterface && $notifiable->getId() > 0) {
            $this->toUser = $notifiable;
        }
    }
}
