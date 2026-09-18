<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Enums;

use Baka\Contracts\AppInterface;

enum ParkingApplicationContractSettingEnum: string
{
    case CURRENT_VERSION = 'parking_application_contract_version';
    case DOCUMENT_URL = 'parking_application_contract_url';

    public function readFrom(AppInterface $app): ?string
    {
        $value = $app->get($this->value);

        return $value === null || $value === '' ? null : (string) $value;
    }
}
