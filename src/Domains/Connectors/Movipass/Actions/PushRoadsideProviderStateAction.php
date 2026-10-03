<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Movipass\Enums\ConfigurationEnum;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideProviderEndpointEnum;
use Kanvas\Connectors\Movipass\Services\RoadsideProviderClient;
use Kanvas\Connectors\Movipass\Services\RoadsideProviderStateMapper;
use Kanvas\Connectors\Movipass\Support\RoadsideProviderCaseNumber;
use Kanvas\Exceptions\ValidationException;

/**
 * Mirrors one of our case statuses onto the provider's state vocabulary.
 *
 * The body key is inferred from the endpoint name; confirm it against the provider spec before
 * relying on this in production. Everything that could be grounded in real data is: the value comes
 * from their own state catalog via RoadsideProviderStateMapper, never a hardcoded code list.
 */
class PushRoadsideProviderStateAction
{
    public function __construct(
        private readonly Model $entity,
        private readonly string $internalStatusSlug,
        private readonly array $extra = [],
    ) {
    }

    public function execute(): bool
    {
        $number = RoadsideProviderCaseNumber::for($this->entity);

        if ($number === null) {
            throw new ValidationException(
                'Cannot push a state for an order that has no roadside assistance provider case number',
            );
        }

        $state = new RoadsideProviderStateMapper($this->entity->app)->resolve($this->internalStatusSlug);

        if ($state === null) {
            throw new ValidationException(sprintf(
                'No roadside assistance provider state matches "%s". Add it to the %s configuration map.',
                $this->internalStatusSlug,
                ConfigurationEnum::ROADSIDE_PROVIDER_STATE_MAP->value,
            ));
        }

        // Re-sending a state the provider already has is noise at best and a duplicate audit entry
        // on their side at worst, so the last pushed value gates the call.
        $lastPushed = $this->entity->get(CustomFieldEnum::ROADSIDE_PROVIDER_LAST_PUSHED_STATE->value);

        if ((string) $lastPushed === (string) $state->id) {
            return false;
        }

        new RoadsideProviderClient($this->entity->app)->put(
            RoadsideProviderEndpointEnum::ASSISTANCE_STATE->withCaseNumber($number),
            ['estado' => $state->id, ...$this->extra],
        );

        $this->entity->set(
            CustomFieldEnum::ROADSIDE_PROVIDER_LAST_PUSHED_STATE->value,
            (string) $state->id,
        );

        return true;
    }
}
