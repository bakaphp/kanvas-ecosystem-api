<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Models;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Guild\Models\BaseModel;
use RuntimeException;

/**
 * Class LeadRotation.
 *
 * @property int $id
 * @property int|null $apps_id
 * @property int $companies_id
 * @property string $name
 * @property string $leads_rotations_email
 * @property int $hits
 * @property string $created_at
 * @property string $updated_at
 * @property int $is_deleted
 */
class LeadRotation extends BaseModel
{
    protected $table = 'leads_rotations';
    protected $guarded = [];

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
