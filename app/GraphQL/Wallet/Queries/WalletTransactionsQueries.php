<?php

declare(strict_types=1);

namespace App\GraphQL\Wallet\Queries;

use Kanvas\Social\Reactions\Repositories\UserReactionRepository;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\Wallet\Actions\GetWalletBalanceAction;

class WalletTransactionsQueries
{
    public function getWalletBalance(mixed $root, array $request): Collection
    {
        return (new GetWalletBalanceAction(auth()->user()))->execute;
    }

    public function getWalletBalanceHistory(mixed $root, array $request): Collection
    {
        if (key_exists('entity_namespace', $request)) {
            $systemModule = SystemModulesRepository::getByUuidOrModelName($request['entity_namespace']);
        }

        return UserReactionRepository::getUserReactionGroupBy($systemModule->model_name ?? null, $req['entity_id'] ?? null);
    }
}
