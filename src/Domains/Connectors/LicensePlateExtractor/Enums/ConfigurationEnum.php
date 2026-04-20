<?php

declare(strict_types=1);

namespace Kanvas\Connectors\LicensePlateExtractor\Enums;

enum ConfigurationEnum: string
{
    case PROVIDER = 'LICENSE_PLATE_EXTRACTOR_PROVIDER';
    case MODEL = 'LICENSE_PLATE_EXTRACTOR_MODEL';
    case REGION = 'LICENSE_PLATE_EXTRACTOR_REGION';
    case MIN_CONFIDENCE = 'LICENSE_PLATE_EXTRACTOR_MIN_CONFIDENCE';
    case API_KEY = 'LICENSE_PLATE_EXTRACTOR_API_KEY';
}
