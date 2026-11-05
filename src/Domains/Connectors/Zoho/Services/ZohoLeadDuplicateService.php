<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Services;

use Baka\Support\Str;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Webleit\ZohoCrmApi\Models\Record;
use Webleit\ZohoCrmApi\Modules\Records as ZohoRecordsModule;

/**
 * Zoho's search compares the stored string and also returns partial phone matches, so each format
 * a number may be stored in is tried and every hit is re-checked here on the last 10 digits.
 */
class ZohoLeadDuplicateService
{
    public function __construct(
        private readonly ZohoRecordsModule $leads,
    ) {
    }

    /**
     * Stops at the first verified match: one is enough to route the lead, and each search is an API call.
     *
     * @param string[] $phones
     * @return array<string, Record> keyed by Zoho lead id
     */
    public function findLeads(?string $email, array $phones): array
    {
        $email = Str::trimToNull((string) $email);

        if ($email !== null) {
            $target = Contact::normalizeValue($email, ContactTypeEnum::EMAIL->value);
            $found = $this->verified(
                $this->leads->searchEmail($email),
                '/email/i',
                fn (string $value) => Contact::normalizeValue($value, ContactTypeEnum::EMAIL->value) === $target
            );

            if ($found !== []) {
                return $found;
            }
        }

        foreach ($phones as $phone) {
            $target = Contact::normalizeValue($phone, ContactTypeEnum::PHONE->value);

            if (strlen($target) < 7) {
                continue;
            }

            foreach (self::phoneVariants($target) as $variant) {
                $found = $this->verified(
                    $this->leads->searchPhone($variant),
                    '/phone|mobile/i',
                    fn (string $value) => Contact::normalizeValue($value, ContactTypeEnum::PHONE->value) === $target
                );

                if ($found !== []) {
                    return $found;
                }
            }
        }

        return [];
    }

    /**
     * @return string[]
     */
    public static function phoneVariants(string $digits): array
    {
        if (strlen($digits) !== 10) {
            return [$digits, '+' . $digits];
        }

        $area = substr($digits, 0, 3);
        $prefix = substr($digits, 3, 3);
        $line = substr($digits, 6);

        return [
            $digits,
            '+1' . $digits,
            '(' . $area . ') ' . $prefix . '-' . $line,
            $area . '-' . $prefix . '-' . $line,
        ];
    }

    /**
     * @param iterable<Record> $records
     * @return array<string, Record>
     */
    private function verified(iterable $records, string $fieldPattern, callable $matches): array
    {
        $found = [];

        foreach ($records as $record) {
            foreach ($record->getData() as $field => $value) {
                if (is_string($value) && $value !== '' && preg_match($fieldPattern, (string) $field) && $matches($value)) {
                    $found[(string) $record->getId()] = $record;

                    break;
                }
            }
        }

        return $found;
    }
}
