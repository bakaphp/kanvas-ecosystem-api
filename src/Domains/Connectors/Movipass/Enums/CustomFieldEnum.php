<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Enums;

enum CustomFieldEnum: string
{
    case ROADSIDE_ASSISTANCE_PIN = 'movipass_roadside_assistance_pin';
    case ROADSIDE_ASSISTANCE_PIN_HASH = 'movipass_roadside_assistance_pin_hash';
}
