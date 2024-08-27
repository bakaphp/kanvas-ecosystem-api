<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Models;

use Kanvas\Workflow\Models\BaseModel;

class Status extends BaseModel
{
    protected $table = 'status';

    protected $fillable = [
        'name',
        'slug',
        'apps_id',
    ];

    protected $casts = [
        'is_deleted' => 'boolean',
    ];
}
