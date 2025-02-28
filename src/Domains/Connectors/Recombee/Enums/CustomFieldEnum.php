<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Enums;

enum CustomFieldEnum: string
{
    case USER_FOR_YOU_FEED_RECOMM_ID = 'recombee-for-you-feed-id';
    case USER_WHO_TO_FOLLOW_RECOMM_ID = 'recombee-who-to-follow-id';
}
