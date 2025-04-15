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

    public function getLeadsRotationsAgents(): Collection
    {
        // Get agents in a consistent order instead of random
        return $this->agents()->where('is_deleted', 0)->orderBy('id')->get();
    }

    public function getAgent(): UserInterface
    {
        $agents = $this->getLeadsRotationsAgents();
        if ($agents->isEmpty()) {
            throw new RuntimeException("This rotation doesn't have any users assigned to it " . $this->id);
        }

        $this->increment('hits');
        $this->save();

        // Calculate current percentage for each agent
        $agentsWithCurrentPercent = $agents->map(function ($agent) {
            $agent->currentPercent = ($agent->hits / $this->hits) * 100;

            return $agent;
        });

        // Find agents below their target percentage
        $eligibleAgents = $agentsWithCurrentPercent->filter(function ($agent) {
            return $agent->currentPercent < $agent->percent;
        });

        // If no agents are below their percentage, reset hits to maintain the ratio
        if ($eligibleAgents->isEmpty()) {
            // Optional: Reset all agent hits to maintain the ratio going forward
            // This prevents the numbers from growing too large over time
            $resetRatio = 0.5; // Reset to 50% of current values
            foreach ($agents as $agent) {
                $agent->hits = intval($agent->hits * $resetRatio);
                $agent->save();
            }
            $this->hits = intval($this->hits * $resetRatio);
            $this->save();

            // Now select the agent with the largest deficit compared to their target percentage
            $agent = $agentsWithCurrentPercent->sortBy(function ($agent) {
                return $agent->currentPercent / $agent->percent;
            })->first();
        } else {
            // Find the agent with the largest percentage deficit
            $agent = $eligibleAgents->sortBy(function ($agent) {
                return $agent->currentPercent / $agent->percent;
            })->first();
        }

        $agent->increment('hits');
        $agent->save();

        return $agent->user;
    }
}
