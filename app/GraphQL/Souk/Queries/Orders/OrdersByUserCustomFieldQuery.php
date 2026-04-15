<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Queries\Orders;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Actions\GetOrdersByUserCustomFieldAction;

class OrdersByUserCustomFieldQuery
{
    public function build(mixed $root, array $args): Builder
    {
        $customFieldName = $args['custom_field_name'];
        $app = app(Apps::class);
        $user = auth()->user();

        $userId = $user->getId();

        if (isset($args['user_id'])) {
            if (! $user->isAdmin()) {
                throw new ValidationException('Only admins can query orders for other users');
            }
            $userId = (int) $args['user_id'];
        }

        return new GetOrdersByUserCustomFieldAction(
            $app,
            $customFieldName,
            $userId,
        )->execute();
    }
}
