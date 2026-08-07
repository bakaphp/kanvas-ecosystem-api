<?php

declare(strict_types=1);

namespace App\GraphQL\Insurance\Queries;

use App\GraphQL\Concerns\ResolvesActingContext;
use InvalidArgumentException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\Contracts\CatalogProviderInterface;
use Kanvas\Insurance\Contracts\InsuranceProviderInterface;
use Kanvas\Insurance\DataTransferObject\InsuranceQuoteRequest;
use Kanvas\Insurance\Providers\InsuranceProviderFactory;

/**
 * The whole direct insurance surface: reference data to render the forms, and a
 * price to compare. Everything past "I'll take this one" rides the Order and its
 * workflow activities, like the other Movipass verticals.
 */
class InsuranceQuery
{
    use ResolvesActingContext;

    /**
     * @return array<array-key, mixed>
     */
    public function catalog(mixed $rootValue, array $request): array
    {
        $provider = $this->resolveProvider($request['provider'] ?? null);

        if (! $provider instanceof CatalogProviderInterface) {
            throw new ValidationException($provider->name() . ' does not expose catalogs');
        }

        return $provider->getCatalog(
            (string) $request['catalog'],
            (array) ($request['params'] ?? [])
        );
    }

    /**
     * Quoting persists nothing on our side — comparing five insurers must not leave
     * five half-built orders behind.
     *
     * @return array<string, mixed>
     */
    public function quote(mixed $rootValue, array $request): array
    {
        $provider = $this->resolveProvider($request['provider'] ?? null);

        $quote = $provider->quote(
            new InsuranceQuoteRequest(
                product: (string) $request['product'],
                payload: (array) ($request['input'] ?? []),
            )
        );

        return [
            'provider' => $providerName,
            'product' => (string) $request['product'],
            'success' => $quote->success,
            'message' => $quote->message,
            'quote_number' => $quote->quoteNumber,
            'premium' => $quote->premium,
            'tax' => $quote->tax,
            'total' => $quote->total,
            'currency' => $quote->currency,
            'data' => $quote->raw,
        ];
    }

    /**
     * The factory throws InvalidArgumentException, which Lighthouse masks as
     * "Internal server error" — a misconfigured company would get an opaque 500
     * instead of being told the insurer isn't set up.
     */
    protected function resolveProvider(?string $requested): InsuranceProviderInterface
    {
        $ctx = $this->actingContext();

        try {
            return InsuranceProviderFactory::make(
                InsuranceProviderFactory::resolveName($ctx->company, $requested),
                $ctx->app,
                $ctx->company
            );
        } catch (InvalidArgumentException $e) {
            throw new ValidationException($e->getMessage());
        }
    }
}
