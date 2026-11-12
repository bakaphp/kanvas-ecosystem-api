<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\BrushCrazy;

use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Models\Order;

trait CreatesQuietOrders
{
    /**
     * withoutEvents() also mutes UuidTrait's creating hook, on the order and on the People the
     * factory would create inside it; both have a NOT NULL uuid, so the uuid and the People are
     * supplied from outside the muted block.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function quietOrder(int $companyId, array $attributes, bool $completed = true): Order
    {
        $user = auth()->user();
        $people = People::factory()->create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $companyId,
            'users_id' => $user->getId(),
        ]);

        $factory = Order::factory()
            ->withCompanyId($companyId)
            ->withUserId($user->getId())
            ->withPeopleId($people->getId());

        if ($completed) {
            $factory = $factory->completed();
        }

        return Order::withoutEvents(fn () => $factory->create(
            $attributes + ['uuid' => Str::uuid()->toString()]
        ));
    }
}
