<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Enums;

enum InsuranceOrderStatusEnum: string
{
    case QUOTED = 'quoted';
    case DOCUMENTS_UPLOADED = 'documents_uploaded';
    case AWAITING_PAYMENT = 'awaiting_payment';
    case PAYMENT_REPORTED = 'payment_reported';
    case EMITTED = 'emitted';
    case POLICY_ACTIVE = 'policy_active';
    case CANCELLED = 'cancelled';
    case FAILED = 'failed';
    case EXPIRED = 'expired';

    public static function fromInsuranceStatus(InsuranceStatusEnum $status): self
    {
        return match ($status) {
            InsuranceStatusEnum::QUOTED => self::QUOTED,
            InsuranceStatusEnum::DOCUMENTS_UPLOADED => self::DOCUMENTS_UPLOADED,
            InsuranceStatusEnum::AWAITING_PAYMENT => self::AWAITING_PAYMENT,
            InsuranceStatusEnum::PAID => self::PAYMENT_REPORTED,
            InsuranceStatusEnum::EMITTED => self::EMITTED,
            InsuranceStatusEnum::POLICY_ACTIVE => self::POLICY_ACTIVE,
            InsuranceStatusEnum::CANCELLED => self::CANCELLED,
            InsuranceStatusEnum::FAILED => self::FAILED,
            InsuranceStatusEnum::EXPIRED => self::EXPIRED,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::QUOTED => 'Quoted',
            self::DOCUMENTS_UPLOADED => 'Documents uploaded',
            self::AWAITING_PAYMENT => 'Awaiting payment',
            self::PAYMENT_REPORTED => 'Payment reported',
            self::EMITTED => 'Emitted',
            self::POLICY_ACTIVE => 'Policy active',
            self::CANCELLED => 'Cancelled',
            self::FAILED => 'Failed',
            self::EXPIRED => 'Expired',
        };
    }

    public function sequence(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }

    public function isDefault(): bool
    {
        return $this === self::QUOTED;
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::POLICY_ACTIVE, self::CANCELLED, self::FAILED, self::EXPIRED], true);
    }

    public function transitionsTo(): array
    {
        return match ($this) {
            self::QUOTED => [self::DOCUMENTS_UPLOADED, self::AWAITING_PAYMENT, self::PAYMENT_REPORTED, self::POLICY_ACTIVE, self::CANCELLED, self::FAILED],
            self::DOCUMENTS_UPLOADED => [self::AWAITING_PAYMENT, self::PAYMENT_REPORTED, self::CANCELLED, self::FAILED],
            self::AWAITING_PAYMENT => [self::PAYMENT_REPORTED, self::CANCELLED, self::FAILED],
            self::PAYMENT_REPORTED => [self::EMITTED, self::POLICY_ACTIVE, self::CANCELLED, self::FAILED],
            self::EMITTED => [self::POLICY_ACTIVE, self::FAILED],
            self::POLICY_ACTIVE => [self::EXPIRED],
            self::CANCELLED, self::FAILED, self::EXPIRED => [],
        };
    }
}
