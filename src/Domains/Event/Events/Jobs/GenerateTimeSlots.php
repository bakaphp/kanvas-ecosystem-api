<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Kanvas\Event\Events\Models\ScheduleException;
use Kanvas\Event\Events\Models\ScheduleRules;
use Kanvas\Event\Events\Models\TimeSlots;
use RRule\RRule;

class GenerateTimeSlots implements ShouldQueue {
  public function __construct(
      public int $resourceId,
      public int $ruleId,
      public Carbon $windowFrom,
      public Carbon $windowTo,
  ) {}

  public function handle() {
    $rule       = ScheduleRules::findOrFail($this->ruleId);
    $resource   = $rule->resource;
    $tz         = $resource->tz ?? $resource->app->get('timezone');

    // 1) Expand RRULE in venue TZ
    $occurrences = RRule::create($rule->rrule, [
      'dtstart' => $rule->dt_start->setTimezone($tz),
      'until'   => optional($rule->dt_end)?->setTimezone($tz),
    ])->between($this->windowFrom->clone()->tz($tz), $this->windowTo->clone()->tz($tz));

    // 2) Build intervals [start,end) per occurrence using slot_duration_min
    foreach ($occurrences as $localStart) {
      $localEnd = (clone $localStart)->addMinutes($rule->slot_duration_min);

      // 2.a Skip outside exceptions (blackouts) or include extra_open
      if ($this->isBlackedOut($resource->id, $localStart, $localEnd, $tz)) continue;

      // 3) Compute capacity & price
      $capacity = $rule->capacity_override ?? $resource->default_capacity;
      $price = $resource->variants()->first()?->getPriceInfoFromDefaultChannel()?->price;

      // 4) Upsert for each unit
      foreach ($resource->units as $unit) {
        TimeSlots::upsert([[
          'resource_id'   => $unit->id,
          'start_at'            => $localStart->clone()->setTimezone('UTC'),
          'end_at'              => $localEnd->clone()->setTimezone('UTC'),
          'capacity'            => $capacity,
          'price_snapshot'      => $price,
          'currency'            => 'USD',
          'updated_at'          => now(),
          'created_at'          => now(),
        ]], uniqueBy: ['resource_id', 'resource_type', 'start_at'], update: [
          'end_at','capacity','price_snapshot','currency','updated_at'
        ]);
      }
    }
  }

  protected function isBlackedOut(int $resourceId, Carbon $start, Carbon $end, string $tz): bool {
    $startUtc = $start->clone()->tz('UTC');
    $endUtc   = $end->clone()->tz('UTC');
    return ScheduleException::where('resources_id',$resourceId)
      ->where('kind','blackout')
      ->where('window_start','<',$endUtc)
      ->where('window_end','>',$startUtc)
      ->exists();
    }
}
