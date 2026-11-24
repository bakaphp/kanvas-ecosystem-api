<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WebPos\Enums;

/**
 * `sendRecEvent` event codes, which are also the `sts` a received document carries in `getRecDoc`
 * (1 means no commercial response sent yet).
 */
enum CommercialResponseEnum: int
{
    case APPROVE = 2;
    case REJECT = 4;
}
