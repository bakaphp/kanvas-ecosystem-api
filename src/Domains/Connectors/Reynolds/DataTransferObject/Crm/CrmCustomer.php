<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\DataTransferObject\Crm;

use Baka\Support\Str;
use Spatie\LaravelData\Data;

class CrmCustomer extends Data
{
    public function __construct(
        public readonly ?string $firstname = null,
        public readonly ?string $middlename = null,
        public readonly ?string $lastname = null,
        public readonly ?string $language = null,
        public readonly ?CrmPhones $phones = null,
        public readonly ?array $emails = null,
        public readonly ?CrmAddress $address = null,
        public readonly ?CrmConsent $consent = null
    ) {
    }

    /**
     * Trimmed, inner whitespace collapsed, lowercased: how two names are compared.
     */
    public static function normalizeName(?string $name): ?string
    {
        $normalized = Str::lowerTrim(preg_replace('/\s+/u', ' ', (string) $name));

        return $normalized === '' ? null : $normalized;
    }

    public function normalizedFirstname(): ?string
    {
        return self::normalizeName($this->firstname);
    }

    public function normalizedLastname(): ?string
    {
        return self::normalizeName($this->lastname);
    }

    public function hasBothNames(): bool
    {
        return $this->normalizedFirstname() !== null && $this->normalizedLastname() !== null;
    }

    /**
     * @return list<string> lowercase, trimmed, no blanks and no repeats
     */
    public function normalizedEmails(): array
    {
        $emails = array_map(
            fn (mixed $email): string => Str::lowerTrim(is_string($email) ? $email : null),
            $this->emails ?? []
        );

        return array_values(array_unique(array_filter($emails, fn (string $email): bool => $email !== '')));
    }

    /**
     * @return list<string> every phone as digits only
     */
    public function phoneNumbers(): array
    {
        $phones = [$this->phones?->cell, $this->phones?->home, $this->phones?->work];
        $digits = array_map(fn (?string $phone): string => Str::sanitizePhoneNumber($phone), $phones);

        return array_values(array_unique(array_filter($digits, fn (string $phone): bool => $phone !== '')));
    }
}
