<?php

declare(strict_types=1);

namespace Kanvas\Souk\Insurance\Infrastructure\Processors\UniversalSeguros;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\UniversalSeguros\Actions\CreateQuoteAction;
use Kanvas\Connectors\UniversalSeguros\Actions\EmitPolicyAction;
use Kanvas\Connectors\UniversalSeguros\Actions\RequestPaymentLinkAction;
use Kanvas\Connectors\UniversalSeguros\Enums\ProductEnum;
use Kanvas\Souk\Insurance\Contracts\InsuranceProcessorInterface;
use Kanvas\Souk\Orders\Models\Order;

/**
 * Adapter bridging the Universal Seguros connector Actions (which are Order-driven and
 * already resolve app/company off the Order) to the provider-agnostic
 * InsuranceProcessorInterface consumed by the generic `insurance*` GraphQL mutations.
 */
class UniversalSegurosProcessor implements InsuranceProcessorInterface
{
    public function __construct(
        protected Apps $app,
        protected Companies $company,
    ) {
    }

    public function name(): string
    {
        return 'universal_seguros';
    }

    public function createQuote(Order $order, string $product, array $input): array
    {
        return new CreateQuoteAction($order, ProductEnum::from($product), $input)->execute();
    }

    public function requestPaymentLink(Order $order, bool $byEmail = false): array
    {
        return new RequestPaymentLinkAction($order, $byEmail)->execute();
    }

    public function emitPolicy(Order $order): array
    {
        return new EmitPolicyAction($order)->execute();
    }
}
