<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Orders;

use Illuminate\Support\Facades\Gate;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\Corrections\AddObservationsAction;
use Kanvas\Connectors\Movipass\Actions\Corrections\AddOrderItemAction;
use Kanvas\Connectors\Movipass\Actions\Corrections\AssociatePaymentToOrderAction;
use Kanvas\Connectors\Movipass\Actions\Corrections\CorrectVehiclePlateAction;
use Kanvas\Connectors\Movipass\Actions\Corrections\MarkOrderAsDuplicateAction;
use Kanvas\Connectors\Movipass\Actions\Corrections\RelocateVehicleAction;
use Kanvas\Connectors\Movipass\Actions\Corrections\RemoveOrderItemAction;
use Kanvas\Connectors\Movipass\Actions\Corrections\UpdateOrderItemAction;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Models\Payments;

class OrderCorrectionMutation
{
    public function amend(mixed $rootValue, array $request): Order
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $correctionType = $request['correction_type'];

        // ability-name only — a model arg would trip the module-access gate (view-module-commerce), which order mutations don't require
        Gate::authorize($correctionType);

        $order = Order::getByIdFromCompanyApp(
            (int) $request['order_id'],
            $company,
            $app,
        );

        $reason = $request['reason'];
        $evidenceUrls = $request['evidence_urls'] ?? [];
        $data = $request['data'] ?? [];

        return match ($correctionType) {
            'correct-plate' => new CorrectVehiclePlateAction(
                $order,
                $user,
                (string) ($data['new_plate'] ?? throw new ValidationException('data.new_plate is required for correct-plate')),
                $reason,
                $evidenceUrls,
            )->execute(),
            'add-observations' => new AddObservationsAction(
                $order,
                $user,
                (string) ($data['observations'] ?? throw new ValidationException('data.observations is required for add-observations')),
                $reason,
                $evidenceUrls,
            )->execute(),
            'update-item' => new UpdateOrderItemAction(
                $order,
                $user,
                (int) ($data['order_item_id'] ?? throw new ValidationException('data.order_item_id is required for update-item')),
                $reason,
                $evidenceUrls,
                newAmount: isset($data['new_amount']) ? (float) $data['new_amount'] : null,
                newQuantity: isset($data['new_quantity']) ? (int) $data['new_quantity'] : null,
            )->execute(),
            'add-item' => new AddOrderItemAction(
                $order,
                $user,
                (int) ($data['variant_id'] ?? throw new ValidationException('data.variant_id is required for add-item')),
                (int) ($data['quantity'] ?? 1),
                (float) ($data['amount'] ?? throw new ValidationException('data.amount is required for add-item')),
                $reason,
                $evidenceUrls,
            )->execute(),
            'remove-item' => new RemoveOrderItemAction(
                $order,
                $user,
                (int) ($data['order_item_id'] ?? throw new ValidationException('data.order_item_id is required for remove-item')),
                $reason,
                $evidenceUrls,
            )->execute(),
            'mark-duplicate' => new MarkOrderAsDuplicateAction(
                $order,
                $user,
                Order::getByIdFromCompanyApp(
                    (int) ($data['original_order_id'] ?? throw new ValidationException('data.original_order_id is required for mark-duplicate')),
                    $company,
                    $app,
                ),
                $reason,
                $evidenceUrls,
            )->execute(),
            'associate-payment' => new AssociatePaymentToOrderAction(
                $order,
                $user,
                Payments::fromApp($app)->fromCompany($company)
                    ->where('uuid', (string) ($data['payment_uuid'] ?? throw new ValidationException('data.payment_uuid is required for associate-payment')))
                    ->firstOrFail(),
                $reason,
                $evidenceUrls,
            )->execute(),
            'relocate' => new RelocateVehicleAction(
                $order,
                $user,
                (int) ($data['variant_id'] ?? throw new ValidationException('data.variant_id is required for relocate')),
                (array) ($data['car_deposit'] ?? throw new ValidationException('data.car_deposit is required for relocate')),
                $reason,
                $evidenceUrls,
                isset($data['current_variant_id']) ? (int) $data['current_variant_id'] : null,
            )->execute(),
            default => throw new ValidationException("Unknown correction type: {$correctionType}"),
        };
    }
}
