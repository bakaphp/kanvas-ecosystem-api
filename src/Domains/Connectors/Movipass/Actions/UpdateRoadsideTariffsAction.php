<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Enums\ConfigurationEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideServiceTypeEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideZoneEnum;
use Kanvas\Connectors\Movipass\Services\RoadsideAuditTrail;
use Kanvas\Connectors\Movipass\Support\RegionalAssistance;
use Kanvas\Connectors\Movipass\Support\RoadsideTariffSchedule;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Users\Models\Users;

/**
 * Spec v6.0 §10.10: a tariff change carries an effective date, a reason, the previous and new
 * values, and never rewrites history. Each change is appended as a version; quotes pick the version
 * in force when the order was created, so paid orders keep the price they were paid at.
 */
class UpdateRoadsideTariffsAction
{
    public function __construct(
        private readonly AppInterface $app,
        private readonly ?CompanyInterface $company,
        private readonly Users $changedBy,
        private readonly array $tariffs,
        private readonly string $reason,
        private readonly ?CarbonInterface $effectiveFrom = null,
    ) {
    }

    public function execute(): array
    {
        $reason = Str::trimToNull($this->reason);

        if ($reason === null) {
            throw new ValidationException('A reason is required to change the tariffs');
        }

        $effectiveFrom = $this->effectiveFrom ?? Carbon::now();

        if ($effectiveFrom->lessThan(Carbon::now()->subMinute())) {
            throw new ValidationException('A tariff change cannot take effect in the past');
        }

        $new = RoadsideTariffSchedule::fromArray($this->tariffs);
        $this->assertComplete($new);

        $previous = RegionalAssistance::tariffs($this->app, $this->company, $effectiveFrom)->toArray();
        $version = [
            'effective_from' => $effectiveFrom->toIso8601String(),
            'tariffs' => $this->tariffs,
            'reason' => $reason,
            'users_id' => $this->changedBy->getId(),
            'created_at' => Carbon::now()->toIso8601String(),
        ];

        $owner = RegionalAssistance::settingsOwner($this->app, $this->company);
        $owner->set(ConfigurationEnum::ROADSIDE_TARIFF_VERSIONS->value, [
            ...RegionalAssistance::tariffVersions($this->app, $this->company),
            $version,
        ]);

        RoadsideAuditTrail::forTariffs(
            app: $this->app,
            company: $this->company,
            action: 'tariffs_changed',
            actor: $this->changedBy,
            before: $previous,
            after: $new->toArray(),
            reason: $reason,
            details: ['effective_from' => $version['effective_from']],
        );

        return $version;
    }

    /**
     * A sheet missing a fare would only fail when a user asks for that service, so every service and
     * zone is priced once here, before the version is stored.
     */
    private function assertComplete(RoadsideTariffSchedule $schedule): void
    {
        foreach (RoadsideServiceTypeEnum::cases() as $service) {
            foreach (RoadsideZoneEnum::cases() as $zone) {
                if ($schedule->baseFare($service, $zone) < 0) {
                    throw new ValidationException(sprintf('The %s fare for %s cannot be negative', $zone->value, $service->label()));
                }
            }
        }
    }
}
