<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Movipass\Mutations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\Corrections\CorrectVehiclePlateAction;
use Kanvas\Souk\Orders\Models\Order;

class ImpoundCorrectionsMutation
{
    public function correctPlate(mixed $rootValue, array $request): Order
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $order = Order::getByIdFromCompanyApp(
            (int) $request['order_id'],
            $company,
            $app,
        );

        return new CorrectVehiclePlateAction(
            $order,
            $user,
            $request['input']['new_plate'],
            $request['input']['reason'],
            $request['input']['evidence_urls'] ?? [],
        )->execute();
    }
}
