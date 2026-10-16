<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Movipass\Enums\RoadsideProviderEndpointEnum;
use Kanvas\Connectors\Movipass\Services\RoadsideProviderClient;
use Kanvas\Connectors\Movipass\Support\RoadsideProviderCaseNumber;
use Kanvas\Exceptions\ValidationException;

/**
 * Registers the case on the roadside assistance provider and keeps the case number it hands back.
 *
 * The request body is supplied by the caller rather than mapped from the case here: the provider's
 * field names for the create call are not in the documentation we were given, and a guessed mapping
 * would fail silently against a real endpoint. Callers pass the payload straight through (read from
 * `assistance_case.provider_payload`); once the field list is confirmed the mapping belongs in a
 * mapper and this action stays as-is.
 */
class CreateRoadsideProviderCaseAction
{
    public function __construct(
        private readonly Model $entity,
        private readonly array $payload,
    ) {
    }

    public function execute(): string
    {
        if ($this->payload === []) {
            throw new ValidationException('Cannot create a roadside assistance provider case with an empty payload');
        }

        $existing = RoadsideProviderCaseNumber::for($this->entity);

        // A case that already has a number is already registered; re-posting it would orphan the
        // first one on the provider side.
        if ($existing !== null) {
            return $existing;
        }

        $response = new RoadsideProviderClient($this->entity->app)->post(
            RoadsideProviderEndpointEnum::ASSISTANCE->value,
            $this->payload,
        );

        $number = RoadsideProviderCaseNumber::fromResponse($response);

        if ($number === null) {
            throw new ValidationException(
                'The roadside assistance provider accepted the case but returned no case number: '
                . json_encode($response),
            );
        }

        RoadsideProviderCaseNumber::store($this->entity, $number);

        return $number;
    }
}
