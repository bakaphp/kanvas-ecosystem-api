<?php

declare(strict_types=1);

namespace Kanvas\Guild\Traits;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;
use Kanvas\Social\Channels\Models\Channel;

/**
 * A `notes` thread on an entity, backed by a Social channel. `channels.entity_id` is a varchar, hence
 * the string_id accessor the relations join on.
 *
 * `self::class` inside a trait resolves to the using model, so each one scopes to its own namespace.
 */
trait HasNotesChannelTrait
{
    public function getStringIdAttribute(): string
    {
        return (string) $this->id;
    }

    public function socialChannels(): HasMany
    {
        return $this->hasMany(Channel::class, 'entity_id', 'string_id')
            ->where('entity_namespace', self::class)
            ->where('is_deleted', 0);
    }

    public function notes(): HasOne
    {
        return $this->hasOne(Channel::class, 'entity_id', 'string_id')
            ->where('entity_namespace', self::class)
            ->where('name', ChannelNameEnum::NOTES->value)
            ->where('is_deleted', 0);
    }
}
