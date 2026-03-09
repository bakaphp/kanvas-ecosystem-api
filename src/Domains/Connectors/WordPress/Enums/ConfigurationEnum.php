<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WordPress\Enums;

enum ConfigurationEnum: string
{
    case NAME = 'WordPress';
    case DEALERS = 'wordpress_inventory_dealers';
}
