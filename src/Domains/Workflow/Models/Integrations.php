<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;

class Integrations extends BaseModel
{
    use UuidTrait;

    protected $table = 'integrations';

    protected $fillable = [
        'uuid',
        'apps_id',
        'name',
        'config',
    ];

    protected $casts = [
        'config' => Json::class,
        'is_deleted' => 'boolean',
    ];
}
