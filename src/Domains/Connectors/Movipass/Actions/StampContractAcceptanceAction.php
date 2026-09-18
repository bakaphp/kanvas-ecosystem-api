<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Enums\ParkingApplicationContractSettingEnum as Contract;
use Kanvas\Connectors\Movipass\Enums\ParkingApplicationFieldEnum as Field;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;

/**
 * The acceptance record, in the shape Azul's StampTermsAcceptanceActivity writes for orders:
 * version + server-stamped moment + IP. Nothing runs at receipt by design, so the moment is the
 * receiver's own record of the request (the LeadAttempt), not "now" — the applicant accepted
 * when they submitted, not when a reviewer got to it days later.
 *
 * @return array{version: string, accepted_at: string, ip: string|null}
 */
class StampContractAcceptanceAction
{
    public function __construct(
        protected readonly Lead $application,
    ) {
    }

    public function execute(): array
    {
        if (! (bool) Field::CONTRACT_ACCEPTED->readFrom($this->application)) {
            throw new ValidationException(Field::CONTRACT_ACCEPTED->value . ' must be true to publish the parking');
        }

        $current = Contract::CURRENT_VERSION->readFrom($this->application->app);
        $accepted = (string) (Field::CONTRACT_VERSION->readFrom($this->application) ?: $current);

        if ($accepted === '') {
            throw new ValidationException(Field::CONTRACT_VERSION->value . ' is missing and the app has no current contract version');
        }

        if ($current !== null && $accepted !== $current) {
            throw new ValidationException(
                "contract version {$accepted} was accepted but {$current} is now in force; the owner must accept it again"
            );
        }

        $attempt = $this->application->attempt;
        $acceptedAt = ($attempt?->created_at ?? $this->application->created_at ?? Carbon::now())->toIso8601String();
        $ip = $attempt?->ip;

        Field::CONTRACT_VERSION->writeTo($this->application, $accepted);
        Field::CONTRACT_ACCEPTED_AT->writeTo($this->application, $acceptedAt);
        Field::CONTRACT_ACCEPTANCE_IP->writeTo($this->application, $ip);

        return ['version' => $accepted, 'accepted_at' => $acceptedAt, 'ip' => $ip];
    }
}
