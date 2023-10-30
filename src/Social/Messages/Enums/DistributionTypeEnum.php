<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Enums;

enum DistributionTypeEnum: string
{
    case ALL = 'ALL';
    case Channels = 'Channels';
    case Followers = 'Followers';
}
