<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\BrushCrazy;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\BrushCrazy\Enums\ConfigurationEnum;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Support\CorrectionCutoff;
use Kanvas\Connectors\BrushCrazy\Webhooks\ProcessBrushCrazyWebhookJob;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhook;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * The scheduled pull safety net behind the mirror webhooks, kept running for good now that the
 * mirror is bidirectional. A studio is only pulled once it has an active mirror receiver and its
 * app has a pinned correction cutoff: both exist only after a manual kanvas:brushcrazy-import, so
 * the first full pass (and the cutoff it pins) stays an operator decision, never a cron one.
 */
class DispatchBrushCrazyIncrementalImportCommand extends Command
{
    use KanvasJobsTrait;

    private const int FAILURE_SUMMARY_LINES = 8;

    protected $signature = 'kanvas:brushcrazy-incremental';

    protected $description = 'Run kanvas:brushcrazy-import --incremental for every app with BrushCrazy studios still mirrored';

    public function handle(): int
    {
        $failed = 0;

        foreach (self::targets() as $target) {
            $app = $target['app'];
            $this->overwriteAppService($app);
            $this->info("App {$app->getId()}: studios " . implode(', ', $target['studios']) . " as user {$target['user']->getId()}");

            $buffer = new BufferedOutput();

            try {
                $exitCode = Artisan::call('kanvas:brushcrazy-import', [
                    'app_id' => $app->getId(),
                    'user_id' => $target['user']->getId(),
                    '--entity' => 'all',
                    '--incremental' => true,
                    '--studio' => $target['studios'],
                ], $buffer);
            } catch (Throwable $e) {
                report($e);
                $failed++;

                continue;
            }

            $output = $buffer->fetch();
            $this->output->write($output);

            if ($exitCode !== self::SUCCESS) {
                $failed++;

                // The import swallows its own failures into an exit code, and a row that keeps
                // failing freezes its studio's cursor, so without this the net silently stops.
                report(new RuntimeException(
                    "BrushCrazy incremental import failed for app {$app->getId()} (studios "
                    . implode(', ', $target['studios']) . "), exit {$exitCode}: " . self::summary($output)
                ));
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Only the studios still mirrored are passed: without --studio the import pulls every studio
     * of the app, including one an operator paused (BRUSHCRAZY_SYNC_ENABLED off). The acting user
     * is the earliest receiver's, the same user the webhook writes as, so both paths author the
     * rows identically.
     *
     * An app without a pinned correction cutoff is skipped: the `--entity=all` run would pin it at
     * the moment of the tick, an irreversible boundary no operator chose.
     *
     * @return array<int, array{app: Apps, user: Users, studios: array<int, int>}> keyed by app id
     */
    public static function targets(?Carbon $now = null): array
    {
        $now ??= Carbon::now('UTC');
        $targets = [];

        $receivers = ReceiverWebhook::query()
            ->forJob(ProcessBrushCrazyWebhookJob::class)
            ->notDeleted()
            ->where('is_active', true)
            ->with(['app', 'company', 'user'])
            ->orderBy('id')
            ->get();

        foreach ($receivers as $receiver) {
            $app = $receiver->app;
            $company = $receiver->company;

            if ($app === null || $company === null || $receiver->user === null) {
                continue;
            }

            if (! $company->getBool(ConfigurationEnum::BRUSHCRAZY_SYNC_ENABLED->value)) {
                continue;
            }

            $studioId = $company->get(CustomFieldEnum::BRUSHCRAZY_STUDIO_ID->value);

            if ($studioId === null || $studioId === '') {
                continue;
            }

            if (CorrectionCutoff::stored($app) === null || CorrectionCutoff::isPastCutover($app, $now)) {
                continue;
            }

            $targets[$app->getId()] ??= ['app' => $app, 'user' => $receiver->user, 'studios' => []];

            if (! in_array((int) $studioId, $targets[$app->getId()]['studios'], true)) {
                $targets[$app->getId()]['studios'][] = (int) $studioId;
            }
        }

        return $targets;
    }

    /**
     * The tail of the import's output: its last lines carry the per-entity failure counts and the
     * final "failed rows" / "Import failed" message.
     */
    public static function summary(string $output): string
    {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/', $output) ?: []),
            fn (string $line) => $line !== ''
        ));

        return mb_substr(implode(' | ', array_slice($lines, -self::FAILURE_SUMMARY_LINES)), 0, 2000);
    }
}
