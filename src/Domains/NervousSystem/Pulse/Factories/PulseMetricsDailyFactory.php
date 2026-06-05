<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Pulse\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Kanvas\NervousSystem\Pulse\Models\PulseMetricsDaily;
use Override;

class PulseMetricsDailyFactory extends Factory
{
    protected $model = PulseMetricsDaily::class;

    #[Override]
    public function definition(): array
    {
        return [
            'apps_id' => 1,
            'companies_id' => 1,
            'metric_date' => Carbon::yesterday()->toDateString(),
            'signals_count' => $this->faker->numberBetween(0, 500),
            'understand_count' => $this->faker->numberBetween(0, 100),
            'decide_count' => $this->faker->numberBetween(0, 50),
            'actions_executed' => $this->faker->numberBetween(0, 200),
            'warnings_count' => $this->faker->numberBetween(0, 10),
            'prevented_issues' => $this->faker->numberBetween(0, 5),
            'system_confidence_pct' => null,
            'computed_at' => Carbon::now(),
            'formula_version' => '1.0',
        ];
    }
}
