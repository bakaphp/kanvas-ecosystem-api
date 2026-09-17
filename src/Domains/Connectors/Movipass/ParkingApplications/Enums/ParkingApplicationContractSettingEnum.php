<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\ParkingApplications\Enums;

use Baka\Contracts\AppInterface;

/**
 * The contract "catalog" is two app settings: the version currently in force and where its PDF
 * lives. History is not a table — every application carries the version it accepted and the
 * server-stamped moment it did, which is the record an audit needs. Publishing a new version is
 * changing the setting; applications that accepted the old one are held at publication until
 * the owner accepts again.
 */
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
