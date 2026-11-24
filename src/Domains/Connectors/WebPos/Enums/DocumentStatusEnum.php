<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WebPos\Enums;

use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfStatusEnum;

/**
 * WebPOS `status` / `sts` / `dgiSts`. 8 is DGII's "aceptado condicional": the document is valid and counts,
 * but carries a system-level error to fix on the next ones.
 */
enum DocumentStatusEnum: int
{
    case PENDING = 1;
    case AUTHORIZED = 2;
    case IN_PROCESS = 3;
    case REJECTED = 4;
    case CANCELLED = 5;
    case CONDITIONALLY_ACCEPTED = 8;
    case CHECKING = 9;

    public function toEcfStatus(): EcfStatusEnum
    {
        return match ($this) {
            self::PENDING, self::IN_PROCESS, self::CHECKING => EcfStatusEnum::WAITING,
            self::AUTHORIZED => EcfStatusEnum::ACCEPTED,
            self::CONDITIONALLY_ACCEPTED => EcfStatusEnum::ACCEPTED_WITH_OBSERVATIONS,
            self::REJECTED => EcfStatusEnum::REJECTED,
            self::CANCELLED => EcfStatusEnum::CANCELLED,
        };
    }
}
