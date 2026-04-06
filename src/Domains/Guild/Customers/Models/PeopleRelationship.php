<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Models;

use Baka\Traits\DatabaseSearchableTrait;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Models\BaseModel;

/**
 * Class PeopleRelationship.
 *
 * @property int $id
 * @property int $companies_id
 * @property int $apps_id
 * @property string $name
 * @property string|null $description
 */
class PeopleRelationship extends BaseModel
{
    use DatabaseSearchableTrait;

    protected $table = 'leads_participants_types';
    protected $guarded = [];

    public function searchableAs(): string
    {
        $app = $this->app ?? app(Apps::class);

        return config('scout.prefix') . 'people_relationship_index';
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'apps_id' => $this->apps_id,
            'companies_id' => $this->companies_id,
            'name' => $this->name,
            'description' => $this->description,
        ];
    }

    public static function search($query = '', $callback = null)
    {
        $query = self::traitSearch($query, $callback)->where('apps_id', app(Apps::class)->getId());
        $user = auth()->user();
        if ($user instanceof UserInterface && ! $user->isAppOwner()) {
            $query->where('companies_id', $user->getCurrentCompany()->getId());
        }

        return $query;
    }
}
