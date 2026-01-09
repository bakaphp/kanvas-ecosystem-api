<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Enums;

use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;

enum ConfigurationEnum: string
{
    case BASE_URL = 'drive_centric_base_url';
    case API_KEY = 'drive_centric_api_key';
    case API_SECRET_KEY = 'drive_centric_api_secret_key';
    case STORE_ID = 'drive_centric_store_id';
    case DEFAULT_SOURCE_TYPE = 'drive_centric_default_source_type';
    case DEFAULT_SOURCE_DESCRIPTION = 'drive_centric_default_source_description';
    case USER = 'drive_centric_user';

    public static function getUserKey(Companies $company, Users $user): string
    {
        return self::USER->value . '_' . $company->getId() . '_' . $user->getId();
    }
}
