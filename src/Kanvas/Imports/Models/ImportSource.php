<?php

declare(strict_types=1);

namespace Kanvas\Imports\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Filesystem\Models\FilesystemImports;
use Kanvas\Filesystem\Models\FilesystemMapper;
use Kanvas\Imports\Enums\ImportRunStatusEnum;
use Kanvas\Imports\Jobs\RunImportSourceJob;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Models\BaseModel;
use Kanvas\Regions\Models\Regions;
use Override;

/**
 * A nightly (or any cron) pull of one or more remote files into one mapper-driven import.
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int $companies_branches_id
 * @property int $users_id
 * @property int $regions_id
 * @property int $filesystem_mapper_id
 * @property int $import_connections_id
 * @property int|null $warehouses_id
 * @property int|null $channels_id
 * @property string $name
 * @property string|null $root
 * @property array $files each: {pattern: string, filter: ?{column, in[]}, required: bool}
 * @property bool $unpublish_missing
 * @property array|null $extra
 * @property string|null $schedule
 * @property string|null $timezone
 * @property bool $is_active
 * @property CarbonInterface|null $last_run_at
 * @property ImportRunStatusEnum|null $last_status
 * @property string|null $last_message
 * @property int|null $last_filesystem_imports_id
 * @property CarbonInterface $created_at
 * @property ImportConnection|null $importConnection
 */
class ImportSource extends BaseModel
{
    use UuidTrait;

    protected $table = 'import_sources';

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'files' => Json::class,
            'extra' => Json::class,
            'unpublish_missing' => 'boolean',
            'is_active' => 'boolean',
            'is_deleted' => 'boolean',
            'last_run_at' => 'datetime',
            'last_status' => ImportRunStatusEnum::class,
        ];
    }

    public function importConnection(): BelongsTo
    {
        return $this->belongsTo(ImportConnection::class, 'import_connections_id', 'id');
    }

    public function filesystemMapper(): BelongsTo
    {
        return $this->belongsTo(FilesystemMapper::class, 'filesystem_mapper_id', 'id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(CompaniesBranches::class, 'companies_branches_id', 'id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Regions::class, 'regions_id', 'id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouses::class, 'warehouses_id', 'id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channels::class, 'channels_id', 'id');
    }

    public function lastFilesystemImport(): BelongsTo
    {
        return $this->belongsTo(FilesystemImports::class, 'last_filesystem_imports_id', 'id');
    }

    public function effectiveSchedule(): ?string
    {
        return $this->schedule ?? $this->importConnection?->default_schedule;
    }

    public function effectiveTimezone(): string
    {
        return $this->timezone ?? $this->importConnection?->timezone ?? 'UTC';
    }

    public function effectiveRoot(): ?string
    {
        return $this->root ?? $this->importConnection?->root;
    }

    /**
     * Due when the most recent scheduled time is later than the last run, so a tick missed by a
     * deploy still runs once on the next tick instead of skipping the night. A source that never
     * ran waits for its first scheduled time after it was created.
     */
    public function isDue(CarbonInterface $now): bool
    {
        $schedule = $this->effectiveSchedule();
        if (! $this->is_active || $schedule === null) {
            return false;
        }

        $previousRun = new CronExpression($schedule)->getPreviousRunDate(
            $now->copy()->setTimezone($this->effectiveTimezone()),
            0,
            true
        );

        $since = $this->last_run_at ?? $this->created_at;

        return $previousRun > $since;
    }

    /**
     * Stamped before dispatch so the next scheduler tick doesn't queue it again while it waits.
     */
    public function queueRun(): void
    {
        $this->forceFill([
            'last_run_at' => now(),
            'last_status' => ImportRunStatusEnum::QUEUED,
        ])->save();

        RunImportSourceJob::dispatch($this);
    }

    /**
     * What the mapper reads as `extra.*` for this source's runs.
     */
    public function runExtra(): array
    {
        return array_merge(
            $this->extra ?? [],
            array_filter(
                [
                    'warehouse_id' => $this->warehouses_id,
                    'channels_id' => $this->channels_id,
                ],
                fn ($value) => $value !== null
            ),
            ['deleteAfterUse' => false]
        );
    }
}
