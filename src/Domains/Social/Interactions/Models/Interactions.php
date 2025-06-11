<?php

declare(strict_types=1);

namespace Kanvas\Social\Interactions\Models;

use Baka\Contracts\AppInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Models\BaseModel;

/**
 * Class Interactions.
 *
 * @property int $id
 * @property int $apps_id
 * @property string $name
 * @property string $title
 * @property string $icon
 * @property string $description
 */
class Interactions extends BaseModel
{
    protected $connection = 'social';
    protected $table = 'interactions';
    protected $guarded = [];


    public static function fetchByName(string $name, ?AppInterface $app): ?self
    {
        $app = $app ?? app(Apps::class);
        return Interactions::fromApp($app)
            ->where('name', $name)
            ->where('is_deleted', 0)
            ->first() ?? null;
    }
}
