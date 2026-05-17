<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Dashboard\Models;

use Illuminate\Support\Carbon;
use Kanvas\NervousSystem\Dashboard\Factories\DashboardMetricsDailyFactory;
use Kanvas\NervousSystem\Models\ImmutableBaseModel;
use Override;

/**
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property Carbon $metric_date
 * @property int $plans_completed
 * @property int $mistakes_auto_corrected
 * @property int $mistakes_escalated
 * @property int $agents_active
 * @property float|null $time_recovered_hours
 * @property int|null $value_delivered_cents
 * @property float|null $estimated_human_hours
 * @property Carbon $computed_at
 * @property string $formula_version
 */
class DashboardMetricsDaily extends ImmutableBaseModel
{
    protected $table = 'nervous_system_dashboard_metrics_daily';

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'apps_id' => 'integer',
            'companies_id' => 'integer',
            'metric_date' => 'date',
            'plans_completed' => 'integer',
            'mistakes_auto_corrected' => 'integer',
            'mistakes_escalated' => 'integer',
            'agents_active' => 'integer',
            'time_recovered_hours' => 'float',
            'value_delivered_cents' => 'integer',
            'estimated_human_hours' => 'float',
            'computed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): DashboardMetricsDailyFactory
    {
        return new DashboardMetricsDailyFactory();
    }
}
