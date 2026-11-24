<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Alanube\Enums;

use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfStatusEnum;

/**
 * Alanube's lifecycle `status` plus the DGII `legalStatus`, which is only meaningful once FINISHED.
 */
enum DocumentStatusEnum: string
{
    case REGISTERED = 'REGISTERED';
    case TO_SEND = 'TO_SEND';
    case WAITING_RESPONSE = 'WAITING_RESPONSE';
    case TO_NOTIFY = 'TO_NOTIFY';
    case FINISHED = 'FINISHED';

    /**
     * FINISHED with no legal status has not happened in the docs, but treating it as final would close an
     * invoice DGII never ruled on, so it stays WAITING and the poll asks again.
     */
    public static function toCanonical(?string $status, ?string $legalStatus): EcfStatusEnum
    {
        if (self::tryFrom((string) $status) !== self::FINISHED) {
            return EcfStatusEnum::WAITING;
        }

        return match ($legalStatus) {
            'ACCEPTED' => EcfStatusEnum::ACCEPTED,
            'ACCEPTED_WITH_OBSERVATIONS' => EcfStatusEnum::ACCEPTED_WITH_OBSERVATIONS,
            'REJECTED' => EcfStatusEnum::REJECTED,
            default => EcfStatusEnum::WAITING,
        };
    }
}
