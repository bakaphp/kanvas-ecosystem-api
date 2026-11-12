<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\BrushCrazy;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\BrushCrazy\Actions\ProvisionBrushCrazyReceiverAction;
use Kanvas\Connectors\BrushCrazy\Actions\PullCalendarablesFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Actions\PullCustomersFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Actions\PullPaintingsFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Actions\PullRegistrationsFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Actions\PullSalesFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Actions\PullStaffFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Actions\ResolveStudioContextsAction;
use Kanvas\Connectors\BrushCrazy\Actions\SeedBrushCrazyLookupDataAction;
use Kanvas\Connectors\BrushCrazy\Enums\CalendarableTypeEnum;
use Kanvas\Connectors\BrushCrazy\Enums\SyncEntityEnum;
use Kanvas\Connectors\BrushCrazy\Support\CorrectionCutoff;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Users\Models\Users;
use Throwable;

class ImportFromBrushCrazyCommand extends Command
{
    use KanvasJobsTrait;

    private const array ENTITIES = [
        'all',
        'studios',
        'lookups',
        'paintings',
        'customers',
        'staff',
        'calendarables',
        'registrations',
        'sales',
        'receivers',
    ];

    /**
     * BrushCrazy writes are not instantaneous: a row can commit with an updated_at a little behind
     * the moment the previous run read the table. Re-reading this much keeps those rows from being
     * skipped; the sync is idempotent, so the overlap costs nothing but a few re-saves.
     */
    private const int CURSOR_OVERLAP_MINUTES = 5;

    protected $signature = 'kanvas:brushcrazy-import
                            {app_id : The application ID}
                            {user_id : User the imported rows and receivers are created as}
                            {--entity=all : all, studios, lookups, paintings, customers, staff, calendarables, registrations, sales, receivers}
                            {--studio=* : Only these BrushCrazy studio ids (repeatable)}
                            {--since= : Only rows updated since this UTC datetime (Y-m-d H:i:s)}
                            {--incremental : Resume each studio from its own cursor instead of a full pass}
                            {--correction-cutoff= : Pin the timezone correction cutoff (UTC); first run only, defaults to now}
                            {--chunk=500 : Source rows per chunk}
                            {--force : Run even though the BRUSHCRAZY_CUTOVER_AT kill switch is on}
                            {--show-secrets : Print each receiver signing secret, for configuring BrushCrazy}';

    protected $description = 'Import BrushCrazy studios, paintings, customers, staff, classes, registrations and sales into Kanvas and provision the mirror receivers';

    private ?Carbon $cutoverAt = null;

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $user = Users::getById((int) $this->argument('user_id'));
        $entity = (string) $this->option('entity');

        if (! in_array($entity, self::ENTITIES, true)) {
            throw new InvalidArgumentException("Unknown entity: {$entity}. Use one of: " . implode(', ', self::ENTITIES));
        }

        if ($this->option('since') !== null && $this->option('incremental')) {
            $this->error('--since and --incremental are mutually exclusive.');

            return self::FAILURE;
        }

        $this->cutoverAt = CorrectionCutoff::cutoverAt($app);

        if (CorrectionCutoff::isPastCutover($app) && ! $this->option('force')) {
            $this->error("BrushCrazy mirror is switched off for this app (BRUSHCRAZY_CUTOVER_AT {$this->cutoverAt->toIso8601String()}). Clear the setting to resume, or use --force for a one-off run.");

            return self::FAILURE;
        }

        $importsCalendarables = in_array($entity, ['all', 'calendarables'], true);

