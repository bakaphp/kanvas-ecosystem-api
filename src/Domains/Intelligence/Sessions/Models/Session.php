<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\Models;

use Baka\Traits\UuidTrait;
use Kanvas\Intelligence\Models\BaseModel;

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
 * @property string $content;
 */
class Session extends BaseModel
{
    protected $table = 'sessions';
    protected $guarded = [];
}
