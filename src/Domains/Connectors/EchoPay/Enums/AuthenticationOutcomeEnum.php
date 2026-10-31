<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\Enums;

use Kanvas\Connectors\EchoPay\DataTransferObject\ConsumerAuthentication;

enum AuthenticationOutcomeEnum: string
{
    case CHALLENGE = 'challenge';
    case ECI_NOT_ACCEPTED = 'eci_not_accepted';
    case ATTEMPTED = 'attempted';
    case UNAVAILABLE = 'unavailable';
    case FAILED = 'failed';
    case UNKNOWN = 'unknown';

    public static function resolve(
        string $status,
        ConsumerAuthentication $consumerData,
        ?CardBrandEnum $brand
    ): self {
        $gatewayStatus = PaymentStatusEnum::tryFrom($status);
        $paResStatus = PaResStatusEnum::tryFrom((string) $consumerData->paresStatus);

        return match (true) {
            $gatewayStatus === null => self::UNKNOWN,
            $gatewayStatus === PaymentStatusEnum::AUTHENTICATION_FAILED => self::FAILED,
            $gatewayStatus === PaymentStatusEnum::PENDING_AUTHENTICATION => self::CHALLENGE,
            $paResStatus?->isFailure() === true => self::FAILED,
            $paResStatus === PaResStatusEnum::ATTEMPTED,
            CardBrandEnum::isAttemptedEci($consumerData->resolvedEci(), $brand) => self::ATTEMPTED,
            $paResStatus === PaResStatusEnum::UNAVAILABLE,
            ($consumerData->resolvedEci() ?? '') === '' => self::UNAVAILABLE,
            default => self::ECI_NOT_ACCEPTED,
        };
    }

    public function isFailure(): bool
    {
        return $this === self::FAILED || $this === self::UNKNOWN;
    }

    public function isInconclusive(): bool
    {
        return $this === self::ATTEMPTED || $this === self::UNAVAILABLE;
    }
}