        try {
            // Pinned only by a run that writes classes: the first run of any other entity would
            // otherwise fix the boundary at its own start, before a single class was imported.
            $correctionCutoff = null;

            if ($importsCalendarables) {
                $correctionCutoff = CorrectionCutoff::pin(
                    $app,
                    $this->option('correction-cutoff') !== null ? Carbon::parse((string) $this->option('correction-cutoff'), 'UTC') : null,
                );
                $this->info('Timezone correction cutoff: ' . $correctionCutoff->toIso8601String());
            }

            $contexts = $this->resolveStudios($app, $user);
            $this->info('Studios: ' . count($contexts));

            $failed = 0;
            $receivers = [];

            foreach ($contexts as $context) {
                $this->line('');
                $this->info("Studio {$context->bcStudioId} → company {$context->companyId()} ({$context->company->name}, {$context->timezone})");

                if ($entity !== 'studios' && $entity !== 'receivers') {
                    $this->seedLookups($app, $user, $context);
                }

                // Paintings before calendarables, or a class whose painting has no Theme yet keeps
                // the Unassigned theme until the class itself changes again.
                if (in_array($entity, ['all', 'paintings'], true)) {
                    $failed += $this->importPaintings($app, $user, $context);
                }

                if (in_array($entity, ['all', 'customers'], true)) {
                    $failed += $this->importCustomers($app, $user, $context);
                }

                // Staff before calendarables: a class links its instructor only if the
                // Facilitator already exists.
                if (in_array($entity, ['all', 'staff'], true)) {
                    $failed += $this->importStaff($app, $user, $context);
                }

                if ($correctionCutoff !== null) {
                    $failed += $this->importCalendarables(
                        $app,
                        $user,
                        $context,
                        $correctionCutoff
                    );
                }

                if (in_array($entity, ['all', 'registrations'], true)) {
                    $failed += $this->importRegistrations($app, $user, $context);
                }

                if (in_array($entity, ['all', 'sales'], true)) {
                    $failed += $this->importSales($app, $user, $context);
                }

                if (in_array($entity, ['all', 'receivers'], true)) {
                    $receivers[] = $this->provisionReceiver($app, $user, $context);
                }
            }

            $this->displayReceivers($receivers);

            if ($failed > 0) {
                $this->error("Import finished with {$failed} failed rows; their cursors were not advanced.");

                return self::FAILURE;
            }

            $this->info('Import completed.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error("Import failed: {$e->getMessage()}");
            $this->error($e->getTraceAsString());

            return self::FAILURE;
        }
    }

    /**
     * --studio is applied inside the action, not after it: resolving writes to every studio it
     * touches. An unknown id only warns, or one deleted studio would fail the scheduled run of the
     * whole app on every tick. --incremental (the scheduled pull) leaves paused studios alone.
     *
     * @return array<int, StudioContext>
     */
    private function resolveStudios(Apps $app, Users $user): array
    {
        $only = array_values(array_unique(array_map('intval', (array) $this->option('studio'))));

        $action = new ResolveStudioContextsAction(
            $app,
            $user,
            onlyStudioIds: $only !== [] ? $only : null,
            skipPausedStudios: (bool) $this->option('incremental'),
        );
        $contexts = $action->execute();

        if ($action->unknownStudioIds !== []) {
            $this->warn('Unknown BrushCrazy studio ids skipped: ' . implode(', ', $action->unknownStudioIds));
        }

        if ($action->pausedStudioIds !== []) {
            $this->warn('Paused studios skipped (BRUSHCRAZY_SYNC_ENABLED off): ' . implode(', ', $action->pausedStudioIds));
        }

        return $contexts;
    }

    private function seedLookups(Apps $app, Users $user, StudioContext $context): void
    {
        $seeded = new SeedBrushCrazyLookupDataAction($app, $context->company, $user)->execute();

        $this->line('  lookups: ' . http_build_query($seeded, '', ', '));
    }

    private function importCalendarables(
        Apps $app,
        Users $user,
        StudioContext $context,
        Carbon $correctionCutoff
    ): int {
        $runStartedAt = Carbon::now('UTC');
        $since = $this->resolveSince($context, SyncEntityEnum::CALENDARABLES);

        $results = new PullCalendarablesFromBrushCrazyAction(
            $app,
            $user,
            $context,
            $correctionCutoff,
            since: $since,
            chunkSize: $this->chunkSize(),
        )->execute(
            fn (CalendarableTypeEnum $morph, int $count) => $this->line("    {$morph->value}: +{$count}")
        );

        return $this->finishPull(
            $context,
            SyncEntityEnum::CALENDARABLES,
            $runStartedAt,
            $results
        );
    }

