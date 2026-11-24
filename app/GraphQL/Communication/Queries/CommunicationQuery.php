<?php

declare(strict_types=1);

namespace App\GraphQL\Communication\Queries;

use App\GraphQL\Communication\Presenters\CommunicationPresenter;
use App\GraphQL\Concerns\ResolvesActingContext;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Kanvas\Communication\Channels\WhatsApp\Providers\Meta\Actions\ListCompanyPhoneNumbersAction;
use Kanvas\Communication\Channels\WhatsApp\Providers\Meta\Client;
use Kanvas\Communication\Communication;
use Kanvas\Communication\Connections\Models\Connection;
use RuntimeException;
use Throwable;

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

    /**
     * Best-effort: an empty list when the company has not connected WhatsApp or Meta cannot be
     * reached, so a number picker shows "no numbers" rather than an error. Only the second is worth
     * a log line, and it names the failure, never the request: a connection error carries its URL.
     */
    public function metaWhatsAppPhoneNumbers(): array
    {
        $ctx = $this->actingContext();

        try {
            return new ListCompanyPhoneNumbersAction($ctx->app, $ctx->company)->execute();
        } catch (RuntimeException) {
            return [];
        } catch (Throwable $exception) {
            Log::warning('Meta WhatsApp numbers could not be listed', [
                'company_id' => $ctx->company->getId(),
                'exception' => $exception::class,
                'status' => $exception instanceof RequestException ? $exception->response->status() : null,
                'meta_error' => $exception instanceof RequestException ? Client::errorMessage($exception) : null,
            ]);

            return [];
        }
    }

    public function operation(mixed $root, array $request): array
    {
        $ctx = $this->actingContext();

        return CommunicationPresenter::operation(
            Communication::for($ctx->app, $ctx->company)->operation((int) $request['id'])
        );
    }
}
