<?php

declare(strict_types=1);

namespace Kanvas\Guild\Rotations\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Kanvas\Guild\Leads\Models\LeadRotationAgent;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Users\Models\Users;
use RuntimeException;

/**
 * Class Rotation.
 *
 * @property int $id
 * @property int $users_id
 * @property int $companies_id
 * @property string $name
 */
class Rotation extends BaseModel
{
    use NoAppRelationshipTrait;

    protected $table = 'rotations';
    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id', 'id');
    }

    public function users(): HasManyThrough
    {
        return $this->hasManyThrough(
            Users::class,
            RotationUser::class,
            'rotations_id',
            'id',
            'id',
            'users_id'
        );
    }

    public function agents(): HasMany
    {
        return $this->hasMany(LeadRotationAgent::class, 'leads_rotations_id');
    }

    public function getRandomLeadsRotationsAgents(): Collection
    {
        return $this->agents()->inRandomOrder()->get();
    }

    public function getAgent(): UserInterface
    {
        $agents = $this->getRandomLeadsRotationsAgents();
        if ($agents->isEmpty()) {
            throw new RuntimeException("This rotation doesn't have any users assigned to it " . $this->id);
        }

        $this->increment('hits');
        $this->save();

        foreach ($agents as $agent) {
            $calculatedPercent = ($agent->hits / $this->hits) * 100;
            if ($calculatedPercent < $agent->percent) {
                $agent->increment('hits');
                $agent->save();

                return $agent->user;
            }
        }

        $agent = $agents->first();
        $agent->increment('hits');
        $agent->save();

        return $agent->user;
    }
}
