<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Google\Enums;

enum ConfigurationEnum: string
{
    case GOOGLE_CLIENT_CONFIG = 'google-client-config';
    case GOOGLE_RECOMMENDATION_CONFIG = 'google-recommendation-config';
}
