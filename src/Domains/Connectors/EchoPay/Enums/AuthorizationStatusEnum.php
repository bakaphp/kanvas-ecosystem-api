<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\Enums;

enum AuthorizationStatusEnum: string
{
    case AUTHORIZED = 'AUTHORIZED';
    case PARTIAL_AUTHORIZED = 'PARTIAL_AUTHORIZED';
    case AUTHORIZED_PENDING_REVIEW = 'AUTHORIZED_PENDING_REVIEW';
    case AUTHORIZED_RISK_DECLINED = 'AUTHORIZED_RISK_DECLINED';
    case DECLINED = 'DECLINED';
    case INVALID_REQUEST = 'INVALID_REQUEST';

    public function holdsFunds(): bool
    {
        return match ($this) {
            self::PARTIAL_AUTHORIZED,
            self::AUTHORIZED_PENDING_REVIEW,
            self::AUTHORIZED_RISK_DECLINED => true,
            default => false,
        };
    }

    public function failureCode(): ?string
    {
        return match ($this) {
            self::AUTHORIZED => null,
            self::PARTIAL_AUTHORIZED => 'PARTIAL_AUTHORIZATION',
            self::AUTHORIZED_PENDING_REVIEW => 'PENDING_REVIEW',
            self::AUTHORIZED_RISK_DECLINED => 'RISK_DECLINED',
            self::DECLINED => 'DECLINED',
            self::INVALID_REQUEST => 'INVALID_REQUEST',
        };
    }

    public function failureMessage(): ?string
    {
        return match ($this) {
            self::AUTHORIZED => null,
            self::PARTIAL_AUTHORIZED => 'The gateway authorized less than the order total.',
            self::AUTHORIZED_PENDING_REVIEW => 'The gateway authorized the payment but flagged it for manual review.',
            self::AUTHORIZED_RISK_DECLINED => 'The gateway authorized the payment but its risk rules declined it.',
            self::DECLINED => 'The gateway declined the authorization.',
            self::INVALID_REQUEST => 'The gateway rejected the authorization request as invalid.',
        };
    }
}
