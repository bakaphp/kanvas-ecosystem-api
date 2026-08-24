<?php

declare(strict_types=1);

namespace Kanvas\Auth\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Sentry\Severity;
use Sentry\State\Scope;
use Throwable;

use function Sentry\captureMessage;
use function Sentry\withScope;

/**
 * Single reporting point for signup abuse, so an attack is visible without
 * anyone tailing logs.
 *
 * Sentry needs no configuration beyond the DSN every environment already has,
 * which makes it the alert of last resort — a spike is never invisible just
 * because nobody set up the email recipients.
 */
final class RegistrationAbuseReportService
{
    private const SENTRY_THROTTLE_SECONDS = 300;

    /**
     * Long enough that an hourly sweep can still read the tail of a window it
     * arrives late for.
     */
    private const COUNTER_TTL_SECONDS = 172800;

    private readonly SignupCounterService $counter;
    private readonly SignupProtectionSettingsService $settings;

    public function __construct(
        private readonly Apps $app,
    ) {
        $this->counter = new SignupCounterService($app);
        $this->settings = new SignupProtectionSettingsService($app);
    }

    public function blocked(string $email, string $reason): void
    {
        $blockedThisHour = $this->counter->hit($this->hourBucket(Carbon::now()), self::COUNTER_TTL_SECONDS);

        Log::warning('auth.registration_spam_email_blocked', [
            'app_id' => $this->app->getId(),
            'app_name' => $this->app->name,
            'email' => $email,
            'reason' => $reason,
            'blocked_this_hour' => $blockedThisHour,
        ]);

        if (! $this->settings->sentryReportingEnabled()) {
            return;
        }

        /**
         * A campaign produces tens of thousands of identical blocks an hour and
         * the 400th event adds nothing, so only one per reason gets through per
         * window — carrying the real count so the issue still shows true scale.
         */
        if (! $this->counter->claim('sentry:' . $reason, self::SENTRY_THROTTLE_SECONDS)) {
            return;
        }

        $this->capture(
            'Registration blocked as spam: ' . $reason,
            ['registration-spam', (string) $this->app->getId(), $reason],
            [
                'email' => $email,
                'reason' => $reason,
                'blocked_this_hour' => $blockedThisHour,
                'throttle_seconds' => self::SENTRY_THROTTLE_SECONDS,
            ],
            ['block_reason' => $reason]
        );
    }

    public function blockedDuring(Carbon $moment): int
    {
        return $this->counter->read($this->hourBucket($moment));
    }

    /**
     * Unthrottled — the sweep runs once an hour, so the event count on the issue
     * tracks how long the attack ran.
     */
    public function anomalyDetected(int $signups, float $baseline, int $blocked, int $multiplier): void
    {
        if (! $this->settings->sentryReportingEnabled()) {
            return;
        }

        $this->capture(
            'Signup spike detected: ' . $signups . ' in the last hour',
            ['signup-anomaly', (string) $this->app->getId()],
            [
                'signups_last_hour' => $signups,
                'hourly_baseline' => round($baseline, 2),
                'blocked_last_hour' => $blocked,
                'multiplier' => $multiplier,
            ]
        );
    }

    private function hourBucket(Carbon $moment): string
    {
        return 'blocked:' . $moment->format('YmdH');
    }

    /**
     * Reporting must never be able to fail a registration — a Sentry transport
     * error here would surface to the user as a failed signup.
     *
     * @param list<string> $fingerprint groups every event of one campaign into a
     *                                  single issue with a rising count, which is
     *                                  what Sentry's spike alerts key off
     * @param array<string, mixed> $context
     * @param array<string, string> $tags
     */
    private function capture(string $message, array $fingerprint, array $context, array $tags = []): void
    {
        try {
            withScope(function (Scope $scope) use ($message, $fingerprint, $context, $tags): void {
                $scope->setLevel(Severity::warning());
                $scope->setTag('app_id', (string) $this->app->getId());
                $scope->setTag('app_name', (string) $this->app->name);

                foreach ($tags as $name => $value) {
                    $scope->setTag($name, $value);
                }

                $scope->setContext('registration_abuse', $context);
                $scope->setFingerprint($fingerprint);

                captureMessage($message, Severity::warning());
            });
        } catch (Throwable $e) {
            Log::warning('auth.registration_spam_report_failed', ['error' => $e->getMessage()]);
        }
    }
}
