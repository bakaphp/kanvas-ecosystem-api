<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Queries;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppEnums;
use Kanvas\Souk\Shipping\Actions\BuildShipmentRequestAction;
use Kanvas\Souk\Shipping\Actions\GetShippingQuotesAction;
use Kanvas\Souk\Shipping\Actions\ResolveShippingContextAction;
use Kanvas\Souk\Shipping\DataTransferObject\ShippingDestination;
use Kanvas\Souk\Shipping\DataTransferObject\ShippingQuote;

class ShippingQuery
{
    public function quotes(mixed $root, array $request): array
    {
        $context = new ResolveShippingContextAction(
            app: app(Apps::class),
            user: auth()->user(),
            branch: app()->bound(CompaniesBranches::class) ? app(CompaniesBranches::class) : null,
        )->executeOrFail();
        $cart = app('cart')->session(app(AppEnums::KANVAS_IDENTIFIER->getValue()));

        $shipmentRequest = new BuildShipmentRequestAction(
            app: $context->app,
            cart: $cart,
            destination: ShippingDestination::forInput($request['destination']),
        )->execute();

        $quotes = new GetShippingQuotesAction(
            app: $context->app,
            company: $context->company,
            region: $context->region,
            request: $shipmentRequest,
        )->execute();

        return array_map(
            fn (ShippingQuote $quote): array => [
                'provider' => $quote->provider,
                'service_code' => $quote->serviceCode,
                'service_name' => $quote->serviceName,
                'amount' => $quote->amount,
                'currency' => $quote->currency,
                'transit_min_days' => $quote->transitMinDays,
                'transit_max_days' => $quote->transitMaxDays,
            ],
            $quotes
        );
    }
}
