<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Enums;

enum PeopleCustomFieldEnum: string
{
    case DRIVERS_LICENSE = 'get_docs_drivers_license';
    case DRIVERS_LICENSE_IMAGE = 'driver_license_images';
    case CREDIT_APP = 'credit_app';
}
