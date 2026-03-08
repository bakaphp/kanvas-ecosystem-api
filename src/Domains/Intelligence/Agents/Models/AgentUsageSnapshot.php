<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Casts\Json;
use Baka\Traits\SoftDeletesTrait;
use Baka\Traits\UuidTrait;
use Kanvas\Intelligence\Models\BaseModel;

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
