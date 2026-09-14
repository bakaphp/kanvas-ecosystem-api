<?php

declare(strict_types=1);

namespace Kanvas\Auth\Services;

use Baka\Validations\EmailProvider;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;

/**
 * Shape-based signup throttling, for the campaigns per-IP rate limiting can't
 * see. A farm rotating IPs still has to reuse its address generator, so we count
 * on the address instead of the connection: a run sharing a local-part prefix is
 * one generator, and a run resolving to one mailbox is one Gmail account working
 * its dot and `+tag` aliases.
 *
 * The domain rule is the one that survives a smarter local-part generator, since
 * it scores nothing about the address string — but only where the campaign
 * concentrates on a domain. A run spread one-per-domain across a provider's
 * vanity catalog (mail.com alone offers ~200) stays under it, which is what
 * email verification is for.
 */
final class RegistrationVelocityService
{
    private const PREFIX_LENGTH = 5;

    private readonly SignupProtectionSettingsService $settings;
    private readonly SignupCounterService $counter;

    public function __construct(Apps $app)
    {
        $this->settings = new SignupProtectionSettingsService($app);
        $this->counter = new SignupCounterService($app);
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
            $bucket = 'prefix:' . substr($local, 0, self::PREFIX_LENGTH);

            if ($this->counter->hitInWindow($bucket, $this->settings->prefixWindowSeconds()) > $prefixLimit) {
                return 'local_part_prefix_burst';
            }
        }

        $mailboxLimit = $this->settings->mailboxLimit();

        if ($mailboxLimit > 0) {
            $bucket = 'mailbox:' . EmailProvider::canonicalize($email);

            if ($this->counter->hitInWindow($bucket, $this->settings->mailboxWindowSeconds()) > $mailboxLimit) {
                return 'mailbox_reuse';
            }
        }

        $domainLimit = $this->settings->domainLimit();

        if ($domainLimit > 0 && str_contains($email, '@')) {
            $bucket = 'domain:' . Str::lower(Str::afterLast($email, '@'));

            if ($this->counter->hitInWindow($bucket, $this->settings->domainWindowSeconds()) > $domainLimit) {
                return 'email_domain_burst';
            }
        }

        return null;
    }
}
