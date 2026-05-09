<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Regions\Enums;

enum ConfigurationEnum: string
{
    case REGION_COUNTRY_MAP = 'region_country_map';
    case DEFAULT_REGION_UUID = 'default_region_uuid';
}
