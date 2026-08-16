<?php

declare(strict_types=1);

namespace Kanvas\Auth\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppSettingsEnums;

/**
 * Every tuning knob for signup abuse protection, so a default is defined once
 * and an app under attack can be retuned from settings without a deploy.
 */
final class SignupProtectionSettingsService
{
    public const DEFAULT_PREFIX_LIMIT = 5;
    public const DEFAULT_PREFIX_WINDOW_SECONDS = 600;
    public const DEFAULT_MAILBOX_LIMIT = 3;
    public const DEFAULT_MAILBOX_WINDOW_SECONDS = 86400;
    public const DEFAULT_ANOMALY_MULTIPLIER = 5;
    public const DEFAULT_ANOMALY_FLOOR = 20;
    public const DEFAULT_ANOMALY_BASELINE_DAYS = 7;
    public const DEFAULT_ANOMALY_COOLDOWN_SECONDS = 21600;

    /**
     * Windows floor rather than reaching 0 — a 0-second TTL expires the counter
     * before it can ever be read, silently disabling the rule while its limit
     * still reads as enabled.
     */
    private const MIN_WINDOW_SECONDS = 60;

    public function __construct(
        private readonly Apps $app,
    ) {
    }

    /**
     * A limit of 0 disables its rule; a window of 0 does not.
     */
    public function prefixLimit(): int
    {
        return $this->int(AppSettingsEnums::SIGNUP_PREFIX_BURST_LIMIT, self::DEFAULT_PREFIX_LIMIT);
    }

    public function prefixWindowSeconds(): int
    {
        return $this->int(AppSettingsEnums::SIGNUP_PREFIX_BURST_WINDOW, self::DEFAULT_PREFIX_WINDOW_SECONDS, self::MIN_WINDOW_SECONDS);
    }

    public function mailboxLimit(): int
    {
        return $this->int(AppSettingsEnums::SIGNUP_MAILBOX_LIMIT, self::DEFAULT_MAILBOX_LIMIT);
    }

    public function mailboxWindowSeconds(): int
    {
        return $this->int(AppSettingsEnums::SIGNUP_MAILBOX_WINDOW, self::DEFAULT_MAILBOX_WINDOW_SECONDS, self::MIN_WINDOW_SECONDS);
    }

    public function anomalyMultiplier(): int
    {
        return $this->int(AppSettingsEnums::SIGNUP_ANOMALY_MULTIPLIER, self::DEFAULT_ANOMALY_MULTIPLIER);
    }

    public function anomalyFloor(): int
    {
        return $this->int(AppSettingsEnums::SIGNUP_ANOMALY_FLOOR, self::DEFAULT_ANOMALY_FLOOR);
    }

    public function anomalyBaselineDays(): int
    {
        return $this->int(AppSettingsEnums::SIGNUP_ANOMALY_BASELINE_DAYS, self::DEFAULT_ANOMALY_BASELINE_DAYS, 1);
    }

    public function anomalyCooldownSeconds(): int
    {
        return $this->int(AppSettingsEnums::SIGNUP_ANOMALY_COOLDOWN, self::DEFAULT_ANOMALY_COOLDOWN_SECONDS, self::MIN_WINDOW_SECONDS);
    }

    /**
     * Per-app recipients fall back to the platform list so a new app is covered
     * before anyone configures it.
     *
     * @return list<string>
     */
    public function anomalyAlertEmails(): array
    {
        $configured = $this->app->get(AppSettingsEnums::SIGNUP_ANOMALY_ALERT_EMAILS->getValue())
            ?: config('kanvas.signup_anomaly.alert_emails', '');

        $candidates = is_array($configured)
            ? $configured
            : (preg_split('/[\s,;]+/', (string) $configured) ?: []);

        return array_values(array_filter(
            array_map(trim(...), $candidates),
            static fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        ));
    }

    /**
     * An unset key and a key cleared to an empty string both mean "use the
     * default" — admin UIs write the latter.
     */
    private function int(AppSettingsEnums $setting, int $default, int $minimum = 0): int
    {
        $configured = $this->app->get($setting->getValue());

        if ($configured === null || $configured === '') {
            return $default;
        }

        return max($minimum, (int) $configured);
    }
}
