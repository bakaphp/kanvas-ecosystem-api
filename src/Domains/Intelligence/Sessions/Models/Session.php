<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Models\BaseModel;
use Override;

/**
 * Class Session
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $channel_id
 * @property int $agents_id
 * @property string $uuid
 * @property string $canal_id
 * @property string $entity_namespace
 * @property int $entity_id
 * @property string $json
 * @property string $content;
 */
class Session extends BaseModel
{
    protected $table = 'sessions';
    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'content' => Json::class,
            'user' => Json::class,
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agents_id');
    }
}
