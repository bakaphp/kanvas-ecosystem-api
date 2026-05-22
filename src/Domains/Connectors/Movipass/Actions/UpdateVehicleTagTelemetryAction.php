<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\PasoRapido\Enums\ConfigurationEnum as PasoRapidoConfigurationEnum;
use Kanvas\Connectors\PasoRapido\Services\PasoRapidoService;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Souk\Orders\Models\Order;
use Throwable;

class UpdateVehicleTagTelemetryAction
{
    private const DEFAULT_TAG_ATTRIBUTE_SLUG = 'tag-number';

    public function __construct(
        protected readonly Order $order,
        protected readonly AppInterface $app,
        protected readonly string $tag,
        protected readonly float $rechargeAmount,
        protected readonly ?PasoRapidoService $service = null,
    ) {
    }

    public function execute(): array
    {
        if (trim($this->tag) === '') {
            return ['status' => 'skipped', 'reason' => 'empty tag'];
        }

        $product = $this->findVehicleProduct();

        if (! $product instanceof Products) {
            return [
                'status' => 'skipped',
                'reason' => 'vehicle product not found for tag',
                'tag' => $this->tag,
            ];
        }

        $now = Carbon::now();
        $attributes = [
            ['name' => 'last_recharge_date', 'value' => $now->toIso8601String()],
            ['name' => 'last_recharge_amount', 'value' => (string) $this->rechargeAmount],
            ['name' => 'last_order_id', 'value' => (string) $this->order->getId()],
        ];

        $balance = null;
        $balanceError = null;

        try {
            $service = $this->service ?? new PasoRapidoService($this->app, $this->order->company);
            $verifyResponse = $service->fetchTagBalance($this->tag);
            $balance = $verifyResponse->balance;

            $attributes[] = ['name' => 'tag_balance', 'value' => (string) $balance];
            $attributes[] = ['name' => 'tag_balance_fetched_at', 'value' => $now->toIso8601String()];
        } catch (Throwable $e) {
            report($e);
            $balanceError = $e->getMessage();
        }

        $product->addAttributes($this->order->user, $attributes);
        $product->save();

        return [
            'status' => 'success',
            'product_id' => $product->getId(),
            'tag' => $this->tag,
            'last_recharge_amount' => $this->rechargeAmount,
            'tag_balance' => $balance,
            'tag_balance_error' => $balanceError,
        ];
    }

    private function findVehicleProduct(): ?Products
    {
        $tagSlug = $this->app->get(PasoRapidoConfigurationEnum::VERIFY_TAG_ATTRIBUTE_SLUG->value)
            ?? self::DEFAULT_TAG_ATTRIBUTE_SLUG;

        return Products::query()
            ->from('products as p')
            ->withoutGlobalScopes()
            ->join('products_attributes as pa', 'p.id', '=', 'pa.products_id')
            ->join('attributes as a', 'pa.attributes_id', '=', 'a.id')
            ->where('a.slug', '=', $tagSlug)
            ->whereRaw('JSON_UNQUOTE(JSON_EXTRACT(pa.value, \'$.en\')) = ?', [$this->tag])
            ->where('p.apps_id', '=', $this->app->getId())
            ->where('p.companies_id', '=', $this->order->company->getId())
            ->where('p.is_deleted', '=', 0)
            ->select('p.*')
            ->first();
    }
}
