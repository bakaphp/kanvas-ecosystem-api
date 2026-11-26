<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Support;

use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;

/**
 * The roadside assistance order statuses and every transition the flow performs. The setup command
 * and the tests both build from this one map: a transition missing here is not an error at runtime,
 * Order::transitionToStatus() just leaves the order where it was.
 */
final class RoadsideAssistanceStatusMap
{
    /**
     * @return array<string, array{name: string, is_default?: bool, is_final?: bool, transitions?: array<int, string>}>
     */
    public static function definition(): array
    {
        $requestSubmitted = MovipassOrderStatusEnum::REQUEST_SUBMITTED->value;
        $awaitingOperator = MovipassOrderStatusEnum::AWAITING_OPERATOR->value;
        $cancelled = MovipassOrderStatusEnum::SERVICE_CANCELLED->value;
        $notResolved = MovipassOrderStatusEnum::SERVICE_COMPLETED_NOT_RESOLVED->value;
        // Declining is only reachable before a provider is assigned; once a unit is on the way the
        // case has to be cancelled, not declined.
        $notAuthorized = MovipassOrderStatusEnum::SERVICE_NOT_AUTHORIZED->value;

        // Closing with an incident is allowed from every open stage: an incident can be registered
        // before a unit accepts, and a rescheduled case sits back in request_submitted.
        return [
            $requestSubmitted => [
                'name' => 'Request submitted',
                'is_default' => true,
                'transitions' => [$awaitingOperator, $notResolved, $cancelled, $notAuthorized],
            ],
            $awaitingOperator => [
                'name' => 'Awaiting operator',
                'transitions' => [
                    MovipassOrderStatusEnum::PROVIDER_ASSIGNED->value,
                    $requestSubmitted,
                    $notResolved,
                    $cancelled,
                    $notAuthorized,
                ],
            ],
            MovipassOrderStatusEnum::PROVIDER_ASSIGNED->value => [
                'name' => 'Provider assigned',
                'transitions' => [
                    MovipassOrderStatusEnum::DISPATCHED->value,
                    $requestSubmitted,
                    $notResolved,
                    $cancelled,
                ],
            ],
            MovipassOrderStatusEnum::DISPATCHED->value => [
                'name' => 'Dispatched',
                'transitions' => [
                    MovipassOrderStatusEnum::ON_SITE->value,
                    $requestSubmitted,
                    $notResolved,
                    $cancelled,
                ],
            ],
            MovipassOrderStatusEnum::ON_SITE->value => [
                'name' => 'On site',
                'transitions' => [
                    MovipassOrderStatusEnum::SERVICE_IN_PROGRESS->value,
                    $requestSubmitted,
                    $notResolved,
                    $cancelled,
                ],
            ],
            MovipassOrderStatusEnum::SERVICE_IN_PROGRESS->value => [
                'name' => 'Service in progress',
                'transitions' => [
                    MovipassOrderStatusEnum::SERVICE_COMPLETED->value,
                    $notResolved,
                    $requestSubmitted,
                    $cancelled,
                ],
            ],
            MovipassOrderStatusEnum::SERVICE_COMPLETED->value => [
                'name' => 'Service completed',
                'is_final' => true,
            ],
            $notResolved => [
                'name' => 'Service not resolved',
                'is_final' => true,
            ],
            $cancelled => [
                'name' => 'Service cancelled',
                'is_final' => true,
            ],
            $notAuthorized => [
                'name' => 'Service not authorized',
                'is_final' => true,
            ],
        ];
    }
}
