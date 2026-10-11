<?php

declare(strict_types=1);

namespace Kanvas\Insurance\DataTransferObject;

use Baka\Support\Str;
use DateTimeImmutable;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\Enums\CustomerDocumentTypeEnum;
use Kanvas\Insurance\Enums\InsuranceCustomerFieldEnum;
use Kanvas\Insurance\Enums\MaritalStatusEnum;
use Kanvas\Insurance\Enums\SexEnum;

class InsuranceCustomer
{
    private const ENUM_FIELDS = [
        'sex' => SexEnum::class,
        'marital_status' => MaritalStatusEnum::class,
        'document_type' => CustomerDocumentTypeEnum::class,
    ];

    private function __construct(
        private readonly array $values,
    ) {
    }

    public static function fromArray(array $data): self
    {
        $values = [];

        foreach (InsuranceCustomerFieldEnum::cases() as $field) {
            $value = Str::trimToNull(isset($data[$field->value]) ? (string) $data[$field->value] : null);

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

    public function toArray(): array
    {
        return $this->values;
    }

    private static function validated(InsuranceCustomerFieldEnum $field, string $value): string
    {
        $enum = self::ENUM_FIELDS[$field->value] ?? null;

        if ($enum !== null && $enum::tryFrom($value) === null) {
            throw new ValidationException(
                'Invalid ' . $field->label() . ', allowed values: ' . implode(', ', array_column($enum::cases(), 'value'))
            );
        }

        if ($field === InsuranceCustomerFieldEnum::BIRTH_DATE && ! self::isDate($value)) {
            throw new ValidationException('birth date must be a valid date formatted Y-m-d');
        }

        return $value;
    }

    private static function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
