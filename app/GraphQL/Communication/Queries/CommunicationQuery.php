<?php

declare(strict_types=1);

namespace App\GraphQL\Communication\Queries;

use App\GraphQL\Communication\Presenters\CommunicationPresenter;
use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Communication\Communication;
use Kanvas\Communication\Connections\Models\Connection;

class CommunicationQuery
{
    use ResolvesActingContext;

    /**
     * Disconnected rows are history, not something to switch on or off, so only live ones are listed.
     */
    public function connections(mixed $root, array $request): array
    {
        $ctx = $this->actingContext();
        $channel = $request['channel'] ?? null;

        return Connection::query()
            ->fromApp($ctx->app)
            ->fromCompany($ctx->company)
            ->live()
            ->orderBy('provider')
            ->orderBy('account_key')
            ->get()
            ->map(fn (Connection $connection): array => CommunicationPresenter::connection($connection))
            ->filter(fn (array $connection): bool => $channel === null || in_array($channel, $connection['channels'], true))
            ->values()
            ->all();
    }

    public function operation(mixed $root, array $request): array
    {
        $ctx = $this->actingContext();

        return CommunicationPresenter::operation(
            Communication::for($ctx->app, $ctx->company)->operation((int) $request['id'])
        );
    }
}
