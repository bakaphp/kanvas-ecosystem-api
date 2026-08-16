<?php

declare(strict_types=1);

namespace Kanvas\Auth\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Sentry\Severity;
use Sentry\State\Scope;
use Throwable;

use function Sentry\captureMessage;
use function Sentry\withScope;

/**
 * Single reporting point for blocked signups, so an attack is visible without
 * anyone tailing logs.
 *
 * Sentry capture is throttled per app + reason. A campaign can produce tens of
 * thousands of blocks an hour, and the value of the 400th identical event is
 * zero — the throttled event carries the real window count instead, so the
 * issue still shows the true scale without paying for it in event quota.
 */
final class RegistrationAbuseReportService
{
    private const SENTRY_THROTTLE_SECONDS = 300;

    /**
     * Long enough that an hourly sweep can still read the tail of a window it
     * arrives late for.
     */
    private const COUNTER_TTL_SECONDS = 172800;

    public function __construct(
        private readonly Apps $app,
    ) {
    }

    public function blocked(string $email, string $reason): void
    {
        $blockedThisHour = $this->increment(Carbon::now());

        Log::warning('auth.registration_spam_email_blocked', [
            'app_id' => $this->app->getId(),
            'app_name' => $this->app->name,
            'email' => $email,
            'reason' => $reason,
            'blocked_this_hour' => $blockedThisHour,
        ]);

        $this->report($email, $reason, $blockedThisHour);
    }

    /**
     * Blocks recorded in the hour bucket the given moment falls into.
     */
    public function blockedDuring(Carbon $moment): int
    {
        return (int) (Cache::get($this->counterKey($moment)) ?? 0);
    }

    private function increment(Carbon $moment): int
    {
        $key = $this->counterKey($moment);

        Cache::add($key, 0, self::COUNTER_TTL_SECONDS);

        return (int) Cache::increment($key);
    }

    private function counterKey(Carbon $moment): string
    {
        return 'signup_blocked:' . $this->app->getId() . ':' . $moment->format('YmdH');
    }

    /**
     * Reporting must never be able to fail a registration — a Sentry transport
     * error here would surface to the user as a failed signup.
     */
    private function report(string $email, string $reason, int $blockedThisHour): void
    {
        $throttleKey = 'signup_block_sentry:' . $this->app->getId() . ':' . $reason;

        if (! Cache::add($throttleKey, true, self::SENTRY_THROTTLE_SECONDS)) {
            return;
        }

        try {
            withScope(function (Scope $scope) use ($email, $reason, $blockedThisHour): void {
                $scope->setLevel(Severity::warning());
                $scope->setTag('app_id', (string) $this->app->getId());
                $scope->setTag('app_name', (string) $this->app->name);
                $scope->setTag('block_reason', $reason);
                $scope->setContext('registration_abuse', [
                    'email' => $email,
                    'reason' => $reason,
                    'blocked_this_hour' => $blockedThisHour,
                    'throttle_seconds' => self::SENTRY_THROTTLE_SECONDS,
                ]);
                /**
                 * One issue per app + reason, so a campaign reads as a single
                 * issue with a rising event count rather than thousands of
                 * one-off issues Sentry can't alert on.
                 */
                $scope->setFingerprint(['registration-spam', (string) $this->app->getId(), $reason]);

                captureMessage('Registration blocked as spam: ' . $reason, Severity::warning());
            });
        } catch (Throwable $e) {
            Log::warning('auth.registration_spam_report_failed', ['error' => $e->getMessage()]);
        }
    }
}
