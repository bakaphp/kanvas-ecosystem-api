<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Dashboard\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Kanvas\NervousSystem\Dashboard\Models\DashboardMetricsDaily;
use Override;

class DashboardMetricsDailyFactory extends Factory
{
    protected $model = DashboardMetricsDaily::class;

    #[Override]
    public function definition(): array
    {
        return [
            'apps_id' => 1,
            'companies_id' => 1,
            'metric_date' => Carbon::yesterday()->toDateString(),
            'plans_completed' => $this->faker->numberBetween(0, 50),
            'mistakes_auto_corrected' => $this->faker->numberBetween(0, 5),
            'mistakes_escalated' => $this->faker->numberBetween(0, 3),
            'agents_active' => $this->faker->numberBetween(0, 12),
            'time_recovered_hours' => null,
            'value_delivered_cents' => null,
            'estimated_human_hours' => null,
            'computed_at' => Carbon::now(),
            'formula_version' => '1.0',
        ];
    }
}
