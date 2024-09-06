<?php

declare(strict_types=1);

namespace App\GraphQL\Subscriptions\Mutations\Plans;

use Kanvas\Subscription\Plans\Repositories\PlanRepository;
use Kanvas\Subscription\Plans\Models\Plan;
use Illuminate\Support\Facades\Auth;

class PlanMutation
{
    /**
     * create.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return Plan
     */
    public function create(mixed $root, array $req): Plan
    {
        $plan = PlanRepository::create($req['input']);
        return $plan;
    }

    /**
     * update.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return Plan
     */
    public function update(mixed $root, array $req): Plan
    {
        $plan = PlanRepository::update($req['id'], $req['input']);
        return $plan;
    }

    /**
     * delete.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return bool
     */
    public function delete(mixed $root, array $req): bool
    {
        return PlanRepository::delete($req['id']);
    }
}