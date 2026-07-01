<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Orders;

use Illuminate\Support\Facades\Gate;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\Corrections\AddObservationsAction;
use Kanvas\Connectors\Movipass\Actions\Corrections\AdjustOrderItemAmountAction;
use Kanvas\Connectors\Movipass\Actions\Corrections\AssociatePaymentToOrderAction;
use Kanvas\Connectors\Movipass\Actions\Corrections\CorrectVehiclePlateAction;
use Kanvas\Connectors\Movipass\Actions\Corrections\MarkOrderAsDuplicateAction;
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

        Gate::authorize($correctionType, Order::class);

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
            'adjust-amount' => new AdjustOrderItemAmountAction(
                $order,
                $user,
                (float) ($data['new_amount'] ?? throw new ValidationException('data.new_amount is required for adjust-amount')),
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
            default => throw new ValidationException("Unknown correction type: {$correctionType}"),
        };
    }
}
