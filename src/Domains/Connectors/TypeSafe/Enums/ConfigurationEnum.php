<?php

declare(strict_types=1);

namespace Kanvas\Connectors\TypeSafe\Enums;

enum ConfigurationEnum: string
{
    case TYPESAFE_API_KEY = 'TYPESAFE_API_KEY';
    case TYPESAFE_MODEL = 'TYPESAFE_MODEL';
    case TYPESAFE_DECISIONS = 'TYPESAFE_DECISIONS';
}
