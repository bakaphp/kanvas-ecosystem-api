<?php

declare(strict_types=1);

namespace Kanvas\Notifications;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification as LaravelNotification;
use Illuminate\Support\Facades\Blade;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Channels\KanvasDatabase as KanvasDatabaseChannel;
use Kanvas\Notifications\Interfaces\EmailInterfaces;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Templates\Actions\RenderTemplateAction;
use Kanvas\Templates\Repositories\TemplatesRepository;
use Kanvas\Users\Models\Users;

class Notification extends LaravelNotification implements EmailInterfaces, ShouldQueue
{
    use Queueable;

    protected Model $entity;
    protected AppInterface $app;
    protected ?NotificationTypes $type = null;
    protected ?string $templateName = null;
    protected ?UserInterface $fromUser = null;
    protected ?UserInterface $toUser = null;

    /**
     * Set the entity
     *
     * @return void
     */
    public function __construct(Model $entity)
    {
        $this->entity = $entity;
        $this->app = app(Apps::class);
    }

    /**
     * Create a new notification channel.
     */
    public function via(): array
    {
        return [
            KanvasDatabaseChannel::class,
        ];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     */
    public function toMail($notifiable): MailMessage
    {
        $fromEmail = $this->app->get('from_email_address') ?? config('mail.from.address');
        $fromName = $this->app->get('from_email_name') ?? config('mail.from.name');
        $this->toUser = $notifiable;

        return (new MailMessage())
                ->from($fromEmail, $fromName)
                ->view('emails.layout', ['html' => $this->message()]);
    }

    /**
     * toKanvasDatabase.
     */
    public function toKanvasDatabase(UserInterface $notifiable): array
    {
        $this->toUser = $notifiable;

        try {
            $fromUserId = $this->getFromUser()->getId();
        } catch (Exception $e) {
            //for now, we need to clean this up -_-
            $fromUserId = 0;
        }

        return [
            'users_id' => $notifiable->getId(),
            'from_users_id' => $fromUserId,
            'companies_id' => $notifiable->getCurrentCompany()->getId(),
            'apps_id' => $this->app->getId(),
            'system_modules_id' => $this->getType()->system_modules_id,
            'notification_type_id' => $this->getType()->getId(),
            'entity_id' => method_exists($this->entity, 'getId') ? $this->entity->getId() : $this->entity->id,
            'content' => $this->message(),
            'read' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'is_deleted' => 0,
        ];
    }

    /**
     * Notification message the user will get.
     */
    public function message(): string
    {
        if ($this->getType()->hasEmailTemplate()) {
            return $this->getEmailTemplate();
        }

        return '';
    }

    /**
     * Given the HTML for the current email notification
     */
    protected function getEmailTemplate(): string
    {
        if (! $this->getType()->hasEmailTemplate()) {
            throw new Exception('This notification type does not have an email template');
        }

        $renderTemplate = new RenderTemplateAction($this->app);
        return $renderTemplate->execute(
            $this->getTemplateName(),
            $this->getData()
        );
    }

    /**
     * Get notification template Name
     */
    public function getTemplateName(): ?string
    {
        return $this->templateName === null ? $this->getType()->template : $this->templateName;
    }

    /**
     * getDataMail.
     */
    public function getData(): array
    {
        return [
            'entity' => $this->entity,
            'app' => $this->app,
            'user' => $this->toUser ? $this->toUser : null,
        ];
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
