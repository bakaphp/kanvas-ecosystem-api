<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem as OrderItemDto;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

class CreateVehicleFromOrderActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $order, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::MOVIPASS,
            additionalParams: $params,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) {
                $eventName = $additionalParams['currentEventTypeName'] ?? null;
                $skipReason = $this->getSkipReason($order, $eventName);

                if ($skipReason) {
                    return ['order' => $order->getId(), 'status' => 'skipped', 'message' => $skipReason];
                }

                $lastPaidPayment = Payments::getLatestForEntity($order, [PaymentStatusEnum::PAID->value]);

                if (! $lastPaidPayment || $lastPaidPayment->users_id == $order->users_id) {
                    return ['order' => $order->getId(), 'status' => 'skipped', 'message' => 'No different paying user found'];
                }

                $payingUser = Users::findOrFail($lastPaidPayment->users_id);
                $product = $this->createVehicleProduct($order, $app, $payingUser);
                $variant = $product->variants()->first();

                $order->addItem(new OrderItemDto(
                    app: $app,
                    variant: $variant,
                    name: $variant->name,
                    sku: $variant->sku,
                    quantity: 1,
                    price: 0.0,
                    tax: 0,
                    discount: 0,
                    currency: Currencies::getByCode($order->currency),
                ));

                $order->users_id = $payingUser->getId();
                $order->metadata = [
                    ...$order->metadata ?? [],
                    'data' => [
                        ...$order->metadata['data'] ?? [],
                        'variants_ids' => [
                            ...$order->metadata['data']['variants_ids'] ?? [],
                            'vehicle_variant_id' => $variant->getId(),
                        ],
                    ],
                ];
                $order->saveQuietly();

                activity()
                    ->causedBy($payingUser)
                    ->performedOn($order)
                    ->withProperties([
                        'order_id' => $order->getId(),
                        'previous_users_id' => $order->getOriginal('users_id'),
                        'new_users_id' => $payingUser->getId(),
                        'product_id' => $product->getId(),
                        'variant_id' => $variant->getId(),
                        'timestamp' => now(),
                    ])
                    ->log('VEHICLE_CREATED_FROM_ORDER');

                return [
                    'order' => $order->getId(),
                    'product' => $product->getId(),
                    'variant' => $variant->getId(),
                    'status' => 'success',
                    'message' => 'Vehicle created successfully',
                ];
            },
            company: $order->company,
        );
    }

    private function getSkipReason(Order $order, ?string $eventName): ?string
    {
        if ($order->orderType->name !== OrderTypeEnum::MOVIPASS->value) {
            return 'Order is not a movipass order';
        }
        if (! ($order->metadata['data']['is_manual'] ?? false)) {
            return 'Order is not a manual order';
        }
        if ($eventName !== WorkflowEnum::UPDATED->value) {
            return 'Event is not an update event';
        }
        if ($order->payment_status !== PaymentStatusEnum::PAID->value) {
            return 'Order is not paid';
        }
        if ($order->metadata['data']['variants_ids']['vehicle_variant_id'] ?? null) {
            return 'Vehicle already linked to this order';
        }

        return null;
    }

    private function createVehicleProduct(Order $order, AppInterface $app, Users $user): Products
    {
        $vehicleData = $order->metadata['data'] ?? [];
        $company = $order->company;

        $brand = $vehicleData['vehicleBrand'] ?? '';
        $model = $vehicleData['vehicleModel'] ?? '';
        $year = $vehicleData['vehicleYear'] ?? '';
        $vin = $vehicleData['vin'] ?? '';
        $plate = $vehicleData['vehiclePlate'] ?? '';
        $tagNumber = $vehicleData['tag_number'] ?? '';

        $username = $user->displayname ?? $user->name ?? $user->email;
        $userSlug = Str::slug($username);
        $brandSlug = Str::slug($brand);
        $modelSlug = Str::slug($model);

        $sku = implode('_', array_filter([$userSlug, $brandSlug, $modelSlug, $year]));
        $productName = trim("{$username}'s {$brand} {$model}");
        $description = "{$username}'s vehicle";

        $productsType = ProductsTypes::where('slug', 'vehicle')
            ->fromApp($app)
            ->notDeleted()
            ->first();

        $attributes = array_filter([
            $brand ? ['name' => 'brand', 'value' => $brand] : null,
            $model ? ['name' => 'model', 'value' => $model] : null,
            $year ? ['name' => 'year', 'value' => $year] : null,
            $vin ? ['name' => 'vin', 'value' => $vin] : null,
            $plate ? ['name' => 'plate', 'value' => $plate] : null,
            $tagNumber ? ['name' => 'tag_number', 'value' => $tagNumber] : null,
        ]);

        $orderUser = $order->user;

        $product = new CreateProductAction(
            new ProductDto(
                app: $app,
                company: $company,
                user: $orderUser,
                name: $productName,
                description: $description,
                productsType: $productsType,
                sku: $sku,
                attributes: array_values($attributes),
            ),
            $orderUser,
        )->setRunWorkflow(false)->execute();

        $product->users_id = $user->getId();
        $product->saveQuietly();

        return $product;
    }
}
