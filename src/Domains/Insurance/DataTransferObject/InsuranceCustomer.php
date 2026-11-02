<?php

declare(strict_types=1);

namespace Kanvas\Insurance\DataTransferObject;

use Baka\Support\DateHelper;
use Baka\Support\Str;
use Kanvas\Insurance\Enums\CustomerDocumentTypeEnum;
use Kanvas\Insurance\Enums\InsuranceCustomerFieldEnum;
use Kanvas\Insurance\Enums\MaritalStatusEnum;
use Kanvas\Insurance\Enums\SexEnum;
use Kanvas\Insurance\Exceptions\InsuranceValidationException;

class InsuranceCustomer
{
    private const ENUM_FIELDS = [
        'sex' => SexEnum::class,
        'marital_status' => MaritalStatusEnum::class,
        'document_type' => CustomerDocumentTypeEnum::class,
    ];

    private const TRUE_VALUES = ['true', '1', 'yes'];

    private const FALSE_VALUES = ['false', '0', 'no'];

    private function __construct(
        private readonly array $values,
    ) {
    }

    public static function fromArray(array $data): self
    {
        $values = [];

        foreach (InsuranceCustomerFieldEnum::cases() as $field) {
            $value = Str::trimToNull(self::rawValue($data[$field->value] ?? null));

            if ($value !== null) {
                $values[$field->value] = self::validated($field, $value);
            }
        }

        return new self($values);
    }

    public function get(InsuranceCustomerFieldEnum $field): ?string
    {
        return $this->values[$field->value] ?? null;
    }

    public function isTrue(InsuranceCustomerFieldEnum $field): bool
    {
        return $this->get($field) === 'true';
    }

    public function fallbackTo(self $defaults): self
    {
        return new self(array_merge($defaults->values, $this->values));
    }

    public function missing(array $required): array
    {
        return array_values(array_filter(
            $required,
            fn (InsuranceCustomerFieldEnum $field): bool => $this->get($field) === null
        ));
    }

    public function assertHas(array $required): void
    {
        $missing = $this->missing($required);

        if ($missing !== []) {
            throw new InsuranceValidationException(
                'We need more information to issue this policy: ' . implode(
                    ', ',
                    array_map(fn (InsuranceCustomerFieldEnum $field): string => $field->label(), $missing)
                )
            );
        }
    }

    public function toArray(): array
    {
        return $this->values;
    }

    private static function rawValue(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private static function validated(InsuranceCustomerFieldEnum $field, string $value): string
    {
        $enum = self::ENUM_FIELDS[$field->value] ?? null;

        if ($enum !== null && $enum::tryFrom($value) === null) {
            throw new InsuranceValidationException(
                'Invalid ' . $field->label() . ', allowed values: ' . implode(', ', array_column($enum::cases(), 'value'))
            );
        }

        if ($field === InsuranceCustomerFieldEnum::BIRTH_DATE && ! self::isDate($value)) {
            throw new InsuranceValidationException('birth date must be a valid date formatted Y-m-d');
        }

        return $field->isBoolean() ? self::booleanValue($field, $value) : $value;
    }

    private static function booleanValue(InsuranceCustomerFieldEnum $field, string $value): string
    {
        $normalized = strtolower($value);

        return match (true) {
            in_array($normalized, self::TRUE_VALUES, true) => 'true',
            in_array($normalized, self::FALSE_VALUES, true) => 'false',
            default => throw new InsuranceValidationException($field->label() . ' must be true or false'),
        };
    }

    private static function isDate(string $value): bool
    {
        return DateHelper::parseYmd($value) !== null;
    }
}
