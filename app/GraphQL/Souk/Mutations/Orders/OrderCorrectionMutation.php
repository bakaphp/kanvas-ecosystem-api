<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Orders;

use Illuminate\Support\Facades\Gate;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\Corrections\AddObservationsAction;
use Kanvas\Connectors\Movipass\Actions\Corrections\CorrectVehiclePlateAction;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;

class OrderCorrectionMutation
{
    public function correct(mixed $rootValue, array $request): Order
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
            default => throw new ValidationException("Unknown correction type: {$correctionType}"),
        };
    }
}
