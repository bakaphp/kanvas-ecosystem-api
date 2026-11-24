<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Meta\Queries;

use App\GraphQL\Concerns\ResolvesActingContext;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\Meta\Actions\ListCompanyPhoneNumbersAction;
use Kanvas\Connectors\Meta\Client;
use Throwable;

/**
 * Best-effort: an empty list when Meta cannot be reached, so a number picker shows "no numbers"
 * rather than an error. The log line names the failure, never the request: a connection error
 * carries its URL.
 */
class MetaWhatsAppPhoneNumbersQuery
{
    use ResolvesActingContext;

    /**
     * @return list<array<string, mixed>>
     */
    public function __invoke(): array
    {
        $ctx = $this->actingContext();

        try {
            return new ListCompanyPhoneNumbersAction($ctx->app, $ctx->company)->execute();
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
}
