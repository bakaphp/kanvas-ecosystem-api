<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros\Actions;

use Kanvas\Connectors\UniversalSeguros\DataTransferObject\QuoteRequest;
use Kanvas\Connectors\UniversalSeguros\Enums\CustomFieldEnum;
use Kanvas\Connectors\UniversalSeguros\Enums\InsuranceOrderStatusEnum;
use Kanvas\Connectors\UniversalSeguros\Enums\ProductEnum;
use Kanvas\Connectors\UniversalSeguros\Services\UniversalSegurosService;
use Kanvas\Souk\Orders\Models\Order;

// Caller maps the Order's people/vehicle into $input — see connector CLAUDE.md.
class CreateQuoteAction
{
    /**
     * @param array<string, mixed> $input
     */
    public function __construct(
        protected Order $order,
        protected ProductEnum $product,
        protected array $input,
    ) {
    }

    public function execute(): array
    {
        $service = new UniversalSegurosService($this->order->app, $this->order->company);

        $response = $service->quote(QuoteRequest::make($this->product, $this->input));

        $quoteNumber = (string) ($response['numeroCotizacion'] ?? '');
        $terminos = $response['data']['terminos'] ?? [];

        $this->order->set(CustomFieldEnum::PRODUCT->value, $this->product->value);
        $this->order->set(CustomFieldEnum::STATUS->value, InsuranceOrderStatusEnum::QUOTED->value);

        if (isset($this->input['requestId'])) {
            $this->order->set(CustomFieldEnum::REQUEST_ID->value, (string) $this->input['requestId']);
        }

        if ($quoteNumber !== '') {
            $this->order->set(CustomFieldEnum::QUOTE_NUMBER->value, $quoteNumber);
        }

        if (is_array($terminos)) {
            $this->order->set(CustomFieldEnum::PRIMA->value, $terminos['prima'] ?? null);
            $this->order->set(CustomFieldEnum::IMPUESTO->value, $terminos['impuesto'] ?? null);
            $this->order->set(CustomFieldEnum::TOTAL_COBRO->value, $terminos['totalCobro'] ?? null);
        }

        return $response;
    }
}
