<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Importer\Models;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Traits\KanvasModelTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Importer\Enums\ProductImportRunStatusEnum;

/**
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $users_id
 * @property int|null $channels_id
 * @property string $status
 * @property int $batches_count
 * @property int $skus_count
 * @property int $published_count
 * @property int $unpublished_count
 * @property string|null $skipped_reason
 * @property Carbon $started_at
 * @property Carbon $last_activity_at
 * @property Carbon|null $finished_at
 */
class ProductImportRun extends Model
{
    use KanvasModelTrait;

    /**
     * An open run with no batch for this long belongs to a script that died before calling
     * finish; the next batch starts a fresh run instead of joining it.
     */
    public const int ABANDON_AFTER_MINUTES = 180;

    /**
     * Hard cap regardless of activity. A client that sends importProduct often and never calls
     * finish would otherwise keep one run open for days — an ancient started_at and ticks piled up
     * from long-gone cars — and the next finish (a dealer script, an FTP source) would sweep almost
     * nothing. Must stay above the longest real import, or a slow run gets split mid-way.
     */
    public const int MAX_AGE_HOURS = 12;

    protected $connection = 'inventory';
    protected $table = 'product_import_runs';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'finished_at' => 'datetime',
            'is_deleted' => 'boolean',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channels::class, 'channels_id');
    }

    /**
     * Explicit tenant columns, not fromApp()/fromCompany(): under an AppKey binding fromCompany()
     * ignores the company passed and widens to companies_id > 0, which would hand a server-to-server
     * script another company's run.
     */
    public static function latestOpen(AppInterface $app, CompanyInterface $company): ?self
    {
        return self::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('status', ProductImportRunStatusEnum::OPEN->value)
            ->latest('id')
            ->first();
    }

    public function isStale(): bool
    {
        return $this->last_activity_at->lt(now()->subMinutes(self::ABANDON_AFTER_MINUTES))
            || $this->started_at->lt(now()->subHours(self::MAX_AGE_HOURS));
    }
}
