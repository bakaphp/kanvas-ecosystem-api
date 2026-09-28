<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideProviderEndpointEnum;
use Kanvas\Connectors\Movipass\Services\RoadsideProviderClient;
use Kanvas\Connectors\Movipass\Support\RoadsideProviderCaseNumber;
use Kanvas\Exceptions\ValidationException;

/**
 * The provider has no webhooks, so this read is the only way a state change made on their side
 * (provider reassigned, case closed by their operator) reaches us.
 */
class PullRoadsideProviderCaseAction
{
    public function __construct(private readonly Model $entity)
    {
    }

    public function execute(): array
    {
        $number = RoadsideProviderCaseNumber::for($this->entity);

        if ($number === null) {
            throw new ValidationException(
                'Cannot pull an order that has no roadside assistance provider case number',
            );
        }

        $response = new RoadsideProviderClient($this->entity->app)->get(
            RoadsideProviderEndpointEnum::ASSISTANCE->value,
            ['numeroAsistencia' => $number],
        );

        $this->entity->set(
            CustomFieldEnum::ROADSIDE_PROVIDER_LAST_POLLED_AT->value,
            Carbon::now()->toISOString(),
        );

        return $response;
    }
}
