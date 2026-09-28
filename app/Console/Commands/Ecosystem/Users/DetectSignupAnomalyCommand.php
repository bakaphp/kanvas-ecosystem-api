<?php

declare(strict_types=1);

namespace App\Console\Commands\Ecosystem\Users;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Notifications\SignupAnomalyNotification;
use Kanvas\Auth\Services\RegistrationAbuseReportService;
use Kanvas\Auth\Services\SignupCounterService;
use Kanvas\Auth\Services\SignupProtectionSettingsService;
use Kanvas\Users\Models\UsersAssociatedApps;
use Throwable;

/**
 * The campaign that created ~14k accounts ran for days before anyone noticed,
 * because nothing watched the rate. Comparing each app against its own trailing
 * average means a quiet app and a busy one both get a threshold that means
 * something.
 */
class DetectSignupAnomalyCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:detect-signup-anomaly {apps_id?} {--dry-run}';

    protected $description = 'Alert on apps whose signup rate in the last hour is far above their own baseline';

    public function handle(): int
    {
        $appsId = $this->argument('apps_id');

        $apps = $appsId
            ? Apps::query()->where('id', (int) $appsId)->get()
            : Apps::query()->where('is_deleted', 0)->get();

        foreach ($apps as $app) {
            $this->overwriteAppService($app);

            try {
                $this->inspect($app);
            } catch (Throwable $e) {
                $this->error('Failed on app ' . $app->getId() . ': ' . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    private function inspect(Apps $app): void
    {
        $now = Carbon::now();
        $windowStart = $now->copy()->subHour();
        $signups = $this->countSignups($app, $windowStart, $now);

        // Most apps are idle most hours; skip the baseline query for them.
        if ($signups === 0) {
            return;
        }

        $settings = new SignupProtectionSettingsService($app);
        $baseline = $this->hourlyBaseline($app, $windowStart, $settings->anomalyBaselineDays());

        if (! $this->isSpike($signups, $baseline, $settings)) {
            return;
        }

        $reporter = new RegistrationAbuseReportService($app);
        $blocked = $reporter->blockedDuring($windowStart);

        $this->warn(sprintf(
            '[%d] %s: %d signups last hour vs %.1f/h baseline (%d blocked)',
            $app->getId(),
            $app->name,
            $signups,
            $baseline,
            $blocked
        ));

        if ($this->option('dry-run')) {
            return;
        }

        $reporter->anomalyDetected($signups, $baseline, $blocked, $settings->anomalyMultiplier());

        $this->email($app, $settings, $signups, $baseline, $blocked);
    }

    /**
     * The baseline floors at 1 so a dormant app can't make every trickle of
     * traffic look like an infinite spike.
     */
    private function isSpike(int $signups, float $baseline, SignupProtectionSettingsService $settings): bool
    {
        return $signups >= $settings->anomalyFloor()
            && $signups > max(1.0, $baseline) * $settings->anomalyMultiplier();
    }

    private function countSignups(Apps $app, Carbon $from, Carbon $to): int
    {
        return UsersAssociatedApps::query()
            ->where('apps_id', $app->getId())
            ->where('is_deleted', 0)
            ->whereBetween('created_at', [$from, $to])
            ->count();
    }

    private function hourlyBaseline(Apps $app, Carbon $windowStart, int $baselineDays): float
    {
        $total = $this->countSignups($app, $windowStart->copy()->subDays($baselineDays), $windowStart);

        return $total / ($baselineDays * 24);
    }

    private function email(
        Apps $app,
        SignupProtectionSettingsService $settings,
        int $signups,
        float $baseline,
        int $blocked
    ): void {
        $recipients = $settings->anomalyAlertEmails();

        if ($recipients === []) {
            $this->warn(
                'No alert recipients configured for app ' . $app->getId() . ' — '
                . ($settings->sentryReportingEnabled() ? 'Sentry only.' : 'nobody will be notified.')
            );

            return;
        }

        $slotIsFree = new SignupCounterService($app)
            ->claim('anomaly_alert', $settings->anomalyCooldownSeconds());

        if (! $slotIsFree) {
            return;
        }

        Notification::route('mail', $recipients)->notify(
            new SignupAnomalyNotification($app, $signups, $baseline, $blocked, $settings->anomalyMultiplier())
        );
    }
}
