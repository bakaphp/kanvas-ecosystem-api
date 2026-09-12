<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Odoo\Actions\Concerns;

use Baka\Support\Str;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;

/**
 * Requires a `protected array $payload` holding one raw Odoo record.
 */
trait ParsesOdooPayload
{
    /**
     * Odoo's JSON-RPC serializes an unset char/text field as the boolean `false`, not `null` or
     * `""` — handing that straight to a `?string` DTO property is a TypeError.
     */
    private function payloadString(string $key): ?string
    {
        $value = $this->payload[$key] ?? null;

        return is_string($value) ? Str::trimToNull($value) : null;
    }

    /**
     * Same `false`-for-unset convention as `payloadString()`, except a set many2one arrives as
     * `[id, "display_name"]` — never a bare id.
     */
    private function relationId(mixed $value): ?string
    {
        return is_array($value) && isset($value[0]) ? (string) $value[0] : null;
    }

    private function relationName(mixed $value): ?string
    {
        return is_array($value) && isset($value[1]) ? (string) $value[1] : null;
    }

    /**
     * @return list<array{value: string, contacts_types_id: int, weight: int}>
     */
    private function contactsFromPayload(string $emailKey, string $phoneKey): array
    {
        $contacts = [];

        foreach ([ContactTypeEnum::EMAIL->value => $emailKey, ContactTypeEnum::PHONE->value => $phoneKey] as $type => $key) {
            $value = $this->payloadString($key);

            if ($value !== null) {
                $contacts[] = ['value' => $value, 'contacts_types_id' => $type, 'weight' => 0];
            }
        }

        return $contacts;
    }
}
