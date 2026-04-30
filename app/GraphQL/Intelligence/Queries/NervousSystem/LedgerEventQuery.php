<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Queries\NervousSystem;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\Ledger\Models\Event;

class LedgerEventQuery
{
    public function getEvents(mixed $rootValue, array $args): Builder
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $query = Event::query()
            ->where('apps_id', $app->getId())
            ->orderByDesc('occurred_at');

        if ($user instanceof UserInterface && ! $user->isAppOwner()) {
            $query->where('companies_id', $user->getCurrentCompany()->getId());
        }

        return $query;
    }
}
