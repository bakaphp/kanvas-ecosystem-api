<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Models;

use Baka\Traits\PublicAppScopeTrait;
use Kanvas\Workflow\Models\BaseModel;

class Status extends BaseModel
{
    use PublicAppScopeTrait;

    protected $table = 'status';

    protected $fillable = [
        'name',
        'slug',
        'apps_id',
    ];

    protected $casts = [
        'is_deleted' => 'boolean',
    ];

    /**
     * Get the defaults status by its name
     * @todo Add this status to seeds to manage its ids
     */
    public static function getDefaultStatusByName(string $name): self
    {
        return self::where('name', $name)
                ->where('apps_id', 0)
                ->firstOrFail();
    }
}
