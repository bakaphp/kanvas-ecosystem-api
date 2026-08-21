<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Models;

use Baka\Casts\Json;
use Baka\Traits\KanvasModelTrait;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Append-only, so it skips the Inventory BaseModel — there is no `is_deleted`.
 * Look up with `query()->fromApp($app)`: the static getById helpers call
 * notDeleted() and would error here.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property ?int $users_id
 * @property ?string $session_id
 * @property string $recommendation_uuid
 * @property string $query_raw
 * @property string $query_normalized
 * @property ?array $intent
 * @property array $product_ids
 * @property int $results_count
 * @property ?string $engine
 */
class RecommendationImpression extends Model
{
    use KanvasModelTrait;

    public const ?string UPDATED_AT = null;

    protected $connection = 'inventory';
    protected $table = 'product_recommendation_impressions';
    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'intent' => Json::class,
            'product_ids' => Json::class,
            'results_count' => 'integer',
        ];
    }
}
