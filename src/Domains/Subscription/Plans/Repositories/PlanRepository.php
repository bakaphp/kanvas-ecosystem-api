<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Plans\Repositories;

use Kanvas\Subscription\Plans\Models\Plan;
use Illuminate\Database\Eloquent\Model;

class PlanRepository
{
    public static function create(array $data): Plan
    {
        return Plan::create($data);
    }

    public static function update(int $id, array $data): Plan
    {
        $plan = Plan::findOrFail($id);
        $plan->update($data);
        return $plan;
    }

    public static function delete(int $id): bool
    {
        $plan = Plan::findOrFail($id);
        return $plan->delete();
    }
}