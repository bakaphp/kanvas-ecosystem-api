<?php

declare(strict_types=1);

namespace App\GraphQL\Communication\Queries;

use App\GraphQL\Communication\Presenters\CommunicationPresenter;
use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Communication\Communication;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Support\ProviderRegistry;

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
        $providers = app(ProviderRegistry::class)->all();

        return Connection::query()
            ->fromApp($ctx->app)
            ->fromCompany($ctx->company)
            ->live()
            ->orderBy('provider')
            ->orderBy('account_key')
            ->get()
            ->filter(fn (Connection $connection): bool => $channel === null
                || array_key_exists($channel, isset($providers[$connection->provider]) ? $providers[$connection->provider]::transports() : []))
            ->map(fn (Connection $connection): array => CommunicationPresenter::connection($connection))
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
