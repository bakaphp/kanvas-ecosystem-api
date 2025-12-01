<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Orders;

use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Models\Order;

class OrderCustomerManagementMutation
{
    public function changeCustomer(mixed $root, array $request): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $orderId = (int) $request['order_id'];
        $customerId = (int) $request['customer_id'];

        $order = Order::getByIdFromCompanyApp($orderId, $company, $app);
        $people = People::getByIdFromCompanyApp($customerId, $company, $app);

        if ($order->isFulfilled() && ! $app->get('ALLOW_USERS_UPDATE_ORDERS') && ! $user->isAdmin()) {
            throw new ValidationException('Order is already fulfilled');
        }

        $order->people_id = $people->getKey();
        $order->user_email = $people->getEmails()->first()?->email ?? $order->user_email;
        $order->user_phone = $people->getPhones()->first()?->phone ?? $order->user_phone;

        return $order->saveOrFail();
    }
}
