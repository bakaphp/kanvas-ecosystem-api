<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Movipass\Mutations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\SetMechanicServiceTypeAction;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UserAppRepository;

class MechanicMutation
{
    public function setServiceType(mixed $rootValue, array $request): Users
    {
        $app = app(Apps::class);

        // App-scoped lookup (same base the `mechanics` listing uses) so the target belongs to
        // the current app, without coupling the lookup to the Bouncer role scope.
        /** @var Users $mechanic */
        $mechanic = UserAppRepository::getAllAppUsers($app)
            ->where('users.id', (int) $request['id'])
            ->firstOrFail();

        return new SetMechanicServiceTypeAction(
            mechanic: $mechanic,
            serviceType: (string) $request['service_type'],
        )->execute();
    }
}
