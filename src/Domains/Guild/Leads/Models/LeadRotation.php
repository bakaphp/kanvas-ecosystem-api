<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Models;

use Baka\Casts\Json;
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

    protected $casts = [
        'config' => Json::class,
    ];

    public function agents(): HasMany
    {
        return $this->hasMany(LeadRotationAgent::class, 'leads_rotations_id');
    }

    public function activeAgents(): HasMany
    {
        return $this->agents()->where('is_deleted', 0);
    }

    public function getActiveUsers(): Collection
    {
        return $this->activeAgents()
            ->with('users')
            ->get()
            ->pluck('users')
            ->filter(fn ($user) => $user && ! $user->is_deleted && $user->isActive());
    }

    public function getLeadsRotationsAgents(): Collection
    {
        // Get agents in a consistent order instead of random
        return $this->activeAgents()->orderBy('id')->get();
    }

    public function getAgent(): UserInterface
    {
        $agents = $this->getLeadsRotationsAgents();
        if ($agents->isEmpty()) {
            throw new RuntimeException("This rotation doesn't have any users assigned to it " . $this->id);
        }

        $this->increment('hits');
        $this->save();

        $totalHits = (float) $this->hits;

        // Calculate current percentage for each agent but don't store it as a model attribute
        $eligibleAgents = $agents->filter(function ($agent) use ($totalHits) {
            $currentPercent = $totalHits > 0.0 ? ((float) $agent->hits / $totalHits) * 100.0 : 0.0;

            return $currentPercent < (float) $agent->percent;
        });

        // If no agents are below their percentage, reset hits to maintain the ratio
        if ($eligibleAgents->isEmpty()) {
            // Reset all agent hits to maintain the ratio going forward
            $resetRatio = 0.5;
            foreach ($agents as $agent) {
                $agent->hits = (int) ((float) $agent->hits * $resetRatio);
                $agent->save();
            }
            $totalHits = (float) max(1, (int) ($totalHits * $resetRatio));
            $this->hits = (int) $totalHits;
            $this->save();

            // Now select the agent with the largest deficit compared to their target percentage
            $agent = $agents->sortBy(function ($agent) use ($totalHits) {
                $currentPercent = ((float) $agent->hits / $totalHits) * 100.0;
                $percent = (float) $agent->percent;

                return $percent > 0.0 ? $currentPercent / $percent : PHP_FLOAT_MAX;
            })->first();
        } else {
            // Find the agent with the largest percentage deficit
            $agent = $eligibleAgents->sortBy(function ($agent) use ($totalHits) {
                $currentPercent = ((float) $agent->hits / $totalHits) * 100.0;
                $percent = (float) $agent->percent;

                return $percent > 0.0 ? $currentPercent / $percent : PHP_FLOAT_MAX;
            })->first();
        }

        $agent->increment('hits');
        $agent->save();

        return $agent->user;
    }
}
