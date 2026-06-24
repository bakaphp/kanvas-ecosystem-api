<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kanvas\Event\Events\Enums\ScheduleTypeEnum;

class SetResourceScheduleAction
{
    public function __construct(
        protected Model $resource,
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected array $days,
        protected ScheduleTypeEnum $scheduleType,
        protected int $slotDurationMin = 60,
        protected ?int $capacityOverride = null,
        protected bool $generateSlots = true,
        protected ?Carbon $startAt = null,
        protected ?Carbon $endAt = null,
    ) {
    }

    public function execute(): array
    {
        return new CreateScheduleRulesFromOperationDaysAction(
            resource: $this->resource,
            app: $this->app,
            company: $this->company,
            operationDays: $this->days,
            slotDurationMinutes: $this->slotDurationMin,
            capacityOverride: $this->capacityOverride,
            frequency: $this->scheduleType === ScheduleTypeEnum::MONTHLY ? 'MONTHLY' : 'WEEKLY',
            scheduleType: $this->scheduleType->value,
            generateSlots: $this->generateSlots,
            startAt: $this->startAt,
            endAt: $this->endAt,
        )->execute();
    }
}
