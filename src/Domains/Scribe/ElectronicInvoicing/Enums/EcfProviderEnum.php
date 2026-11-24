<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Enums;

enum EcfProviderEnum: string
{
    case WEBPOS = 'webpos';
    case ALANUBE = 'alanube';
}
