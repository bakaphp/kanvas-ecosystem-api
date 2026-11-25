<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Imports\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kanvas\Workflow\Integrations\Imports\DataTransferObject\ImportChunkResult;
use Kanvas\Workflow\Integrations\Imports\Enums\IntegrationImportStatusEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Models\BaseModel;

/**
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int $integrations_id
 * @property int $integrations_companies_id
 * @property int $users_id
 * @property string $entity
 * @property IntegrationImportStatusEnum $status
 * @property array|null $options
 * @property int|null $total
 * @property int $processed
 * @property int $created
 * @property int $updated
 * @property int $skipped
 * @property int $failed
 * @property list<array{id: string, error: string}>|null $errors
 * @property array|null $summary
 * @property string|null $error
 * @property Carbon|null $queued_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class IntegrationImport extends BaseModel
{
    use UuidTrait;

    public const int STALE_AFTER_HOURS = 3;
    public const int MAX_ERRORS = 50;

    protected $table = 'integration_imports';

    protected $guarded = [];

    protected $attributes = [
        'processed' => 0,
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'failed' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => IntegrationImportStatusEnum::class,
            'options' => Json::class,
            'errors' => Json::class,
            'summary' => Json::class,
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'is_deleted' => 'boolean',
        ];
    }

    public function integrationCompany(): BelongsTo
    {
        return $this->belongsTo(IntegrationsCompany::class, 'integrations_companies_id');
    }

    /**
     * @return list<array{id: string, error: string}>
     */
    public function getErrors(): array
    {
        return $this->errors ?? [];
    }

    public static function latestFor(IntegrationsCompany $integrationCompany, string $entity): ?self
    {
        return self::sameImportAs($integrationCompany, $entity)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * A run that stopped reporting is not blocking anything: a worker killed mid-run never gets to
     * mark it failed.
     */
    public static function inProgressFor(IntegrationsCompany $integrationCompany, string $entity): ?self
    {
        return self::sameImportAs($integrationCompany, $entity)
            ->whereIn('status', [
                IntegrationImportStatusEnum::QUEUED->value,
                IntegrationImportStatusEnum::RUNNING->value,
            ])
            ->where('updated_at', '>', Carbon::now()->subHours(self::STALE_AFTER_HOURS))
            ->orderByDesc('id')
            ->first();
    }

    public function markRunning(?int $total): void
    {
        $this->status = IntegrationImportStatusEnum::RUNNING;
        $this->total = $total;
        $this->started_at = Carbon::now();
        $this->save();
    }

    public function advance(ImportChunkResult $chunk): void
    {
        $this->created += $chunk->created;
        $this->updated += $chunk->updated;
        $this->skipped += $chunk->skipped;
        $this->failed += $chunk->failed;
        $this->processed += $chunk->created + $chunk->updated + $chunk->skipped + $chunk->failed;
        $this->errors = array_slice(
            array_merge($this->errors ?? [], $chunk->errors),
            0,
            self::MAX_ERRORS
        );
        $this->save();
    }

    public function complete(?array $summary = null): void
    {
        $this->status = IntegrationImportStatusEnum::COMPLETED;
        $this->summary = $summary;
        $this->finished_at = Carbon::now();
        $this->save();
    }

    public function fail(string $error): void
    {
        $this->status = IntegrationImportStatusEnum::FAILED;
        $this->error = $error;
        $this->finished_at = Carbon::now();
        $this->save();
    }

    private static function sameImportAs(IntegrationsCompany $integrationCompany, string $entity): Builder
    {
        return self::query()
            ->where('companies_id', $integrationCompany->companies_id)
            ->where('integrations_id', $integrationCompany->integrations_id)
            ->where('entity', $entity)
            ->notDeleted();
    }
}
