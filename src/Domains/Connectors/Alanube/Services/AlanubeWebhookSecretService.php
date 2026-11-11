<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Alanube\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Alanube\Enums\ConfigurationEnum;
use Throwable;

/**
 * Alanube does not sign deliveries; it replays the custom headers we configure. So each company gets its own
 * random secret, sent as `x-kanvas-webhook-secret` and compared in constant time on every delivery.
 *
 * Rotation never drops a delivery: the new secret becomes current, the old one keeps validating for a grace
 * window (deliveries already queued at Alanube carry the old header), then it simply stops matching.
 */
class AlanubeWebhookSecretService
{
    public const int ROTATION_GRACE_HOURS = 24;
    private const int SECRET_LENGTH = 48;

    public function __construct(
        private readonly Companies $company,
    ) {
    }

    public function current(): ?string
    {
        return $this->decrypt(ConfigurationEnum::WEBHOOK_SECRET);
    }

    public function currentOrCreate(): string
    {
        $secret = $this->current();

        if ($secret !== null) {
            return $secret;
        }

        $secret = Str::random(self::SECRET_LENGTH);
        $this->company->setEncrypted(ConfigurationEnum::WEBHOOK_SECRET->value, $secret);

        return $secret;
    }

    public function rotate(): string
    {
        $previous = $this->current();

        if ($previous !== null) {
            $this->company->setEncrypted(ConfigurationEnum::WEBHOOK_PREVIOUS_SECRET->value, $previous);
            $this->company->set(
                ConfigurationEnum::WEBHOOK_PREVIOUS_SECRET_EXPIRES_AT->value,
                Carbon::now()->addHours(self::ROTATION_GRACE_HOURS)->toIso8601String(),
            );
        }

        $this->company->del(ConfigurationEnum::WEBHOOK_SECRET->value);

        return $this->currentOrCreate();
    }

    public function matches(?string $presented): bool
    {
        if ($presented === null || $presented === '') {
            return false;
        }

        $current = $this->current();
        if ($current !== null && hash_equals($current, $presented)) {
            return true;
        }

        $previous = $this->previousIfStillValid();

        return $previous !== null && hash_equals($previous, $presented);
    }

    public function dropExpiredPrevious(): void
    {
        if ($this->company->get(ConfigurationEnum::WEBHOOK_PREVIOUS_SECRET->value) !== null
            && $this->previousIfStillValid() === null) {
            $this->company->del(ConfigurationEnum::WEBHOOK_PREVIOUS_SECRET->value);
            $this->company->del(ConfigurationEnum::WEBHOOK_PREVIOUS_SECRET_EXPIRES_AT->value);
        }
    }

    private function previousIfStillValid(): ?string
    {
        $expiresAt = (string) $this->company->get(ConfigurationEnum::WEBHOOK_PREVIOUS_SECRET_EXPIRES_AT->value);

        if ($expiresAt === '' || Carbon::parse($expiresAt)->isPast()) {
            return null;
        }

        return $this->decrypt(ConfigurationEnum::WEBHOOK_PREVIOUS_SECRET);
    }

    private function decrypt(ConfigurationEnum $key): ?string
    {
        try {
            $secret = (string) $this->company->get($key->value);
        } catch (Throwable) {
            // An APP_KEY rotation makes the stored secret unreadable: treat it as absent so the next registration mints a new one.
            return null;
        }

        return $secret !== '' ? $secret : null;
    }
}
