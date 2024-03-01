<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Channels\Observers;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Channels\Models\Channels;

class ChannelObserver
{
    public function creating(Channels $channel): void
    {
        $defaultChannel = $channel::getDefault($channel->company);

        // if default already exist remove its default
        if ($channel->is_default && $defaultChannel) {
            $defaultChannel->is_default = false;
            $defaultChannel->saveQuietly();
        }

        if (! $channel->is_default && ! $defaultChannel) {
            throw new ValidationException('Can\'t Save, you have to have at least one default Channel');
        }
    }

    public function updating(Channels $channel): void
    {
        $defaultChannel = Channels::getDefault($channel->company);

        // if default already exist remove its default
        if ($defaultChannel &&
            $channel->is_default &&
            $channel->getId() != $defaultChannel->getId()
        ) {
            $defaultChannel->is_default = false;
            $defaultChannel->saveQuietly();
        } elseif ($defaultChannel &&
            ! $channel->is_default &&
            $channel->getId() == $defaultChannel->getId()
        ) {
            throw new ValidationException('Can\'t Save, you have to have at least one default Channel');
        }
    }

    public function deleting(Channels $channel): void
    {
        $defaultChannel = $channel::getDefault($channel->company);

        if ($defaultChannel->getId() == $channel->getId()) {
            throw new ValidationException('Can\'t delete, you have to have at least one default Channel');
        }
    }
}
