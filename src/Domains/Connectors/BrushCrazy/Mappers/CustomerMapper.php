<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Mappers;

use Baka\Support\Str;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Support\Money;

/**
 * Turns one BrushCrazy `users` row into the values a People needs. Free of model lookups so it
 * stays unit-testable; the sync action resolves the tenant and writes.
 *
 * Never read: `password`, `api_token` and `remember_token`. People is a CRM contact, and none of
 * those belong on one. `card` is safe to read: it is the VIP card balance (a Money cast), not
 * stored card data.
 */
class CustomerMapper
{
    /**
     * @return array{
     *     people: array<string, mixed>,
     *     email: string|null,
     *     phone: string|null,
     *     address: array<string, string|null>|null,
     *     custom_fields: array<string, mixed>
     * }
     */
    public static function map(object $row): array
    {
        $fullName = preg_replace('/\s+/', ' ', trim((string) ($row->name ?? ''))) ?? '';
        $name = Str::parseFullName($fullName);
        $email = Str::trimToNull($row->email ?? null);

        // `name` is NOT NULL at the source but can still be blank; the email is the one
        // identifier every user is guaranteed to have.
        $displayName = $fullName !== '' ? $fullName : (string) $email;

        return [
            'people' => [
                'firstname' => $name['firstname'] !== '' ? $name['firstname'] : $displayName,
                'lastname' => $name['lastname'],
                'name' => $displayName,
                'is_deleted' => ($row->deleted_at ?? null) !== null,
                'created_at' => $row->created_at ?? null,
            ],
            'email' => $email,
            'phone' => Str::trimToNull($row->phone_number ?? null),
            'address' => self::address($row),
            'custom_fields' => self::customFields($row),
        ];
    }

    /**
     * @return array<string, string|null>|null
     */
    public static function address(object $row): ?array
    {
        $street = Str::trimToNull(($row->street_number ?? '') . ' ' . ($row->route ?? ''));

        if ($street === null) {
            return null;
        }

        return [
            'address' => $street,
            'address_2' => Str::trimToNull($row->suite ?? null),
            'city' => Str::trimToNull($row->locality ?? null),
            'state' => Str::trimToNull($row->administrative_area_level_1 ?? null),
            'zip' => Str::trimToNull($row->postal_code ?? null),
        ];
    }

    /**
     * Points, rewards and the VIP card are a snapshot. BrushCrazy keeps them global per user while
     * Kanvas People is per company, so every studio's copy carries the same balance; it is not a
     * ledger and must not be summed across companies. Studio credit is studio-scoped and is set by
     * the sync, which knows the studio.
     *
     * Nulls are kept on purpose: the sync deletes the field, so a value cleared at the source is
     * cleared in Kanvas too.
     *
     * @return array<string, mixed>
     */
    public static function customFields(object $row): array
    {
        $birthMonth = (int) ($row->birthday ?? 0);
        $rewards = Str::jsonToArray($row->rewards ?? null);
        $settings = Str::jsonToArray($row->settings ?? null);
        $vipCard = Money::fromRaw($row->card ?? null);

        return [
            CustomFieldEnum::BRUSHCRAZY_USER_ID->value => (int) $row->id,
            CustomFieldEnum::BRUSHCRAZY_DISPLAY_NAME->value => Str::trimToNull($row->display_name ?? null),
            CustomFieldEnum::BRUSHCRAZY_BIRTHDAY_MONTH->value => $birthMonth >= 1 && $birthMonth <= 12 ? $birthMonth : null,
            CustomFieldEnum::BRUSHCRAZY_POINTS->value => isset($row->points) ? (int) $row->points : null,
            CustomFieldEnum::BRUSHCRAZY_REWARDS->value => is_array($rewards) && $rewards !== [] ? $rewards : null,
            CustomFieldEnum::BRUSHCRAZY_VIP_CARD_CENTS->value => isset($row->card) ? $vipCard->cents : null,
            CustomFieldEnum::BRUSHCRAZY_PROMOTION_EMAILS->value => is_array($settings) && array_key_exists('promotionEmails', $settings)
                ? (bool) $settings['promotionEmails']
                : null,
            CustomFieldEnum::BRUSHCRAZY_FAVORITE_STUDIO_ID->value => isset($row->favorite_studio) ? (int) $row->favorite_studio : null,
            CustomFieldEnum::BRUSHCRAZY_LAST_STUDIO_ID->value => isset($row->last_studio) ? (int) $row->last_studio : null,
        ];
    }
}
