<?php

declare(strict_types=1);

namespace Kanvas\Auth\Services;

use Baka\Validations\EmailProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;

/**
 * Shape-based signup throttling, for the campaigns that per-IP rate limiting
 * can't see. A farm rotating IPs still has to reuse its address generator, so
 * we count on the address instead of the connection:
 *
 * - a run of signups sharing a local-part prefix (`dggie_*`) is one generator
 * - a run of signups resolving to one real mailbox is one Gmail account using
 *   dot and `+tag` aliases
 *
 * Both counters are fixed windows keyed per app, so a tenant's traffic can
 * never trip another tenant's limit. Limits and windows are tunable per app via
 * SignupProtectionSettingsService.
 */
final class RegistrationVelocityService
{
    private const PREFIX_LENGTH = 5;

    private readonly SignupProtectionSettingsService $settings;

    public function __construct(
        private readonly Apps $app,
    ) {
        $this->settings = new SignupProtectionSettingsService($app);
    }

    /**
     * Which velocity rule this signup breaches, or null when it's within limits.
     * Calling this records the attempt, so it must run once per registration.
     */
    public function violation(string $email): ?string
    {
        $local = Str::lower(Str::before($email, '@'));
        $prefixLimit = $this->settings->prefixLimit();

        if ($prefixLimit > 0 && strlen($local) >= self::PREFIX_LENGTH) {
            $prefix = substr($local, 0, self::PREFIX_LENGTH);

            if ($this->hit('prefix:' . $prefix, $this->settings->prefixWindowSeconds()) > $prefixLimit) {
                return 'local_part_prefix_burst';
            }
        }

        $mailboxLimit = $this->settings->mailboxLimit();

        if ($mailboxLimit > 0) {
            $mailbox = EmailProvider::canonicalize($email);

            if ($this->hit('mailbox:' . $mailbox, $this->settings->mailboxWindowSeconds()) > $mailboxLimit) {
                return 'mailbox_reuse';
            }
        }

        return null;
    }

    /**
     * `add` only seeds the counter when the key is absent, so the TTL is anchored
     * to the first attempt in the window and a sustained trickle still expires.
     */
    private function hit(string $bucket, int $ttlSeconds): int
    {
        $key = 'signup_velocity:' . $this->app->getId() . ':' . md5($bucket);

        Cache::add($key, 0, $ttlSeconds);

        return (int) Cache::increment($key);
    }
}
