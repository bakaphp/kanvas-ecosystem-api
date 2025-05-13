<?php
declare(strict_types=1);
namespace Kanvas\Connectors\DriveCentric\Enums;

enum ConfigurationEnum: string{
    case BASE_URL = 'drivec_entric_base_url';
    case API_KEY = 'drivec_entric_api_key';
    case API_SECRET_KEY = 'drivec_entric_api_secret_key';
    case STORE_ID = 'drivec_entric_store_id';
}