    private function importPaintings(Apps $app, Users $user, StudioContext $context): int
    {
        $runStartedAt = Carbon::now('UTC');
        $since = $this->resolveSince($context, SyncEntityEnum::PAINTINGS);

        $results = new PullPaintingsFromBrushCrazyAction(
            $app,
            $user,
            $context,
            since: $since,
            chunkSize: $this->chunkSize(),
        )->execute(
            fn (int $count) => $this->line("    paintings: +{$count}")
        );

        return $this->finishPull(
            $context,
            SyncEntityEnum::PAINTINGS,
            $runStartedAt,
            $results
        );
    }

    private function importCustomers(Apps $app, Users $user, StudioContext $context): int
    {
        $runStartedAt = Carbon::now('UTC');
        $since = $this->resolveSince($context, SyncEntityEnum::CUSTOMERS);

        $results = new PullCustomersFromBrushCrazyAction(
            $app,
            $user,
            $context,
            since: $since,
            chunkSize: $this->chunkSize(),
        )->execute(
            fn (int $count) => $this->line("    users: +{$count}")
        );

        return $this->finishPull(
            $context,
            SyncEntityEnum::CUSTOMERS,
            $runStartedAt,
            $results
        );
    }

    private function importStaff(Apps $app, Users $user, StudioContext $context): int
    {
        $runStartedAt = Carbon::now('UTC');
        $since = $this->resolveSince($context, SyncEntityEnum::STAFF);

        $results = new PullStaffFromBrushCrazyAction(
            $app,
            $user,
            $context,
            since: $since,
            chunkSize: $this->chunkSize(),
        )->execute(
            fn (int $count) => $this->line("    staff: +{$count}")
        );

        return $this->finishPull(
            $context,
            SyncEntityEnum::STAFF,
            $runStartedAt,
            $results
        );
    }

    private function importRegistrations(Apps $app, Users $user, StudioContext $context): int
    {
        $runStartedAt = Carbon::now('UTC');
        $since = $this->resolveSince($context, SyncEntityEnum::REGISTRATIONS);

        $results = new PullRegistrationsFromBrushCrazyAction(
            $app,
            $user,
            $context,
            since: $since,
            chunkSize: $this->chunkSize(),
        )->execute(
            fn (CalendarableTypeEnum $morph, int $count) => $this->line("    {$morph->value}: +{$count} classes")
        );

        $this->line("  participants written: {$results['participants']}");

        return $this->finishPull(
            $context,
            SyncEntityEnum::REGISTRATIONS,
            $runStartedAt,
            $results
        );
    }

    private function importSales(Apps $app, Users $user, StudioContext $context): int
    {
        $runStartedAt = Carbon::now('UTC');
        $since = $this->resolveSince($context, SyncEntityEnum::ORDERS);

        $results = new PullSalesFromBrushCrazyAction(
            $app,
            $user,
            $context,
            since: $since,
            chunkSize: $this->chunkSize(),
        )->execute(
            fn (int $count) => $this->line("    sales: +{$count}")
        );

        $this->line('  catalog: ' . http_build_query($results['catalog'], '', ', ') . "; open sales skipped: {$results['skipped']}");

        return $this->finishPull(
            $context,
            SyncEntityEnum::ORDERS,
            $runStartedAt,
            $results
        );
    }

    /**
     * The cursor is per (company, entity) and only moves when the whole studio synced cleanly: a
     * cursor advanced past a failed row would drop that row for good. It never moves past the
     * BRUSHCRAZY_CUTOVER_AT kill switch, so a forced run while the mirror is off cannot make the
     * incremental run that follows its re-enabling skip what changed in the meantime.
     *
     * Warnings are data-quality flags (phantom seats, missing buyers) that do not hold the cursor.
     *
     * @param  array{synced: int, failed: int, errors: array<int, string>, warnings?: array<int, string>}  $results
     */
    private function finishPull(
        StudioContext $context,
        SyncEntityEnum $entity,
        Carbon $runStartedAt,
        array $results
    ): int {
        $warnings = $results['warnings'] ?? [];
        $this->line("  {$entity->value}: {$results['synced']} synced, {$results['failed']} failed, " . count($warnings) . ' warnings');

        foreach (array_slice($results['errors'], 0, 20) as $error) {
            $this->warn('    ' . $error);
        }

        foreach (array_slice($warnings, 0, 20) as $warning) {
            $this->line('    ' . $warning);
        }

        $cursor = self::nextCursor(
            $this->option('since') !== null,
            $results['failed'],
            $runStartedAt,
            $this->cutoverAt
        );

        if ($cursor !== null) {
            $context->company->set($entity->cursorKey(), $cursor->toIso8601String());
        }

        return $results['failed'];
    }

