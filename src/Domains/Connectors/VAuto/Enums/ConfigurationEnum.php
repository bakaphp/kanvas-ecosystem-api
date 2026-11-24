<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VAuto\Enums;

/**
 * Company-level settings. The key names are the ones the legacy Gewaer integration stored, so a
 * dealer configured there keeps working without re-entering credentials.
 */
enum ConfigurationEnum: string
{
    case ACCESS_ID = 'VAUTO_ACCESS_ID';
    case SECRET_KEY = 'VAUTO_SECRET_KEY';
    case ENTITY_ID = 'VAUTO_COMPANY_ID';
    case USER_NAME = 'VAUTO_USER_NAME';
    case WSDL_URL = 'VAUTO_WSDL_URL';
}
