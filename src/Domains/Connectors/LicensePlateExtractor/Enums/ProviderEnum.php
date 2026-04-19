<?php

declare(strict_types=1);

namespace Kanvas\Connectors\LicensePlateExtractor\Enums;

enum ProviderEnum: string
{
    case GEMINI = 'gemini';
    case PLATE_RECOGNIZER = 'plate_recognizer';
}
