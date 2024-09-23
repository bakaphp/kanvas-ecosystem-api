<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Models\BaseModel;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Templates\Models\Templates;

/**
 * NotificationTypes Model.
 *
 * @property int $apps_id
 * @property int $system_modules_id
 * @property int $notification_channel_id
 * @property string $name
 * @property string $key
 * @property string $description
 * @property string|null title
 * @property string $template
 * @property string $icon_url
 * @property int $with_realtime
 * @property int $parent_id
 * @property float $is_published
 */
class NotificationTypes extends BaseModel
{
    // use Cachable;

    public $table = 'notification_types';

    public $fillable = [
        'apps_id',
        'system_modules_id',
        'name',
        'key',
        'description',
        'template',
        'icon_url',
        'with_realtime',
        'parent_id',
        'is_published',
    ];

    public function systemModule(): BelongsTo
    {
        return $this->belongsTo(SystemModules::class, 'system_modules_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class, 'notification_channel_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Templates::class, 'template_id', 'id');
    }

    public function channels(): HasMany
    {
        return $this->hasMany(NotificationTypeChannel::class, 'notification_type_id');
    }

    public function getChannelsInNotificationFormat(): array
    {
        $channels = [];

        foreach ($this->channels as $channel) {
            $channels[] = NotificationChannelEnum::getNotificationChannelBySlug($channel->channel->slug);
        }

        return $channels;
    }

    public function getPushTemplateName(): string
    {
        $pushNotificationTemplate = $this->channels()->where('notification_channel_id', NotificationChannelEnum::PUSH->value)->first();

        // if the notification type does not have a push template, we return the default one
        if (! $pushNotificationTemplate) {
            return 'new-push-default';
        }

        $templateName = $pushNotificationTemplate->template()->exists() ? $pushNotificationTemplate->template()->first()->name : null;

        if (empty($templateName)) {
            throw new ModelNotFoundException('This notification type does not have an push template');
        }

        return $templateName;
    }

    /**
     * Verify this notification type uses email template.
     */
    public function hasEmailTemplate(): bool
    {
        $templateName = $this->template()->exists() ? $this->template()->first()->name : $this->template;

        return ! empty($templateName);
    }

    public function getTemplateName(): string
    {
        $templateName = $this->template()->exists() ? $this->template()->first()->name : $this->template;

        if (empty($templateName)) {
            throw new ModelNotFoundException('This notification type does not have an email template');
        }

        return $templateName;
    }

    public function getNotificationChannels(): array
    {
        return [
            'mail',
        ];
    }

    public function assignChannel(NotificationChannel $channel, Templates $template)
    {
        $this->channels()->firstOrCreate([
            'notification_channel_id' => $channel->getId(),
        ], [
            'template_id' => $template->getId(),
        ]);
    }
}
