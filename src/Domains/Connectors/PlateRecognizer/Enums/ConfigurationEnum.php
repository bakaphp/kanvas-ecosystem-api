<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PlateRecognizer\Enums;

enum ConfigurationEnum: string
{
    case API_KEY = 'PLATE_RECOGNIZER_API_KEY';
    case APP_CAR_REGION = 'PLATE_RECOGNIZER_APP_CAR_REGION';
}
