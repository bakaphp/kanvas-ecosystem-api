<?php

declare(strict_types=1);

namespace Kanvas\Insurance\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\DateHelper;
use Baka\Support\Str;
use Kanvas\Insurance\Exceptions\InsuranceValidationException;
use Kanvas\Insurance\Services\InsuranceVehicleProduct;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Users\Models\Users;

class RegisteredInsurancePolicy
{
    private const int MAX_NAME_LENGTH = 150;

    private const int MAX_POLICY_NUMBER_LENGTH = 100;

    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly Users $user,
        public readonly Variants $vehicle,
        public readonly string $insurerName,
        public readonly string $planName,
        public readonly string $policyNumber,
        public readonly ?string $startsAt,
        public readonly string $endsAt,
    ) {
    }

    public static function fromMultiple(
        AppInterface $app,
        Users $user,
        CompanyInterface $company,
        array $data
    ): self {
        $startsAt = Str::trimToNull((string) ($data['starts_at'] ?? ''));
        $endsAt = Str::trimToNull((string) ($data['ends_at'] ?? ''));

        self::assertDates($startsAt, $endsAt);

        $policyNumber = self::text($data['policy_number'] ?? null, 'The policy number', self::MAX_POLICY_NUMBER_LENGTH);

        if (self::normalize($policyNumber) === '') {
            throw new InsuranceValidationException('The policy number is not valid');
        }

        return new self(
            app: $app,
            company: $company,
            user: $user,
            vehicle: InsuranceVehicleProduct::variantOwnedBy(
                (int) ($data['vehicle_variant_id'] ?? 0),
                $app,
                $user->getId()
            ),
            insurerName: self::text($data['insurer_name'] ?? null, 'The insurer name', self::MAX_NAME_LENGTH),
            planName: self::text($data['plan_name'] ?? null, 'The plan name', self::MAX_NAME_LENGTH),
            policyNumber: $policyNumber,
            startsAt: $startsAt,
            endsAt: $endsAt,
        );
    }

    public function normalizedPolicyNumber(): string
    {
        return self::normalize($this->policyNumber);
    }

    private static function normalize(string $policyNumber): string
    {
        return Str::lower((string) preg_replace('/[^A-Za-z0-9]/', '', $policyNumber));
    }

    private static function text(mixed $value, string $label, int $maxLength): string
    {
        $text = Str::trimToNull((string) $value)
            ?? throw new InsuranceValidationException($label . ' is required');

        if (mb_strlen($text) > $maxLength) {
            throw new InsuranceValidationException($label . ' is too long');
        }

        return $text;
    }

    private static function assertDates(?string $startsAt, ?string $endsAt): void
    {
        if ($endsAt === null) {
            throw new InsuranceValidationException('The policy end date is required');
        }

        $end = DateHelper::parseYmd($endsAt)
            ?? throw new InsuranceValidationException('The policy end date must be a valid date formatted YYYY-MM-DD');

        if ($startsAt === null) {
            return;
        }

        $start = DateHelper::parseYmd($startsAt)
            ?? throw new InsuranceValidationException('The policy start date must be a valid date formatted YYYY-MM-DD');

        if ($start > $end) {
            throw new InsuranceValidationException('The policy start date cannot be after its end date');
        }
    }
}
