<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Mappers;

use Baka\Support\Str;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Support\Money;

/**
 * One BrushCrazy `users` row as the customer's Kanvas login, the account the site signs into.
 *
 * Reads `password` on purpose, unlike CustomerMapper: both systems hash with bcrypt, so the stored
 * hash is copied as is and the customer keeps signing in with the password they already have. It is
 * never decoded or logged. An account BrushCrazy made by itself (a sale, a kiosk, an event request)
 * has a random password nobody knows; it is flagged so the launch email asks for a new one.
 *
 * The custom field names are the ones the site reads off `me`; points, Paint Money and the VIP card
 * are global per user in BrushCrazy, so the single User carries the one true balance.
 */
class CustomerAccountMapper
{
    public const string REWARD_POINTS_FIELD = 'reward_points';

    public const string PAINT_MONEY_FIELD = 'paint_money';

    public const string GIFT_CARD_BALANCE_FIELD = 'gift_card_balance';

    /**
     * @return array{
     *     email: string,
     *     firstname: string,
     *     lastname: string,
     *     displayname: string,
     *     phone: string|null,
     *     password_hash: string|null,
     *     dob: string|null,
     *     custom_fields: array<string, mixed>
     * }|null  null when the row cannot sign in: no email, or deleted at the source
     */
    public static function map(object $row): ?array
    {
        $email = Str::trimToNull(mb_strtolower((string) ($row->email ?? '')));

        if ($email === null || ($row->deleted_at ?? null) !== null) {
            return null;
        }

        $customer = CustomerMapper::map($row)['people'];
        $hash = self::bcryptHash($row->password ?? null);
        $method = Str::trimToNull($row->registration_method ?? null);
        $month = (int) ($row->birthday ?? 0);

        return [
            'email' => $email,
            'firstname' => (string) $customer['firstname'],
            'lastname' => (string) $customer['lastname'],
            'displayname' => (string) $customer['name'],
            'phone' => Str::trimToNull($row->phone_number ?? null),
            'password_hash' => $hash,
            // BrushCrazy stores only the birth month; the site shows only the month of `dob`.
            'dob' => $month >= 1 && $month <= 12 ? sprintf('1900-%02d-01', $month) : null,
            'custom_fields' => [
                CustomFieldEnum::BRUSHCRAZY_USER_ID->value => (int) $row->id,
                CustomFieldEnum::BRUSHCRAZY_REGISTRATION_METHOD->value => $method,
                CustomFieldEnum::BRUSHCRAZY_NEEDS_PASSWORD->value => $hash === null || self::isAutomatic($method),
                self::REWARD_POINTS_FIELD => isset($row->points) ? (string) (int) $row->points : null,
                self::PAINT_MONEY_FIELD => isset($row->rewards) ? self::dollars($row->rewards) : null,
                self::GIFT_CARD_BALANCE_FIELD => isset($row->card) ? self::dollars($row->card) : null,
            ],
        ];
    }

    public static function isAutomatic(?string $registrationMethod): bool
    {
        return $registrationMethod !== null
            && (str_starts_with($registrationMethod, 'Automatic:') || $registrationMethod === 'In-Studio Kiosk');
    }

    protected static function bcryptHash(mixed $hash): ?string
    {
        return is_string($hash) && preg_match('/^\$2[aby]\$\d{2}\$.{53}$/', $hash) === 1 ? $hash : null;
    }

    protected static function dollars(mixed $money): string
    {
        return number_format(Money::fromRaw($money)->dollars(), 2, '.', '');
    }
}
