<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Enums;

use Kanvas\Connectors\WaSender\Traits\ReadsReceiverConfigurationTrait;
use Override;

/**
 * Burst timing and media policy. Deliberately not shape-specific: one debounce policy and one
 * media allow-list per connection, applied to group rooms and assistant DMs alike. Naming these
 * `group_*` would hide from an operator that the same knob governs their DMs.
 */
enum BurstConfigEnum: string
{
    use ReadsReceiverConfigurationTrait;

    case BURST_IDLE_SECONDS = 'burst_idle_seconds';

    /** A mention shortens the wait rather than closing the burst, so `@agent <3 photos>` survives. */
    case BURST_MENTION_IDLE_SECONDS = 'burst_mention_idle_seconds';

    /** Hard ceiling, so a conversation that never goes quiet still flushes. */
    case BURST_MAX_SECONDS = 'burst_max_seconds';

    /** Media the agent is allowed to see. Video is filed and tagged but never sent through. */
    case MEDIA_TYPES = 'media_types';

    #[Override]
    public function default(): mixed
    {
        return match ($this) {
            // 30s covers the 22s gap between an article and its photo in the capture.
            self::BURST_IDLE_SECONDS => 30,
            self::BURST_MENTION_IDLE_SECONDS => 8,
            self::BURST_MAX_SECONDS => 180,
            self::MEDIA_TYPES => [MessageTypeEnum::IMAGE->value],
        };
    }
}
