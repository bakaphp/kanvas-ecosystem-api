<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Casts\Json;
use Baka\Traits\SoftDeletesTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Models\BaseModel;

/**
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property Carbon|null $snapshot_date
 * @property string|null $source
 * @property string|null $raw_output
 * @property array|null $parsed_data
 * @property bool $is_deleted
 */
class AgentUsageSnapshot extends BaseModel
{
    use UuidTrait;
    use SoftDeletesTrait;

    protected $fillable = [
        'uuid',
        'apps_id',
        'companies_id',
        'snapshot_date',
        'source',
        'raw_output',
        'parsed_data',
    ];

    protected $casts = [
        'parsed_data' => Json::class,
        'snapshot_date' => 'date',
    ];
}
