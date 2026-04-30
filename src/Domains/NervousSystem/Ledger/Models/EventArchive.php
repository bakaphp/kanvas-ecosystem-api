<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Ledger\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property int $id
 * @property string $uuid
 * @property int|null $apps_id
 * @property int|null $companies_id
 * @property \Illuminate\Support\Carbon $window_starts_at
 * @property \Illuminate\Support\Carbon $window_ends_at
 * @property string $s3_disk
 * @property string $s3_path
 * @property int $event_count
 * @property int|null $size_bytes
 * @property \Illuminate\Support\Carbon $archived_at
 */
class EventArchive extends Model
{
    use UuidTrait;

    protected $connection = 'intelligence';

    protected $table = 'nervous_system_event_archives';

    public $timestamps = false;

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'apps_id' => 'integer',
            'companies_id' => 'integer',
            'event_count' => 'integer',
            'size_bytes' => 'integer',
            'window_starts_at' => 'date',
            'window_ends_at' => 'date',
            'archived_at' => 'datetime',
        ];
    }
}
