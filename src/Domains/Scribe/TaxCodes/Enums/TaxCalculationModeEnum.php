<?php

declare(strict_types=1);

namespace Kanvas\Scribe\TaxCodes\Enums;

enum TaxCalculationModeEnum: string
{
    case EXCLUSIVE = 'exclusive';
    case INCLUSIVE = 'inclusive';
}
