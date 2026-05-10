<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Pulse\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;
use Kanvas\NervousSystem\Models\BaseModel;
use Kanvas\NervousSystem\Pulse\Factories\PulseMetricsDailyFactory;

/**
 * Daily snapshot of operational Pulse metrics. One row per (apps_id,
 * companies_id, metric_date). Written by RollupPulseMetricsAction;
 * read by PulseMetricsService for past days.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property Carbon $metric_date
 * @property int $signals_count
 * @property int $understand_count
 * @property int $decide_count
 * @property int $actions_executed
 * @property int $warnings_count
 * @property int $prevented_issues
 * @property float|null $system_confidence_pct
 * @property Carbon $computed_at
 * @property string $formula_version
 */
class PulseMetricsDaily extends BaseModel
{
    use HasFactory;

    protected $table = 'nervous_system_pulse_metrics_daily';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'apps_id' => 'integer',
            'companies_id' => 'integer',
            'metric_date' => 'date',
            'signals_count' => 'integer',
            'understand_count' => 'integer',
            'decide_count' => 'integer',
            'actions_executed' => 'integer',
            'warnings_count' => 'integer',
            'prevented_issues' => 'integer',
            'system_confidence_pct' => 'float',
            'computed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): PulseMetricsDailyFactory
    {
        return new PulseMetricsDailyFactory();
    }
}
