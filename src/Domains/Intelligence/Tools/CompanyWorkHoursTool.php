<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Tools;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Contracts\ContextToolInterface;
use Override;

class CompanyWorkHoursTool implements ContextToolInterface
{
    protected $currentDay;

    public function __construct(
        protected Model $entity
    ) {
        $this->currentDay = Carbon::now();
        $this->currentDay->setTimezone($this->entity->app->get('timezone') ?? 'UTC');
    }

    #[Override]
    public function execute(array $params = []): array
    {
        $workingHours = $this->entity->company->get(ConfigurationEnum::WORKING_HOURS->value);

        return [
            'status' => $this->getStatus(),
            'weekday' => Carbon::now()->dayName,
            'opens_at_local' => $workingHours['opens_at_local'] ?? '',
            'closes_at_local' => $workingHours['closes_at_local'] ?? '',
            'next_open_iso' => $this->getNextWorkingDay(),
        ];
        // return [
        //     'work_hours' => $this->entity->company->get(ConfigurationEnum::WORKING_HOURS->value) ?? null,
        //     'working_days' => $this->entity->company->get(ConfigurationEnum::WORKING_DAYS->value) ?? null,
        // ];
    }

    protected function getStatus(): string
    {
        $workingDays = $this->entity->company->get(ConfigurationEnum::WORKING_DAYS->value);
        $workingHours = $this->entity->company->get(ConfigurationEnum::WORKING_HOURS->value);
        if (in_array($this->currentDay->format('l'), $workingDays)) {
            $opensAt = Carbon::createFromFormat('H:i:s', $workingHours['opens_at_local'] ?? '00:00:00')->setTimezone($this->currentDay->timezone);
            $closesAt = Carbon::createFromFormat('H:i:s', $workingHours['closes_at_local'] ?? '23:59:59')->setTimezone($this->currentDay->timezone);
            if ($this->currentDay->between($opensAt, $closesAt)) {
                return 'before_hours';
            }

            return 'after_hours';
        }

        return 'after_hours';
    }

    protected function getNextWorkingDay(): string
    {
        $current = $this->currentDay ?? Carbon::now();
        $workingDays = $this->entity->company->get(ConfigurationEnum::WORKING_DAYS->value);
        $nextDay = $current->copy();

        do {
            $nextDay->addDay();

            $workingHours = $this->entity->company->get(ConfigurationEnum::WORKING_HOURS->value);

            [$hour, $minute] = explode(':', $workingHours['opens_at_local'] ?? '09:00');
            $nextDay->setTime((int)$hour, (int)$minute);
        } while (! in_array(strtolower($nextDay->dayName), $workingDays));

        return $nextDay->toIso8601String();
    }
}
