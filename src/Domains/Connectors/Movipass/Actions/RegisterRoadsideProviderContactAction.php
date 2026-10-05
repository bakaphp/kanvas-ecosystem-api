<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Movipass\Enums\RoadsideProviderEndpointEnum;
use Kanvas\Connectors\Movipass\Services\RoadsideProviderClient;
use Kanvas\Connectors\Movipass\Support\RoadsideProviderCaseNumber;
use Kanvas\Exceptions\ValidationException;

/**
 * The assignment step: the provider is told which unit and driver took the case.
 *
 * The contact body is passed through from the caller for the same reason as the create payload: the
 * field names are not in the documentation we have, and inventing them would fail silently.
 */
class RegisterRoadsideProviderContactAction
{
    public function __construct(
        private readonly Model $entity,
        private readonly array $contact,
    ) {
    }

    public function execute(): bool
    {
        if ($this->contact === []) {
            throw new ValidationException('Cannot register an empty roadside assistance provider contact');
        }

        $number = RoadsideProviderCaseNumber::for($this->entity);

        if ($number === null) {
            throw new ValidationException(
                'Cannot register a contact for an order that has no roadside assistance provider case number',
            );
        }

        new RoadsideProviderClient($this->entity->app)->put(
            RoadsideProviderEndpointEnum::ASSISTANCE_CONTACT->withCaseNumber($number),
            $this->contact,
        );

        return true;
    }
}
