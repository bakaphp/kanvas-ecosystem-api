<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Credit700\DataTransferObject;

use Baka\Support\DateHelper;
use Baka\Traits\ScalarCoercionTrait;
use Kanvas\Guild\Customers\DataTransferObject\Address as AddressData;
use Kanvas\Guild\Customers\Models\People;
use Spatie\LaravelData\Data;

class CreditApplication extends Data
{
    use ScalarCoercionTrait;

    public function __construct(
        public readonly string $name,
        public readonly string $ssn,
        public readonly string $address,
        public readonly string $city,
        public readonly string $state,
        public readonly string $zip,
        public readonly ?string $dob = null,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly ?string $mobileNumber = null,
        public readonly ?string $employer = null,
        public readonly ?string $employmentStatus = null,
        public readonly ?string $position = null,
        public readonly ?int $employmentYears = null,
        public readonly ?int $employmentMonths = null,
        public readonly ?string $workPhone = null,
        public readonly ?string $previousEmployer = null,
        public readonly ?int $previousEmploymentYears = null,
        public readonly ?string $previousEmploymentTitle = null,
        public readonly ?string $previousWorkPhone = null,
        public readonly ?float $monthlyIncome = null,
        public readonly ?float $otherIncome = null,
        public readonly ?string $otherIncomeExplanation = null,
        public readonly ?string $housingType = null,
        public readonly ?float $housingPayment = null,
        public readonly ?string $driversLicenseNumber = null,
        public readonly ?string $driversLicenseState = null,
        public readonly ?int $currentAddressPeriod = null,
        public readonly ?string $previousAddress = null,
        public readonly ?string $previousCity = null,
        public readonly ?string $previousState = null,
        public readonly ?string $previousZip = null,
        public readonly ?int $previousAddressPeriod = null,
    ) {
    }

    /**
     * Build from the submitted credit-app form data.
     *
     * @param array<string, mixed> $formData the `message['data']['form']` array
     */
    public static function fromMultiple(array $formData, People $people): self
    {
        $personal = $formData['personal'] ?? [];
        $housing = $formData['housing'] ?? [];
        $financial = $formData['financial'] ?? [];

        $employmentDuration = DateHelper::parseDuration($financial['years_at_current_employment'] ?? '');

        $peopleLicense = $people->getDriverLicense();
        $hasPreviousAddress = ! empty($housing['previous_address'] ?? null);

        $name = trim(($personal['first_name'] ?? '') . ' ' . ($personal['last_name'] ?? ''));

        return new self(
            name: $name !== '' ? $name : $people->getName(),
            ssn: (string) ($personal['ssn'] ?? ''),
            address: self::normalizeStreet($housing['address'] ?? ''),
            city: self::normalizeCity($housing['city'] ?? null),
            state: self::normalizeState($housing['state'] ?? null),
            zip: self::normalizeZip($housing['zip_code'] ?? ''),
            dob: self::normalizeDob($personal['dob'] ?? null),
            email: self::nullableString($personal['email'] ?? null),
            phone: self::nullableString($personal['home_number'] ?? null),
            mobileNumber: self::nullableString($personal['mobile_number'] ?? null),
            employer: self::nullableString($financial['current_employer'] ?? null),
            employmentStatus: self::nullableString($financial['employment_status'] ?? null),
            position: self::nullableString($financial['current_employment_title'] ?? null),
            employmentYears: $employmentDuration['years'],
            employmentMonths: $employmentDuration['months'],
            workPhone: self::nullableString($financial['current_employer_phone'] ?? null),
            previousEmployer: self::nullableString($financial['previous_employer'] ?? null),
            previousEmploymentYears: self::normalizeYears($financial['years_at_previous_employment'] ?? null),
            previousEmploymentTitle: self::nullableString($financial['previous_employment_title'] ?? null),
            previousWorkPhone: self::nullableString($financial['previous_employer_phone'] ?? null),
            monthlyIncome: self::normalizeMonthlyIncome(
                $financial['gross_income'] ?? null,
                $financial['income_interval'] ?? null
            ),
            otherIncome: self::nullableFloat($financial['other_income'] ?? null),
            otherIncomeExplanation: self::nullableString($financial['other_income_source'] ?? null),
            housingType: self::nullableString($housing['residence_type'] ?? null),
            housingPayment: self::nullableFloat($housing['rent'] ?? null),
            driversLicenseNumber: self::nullableString($personal['drivers_license'] ?? null)
                ?? $peopleLicense?->number,
            driversLicenseState: self::normalizeState($personal['drivers_license_state'] ?? null)
                ?: $peopleLicense?->state,
            currentAddressPeriod: self::normalizeYears($housing['time_at_address'] ?? null),
            previousAddress: $hasPreviousAddress ? self::normalizeStreet($housing['previous_address']) : null,
            previousCity: $hasPreviousAddress ? self::normalizeCity($housing['previous_city'] ?? null) : null,
            previousState: $hasPreviousAddress ? self::normalizeState($housing['previous_state'] ?? null) : null,
            previousZip: $hasPreviousAddress ? self::normalizeZip($housing['previous_zip_code'] ?? '') : null,
            previousAddressPeriod: $hasPreviousAddress
                ? self::normalizeYears($housing['previous_time_at_address'] ?? null)
                : null,
        );
    }

    /**
     * CURRENTADDRESSPERIOD / PREVADDRESSPERIOD / PREVEMPLOYMENTYEARS are single integer
     * "years" fields in the 700Credit spec with no months counterpart, so the form's
     * "years.months" value rounds to whole years. RouteOne reads anything else as months.
     */
    private static function normalizeYears(mixed $duration): ?int
    {
        if (empty($duration)) {
            return null;
        }

        $parsed = DateHelper::parseDuration($duration);

        return (int) round($parsed['years'] + ($parsed['months'] / 12));
    }

    /**
     * MINCOME is monthly and RouteOne has no income-frequency field, so the form's
     * gross_income has to be converted here from whatever interval the customer picked.
     * Whole dollars: the spec caps the value at 7 characters, which cents blow past
     * for anything over $9,999.99 a month.
     */
    private static function normalizeMonthlyIncome(mixed $income, ?string $interval): ?float
    {
        $income = self::nullableFloat($income);

        if ($income === null) {
            return null;
        }

        $monthly = match (strtolower(trim((string) $interval))) {
            'yearly', 'annually', 'annual' => $income / 12,
            'bi-weekly', 'biweekly' => $income * 26 / 12,
            'weekly' => $income * 52 / 12,
            default => $income,
        };

        return round($monthly);
    }

    /**
     * 700Credit expects the DOB as MM/DD/YYYY. The form submits free-form values
     * (e.g. "14-April-1965"), so parse leniently and reformat; null on unparseable.
     */
    private static function normalizeDob(mixed $dob): ?string
    {
        return DateHelper::tryParseCarbon(self::nullableString($dob))?->format('m/d/Y');
    }

    private static function normalizeCity(array|string|null $city): string
    {
        if (is_array($city)) {
            return (string) ($city['name'] ?? '');
        }

        return (string) ($city ?? '');
    }

    private static function normalizeState(array|string|null $state): string
    {
        if (is_array($state)) {
            return (string) ($state['code'] ?? '');
        }

        return strtoupper((string) ($state ?? ''));
    }

    private static function normalizeZip(mixed $zip): string
    {
        return (string) preg_replace('/[^0-9\-]/', '', (string) $zip);
    }

    private static function normalizeStreet(mixed $street): string
    {
        return AddressData::flattenStreet($street);
    }
}
