<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OfferLogix\Enums;

enum ConfigurationEnum: string
{
    case COMPANY_SOURCE_ID = 'OFFERLOGIX_COMPANY_SOURCE_ID';
    case ACTION_VERB = 'soft-pull';
}
