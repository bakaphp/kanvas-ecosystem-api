<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Movipass\Actions\GetAvailableMechanicsAction;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideServiceTypeEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

/**
 * Who an Asistencia Regional case may be offered to (spec v6.0 §10.6): available, a member of the
 * order's app, authorized for the service, inside the cabin's shortlist when it set one, and not
 * someone who already rejected or dropped this case.
 *
 * A technician with no service list configured is treated as authorized for every service, so a
 * fleet that has not been parametrized yet still gets orders.
 */
class RoadsideTechnicianPool
{
    public function eligibleFor(Order $order, array $assistanceCase): Collection
    {
        $excluded = array_values(array_unique(array_map('intval', [
            ...(array) ($assistanceCase['rejected_mechanic_ids'] ?? []),
            ...(array) ($assistanceCase['cancelled_mechanic_ids'] ?? []),
        ])));
        $shortlist = array_map('intval', (array) ($assistanceCase['eligible_mechanic_ids'] ?? []));
        $service = RoadsideServiceTypeEnum::tryFrom((string) ($assistanceCase['service_type'] ?? ''));

        $available = new GetAvailableMechanicsAction($excluded)->execute();

        if ($available->isEmpty()) {
            return $available;
        }

        $appMembers = DB::connection($available->first()->getConnectionName())
            ->table('users_associated_apps')
            ->where('apps_id', $order->apps_id)
            ->where('is_deleted', 0)
            ->whereIn('users_id', $available->pluck('id')->all())
            ->pluck('users_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $available
            ->filter(fn (Users $mechanic) => in_array($mechanic->getId(), $appMembers, true))
            ->filter(fn (Users $mechanic) => $shortlist === [] || in_array($mechanic->getId(), $shortlist, true))
            ->filter(fn (Users $mechanic) => $this->isAuthorizedFor($mechanic, $service))
            ->values();
    }

    public function isAuthorizedFor(Users $mechanic, ?RoadsideServiceTypeEnum $service): bool
    {
        $configured = (string) ($mechanic->get(CustomFieldEnum::MECHANIC_SERVICE_TYPE->value) ?? '');
        $authorized = array_filter(array_map(
            static fn (string $label) => RoadsideServiceTypeEnum::tryFromLabel($label),
            explode(',', $configured),
        ));

        return $service === null || $authorized === [] || in_array($service, $authorized, true);
    }
}