    /**
     * Null means "leave the cursor where it is". An explicit --since window is a repair of an
     * arbitrary range: it says nothing about rows older than it, so moving the cursor to now would
     * make the next --incremental run skip whatever changed between the old cursor and --since.
     */
    public static function nextCursor(
        bool $explicitSince,
        int $failed,
        Carbon $runStartedAt,
        ?Carbon $cutoverAt
    ): ?Carbon {
        if ($explicitSince || $failed > 0) {
            return null;
        }

        return $cutoverAt !== null && $cutoverAt->lessThan($runStartedAt) ? $cutoverAt : $runStartedAt;
    }

    private function chunkSize(): int
    {
        return max(1, (int) $this->option('chunk'));
    }

    private function resolveSince(StudioContext $context, SyncEntityEnum $entity): ?Carbon
    {
        $since = $this->explicitOrCursorSince($context, $entity);

        $this->line("  {$entity->value}: " . ($since !== null ? 'since ' . $since->toDateTimeString() . ' UTC' : 'full pass'));

        return $since;
    }

    private function explicitOrCursorSince(StudioContext $context, SyncEntityEnum $entity): ?Carbon
    {
        if ($this->option('since') !== null) {
            return Carbon::parse((string) $this->option('since'), 'UTC');
        }

        if (! $this->option('incremental')) {
            return null;
        }

        $cursor = $context->company->get($entity->cursorKey());

        return $cursor !== null
            ? Carbon::parse((string) $cursor)->utc()->subMinutes(self::CURSOR_OVERLAP_MINUTES)
            : null;
    }

    /**
     * @return array{studio: int, company: int, url: string, secret: string, created: bool}
     */
    private function provisionReceiver(Apps $app, Users $user, StudioContext $context): array
    {
        $action = new ProvisionBrushCrazyReceiverAction($app, $user, $context);
        $receiver = $action->execute();

        $this->line('  receiver: ' . ($action->created ? 'created' : 'already exists') . " ({$receiver->uuid})");

        return [
            'studio' => $context->bcStudioId,
            'company' => $context->companyId(),
            'url' => $receiver->getUrl(),
            'secret' => (string) ($receiver->configuration['signing_secret'] ?? ''),
            'created' => $action->created,
        ];
    }

    /**
     * @param  array<int, array{studio: int, company: int, url: string, secret: string, created: bool}>  $receivers
     */
    private function displayReceivers(array $receivers): void
    {
        if ($receivers === []) {
            return;
        }

        $showSecrets = (bool) $this->option('show-secrets');

        $this->line('');
        $this->table(
            ['Studio', 'Company', 'Receiver URL', 'Signing secret'],
            array_map(fn (array $receiver) => [
                $receiver['studio'],
                $receiver['company'],
                $receiver['url'],
                $showSecrets ? $receiver['secret'] : '(use --show-secrets)',
            ], $receivers),
        );

        // The exact value BrushCrazy's `config/services.php` (kanvas.mirror.receivers) reads.
        $this->info('KANVAS_MIRROR_RECEIVERS for BrushCrazy\'s .env:');
        $this->line(json_encode(
            array_combine(
                array_column($receivers, 'studio'),
                array_map(fn (array $receiver) => [
                    'url' => $receiver['url'],
                    'secret' => $showSecrets ? $receiver['secret'] : '<use --show-secrets>',
                ], $receivers)
            ),
            JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT
        ));
    }
}
