<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Support;

/**
 * A customer's login lives on both systems (same email, same bcrypt password hash, same name and
 * phone) and either side may change it. Each side remembers a fingerprint of what was last agreed
 * with the other (never the hash itself), which tells who changed since:
 *
 * - Kanvas still at the agreed value and BrushCrazy different: BrushCrazy changed it, take it.
 * - Kanvas different from the agreed value: Kanvas changed it; its push carries it to BrushCrazy.
 *
 * Values that already match are left alone, so the echo of a sync (each side seeing the other's
 * write come back) writes nothing and pushes nothing: the two settle after one round.
 */
final class AccountSync
{
    public static function isBcrypt(?string $hash): bool
    {
        return is_string($hash) && preg_match('/^\$2[aby]\$\d{2}\$.{53}$/', $hash) === 1;
    }

    public static function passwordFingerprint(?string $hash): ?string
    {
        return self::isBcrypt($hash) ? hash('sha256', 'password|' . $hash) : null;
    }

    /**
     * Email, name and phone as both systems can compare them: BrushCrazy keeps one `name` and
     * formats phone numbers its own way, so the full name (spacing and case aside), the phone's
     * digits and the email in lower case.
     */
    public static function profileFingerprint(?string $firstname, ?string $lastname, ?string $phone, ?string $email = null): string
    {
        $name = mb_strtolower(trim((string) preg_replace('/\s+/', ' ', trim((string) $firstname) . ' ' . trim((string) $lastname))));
        $digits = (string) preg_replace('/\D+/', '', (string) $phone);
        $address = mb_strtolower(trim((string) $email));

        return hash('sha256', 'profile|' . $name . '|' . $digits . '|' . $address);
    }

    /**
     * Whether BrushCrazy's value should win: it differs from Kanvas's, and Kanvas has not changed
     * since the last agreed value. Without an agreed value yet (accounts from before this sync),
     * BrushCrazy wins only for accounts it owns: a login made on the new site keeps its own.
     */
    public static function brushCrazyWins(
        string $kanvasFingerprint,
        string $brushCrazyFingerprint,
        ?string $agreedFingerprint,
        bool $brushCrazyOwnsAccount
    ): bool {
        if ($kanvasFingerprint === $brushCrazyFingerprint) {
            return false;
        }

        return $agreedFingerprint !== null
            ? $kanvasFingerprint === $agreedFingerprint
            : $brushCrazyOwnsAccount;
    }
}
