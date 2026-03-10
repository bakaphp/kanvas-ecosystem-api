<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Orders;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Actions\CreateDraftOrderAction;
use Kanvas\Souk\Orders\Actions\UpdateOrderAction;
use Kanvas\Souk\Orders\DataTransferObject\DraftOrder;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;

class DraftOrderManagementMutation
{
    public function create(mixed $root, array $request): Order
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $branch = app(CompaniesBranches::class);

        if ($branch->id === null) {
            throw new ValidationException('Missing Location Header');
        }

        $region = Regions::getByIdFromCompanyAppOrGlobal($request['input']['region_id'], $branch->company, $app);

        $log = activity('create-order-from-draft')
         ->causedBy($user)
         ->withProperties([
             'request_data' => $request,
             'user_id' => $user->id,
             'apps_id' => $app->getId(),
             'companies_id' => $branch->company->getId(),
         ])
         ->log('User attempted to create order from draft');

        $draftOrder = DraftOrder::from(
            $app,
            $branch,
            $user,
            $region,
            $request
        );

        $order = new CreateDraftOrderAction($draftOrder)->execute();

        $log->subject_type = get_class($order);
        $log->subject_id = $order->id;
        $log->description = 'User successfully created order from draft';
        $log->save();

        return $order;
    }

    public function updateStatus(mixed $root, array $request): Order
    {
        $user = auth()->user();
        $app = app(Apps::class);

        $order = Order::getById($request['order_id'], $app);

        if ($order->status !== OrderStatusEnum::DRAFT->value) {
            throw new ValidationException('Only draft orders can have their status updated through this mutation');
        }

        $newStatus = $request['status'];

        if ($newStatus === OrderStatusEnum::DRAFT->value) {
            throw new ValidationException('Order is already in draft status');
        }

        return (new UpdateOrderAction(
            $order,
            ['status' => $newStatus],
            $user
        ))->execute();
    }
}
