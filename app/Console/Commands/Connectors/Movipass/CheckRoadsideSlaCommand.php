<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Movipass;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Concerns\InteractsWithAssistanceCase;
use Kanvas\Connectors\Movipass\Enums\RoadsideCaseStateEnum;
use Kanvas\Connectors\Movipass\Services\RoadsideAuditTrail;
use Kanvas\Connectors\Movipass\Support\RegionalAssistance;
use Kanvas\Connectors\Movipass\Support\RoadsideSlaCalculator;
use Kanvas\Souk\Orders\Models\Order;

/**
 * Spec v6.0 §14: flags every open Asistencia Regional case whose current stage ran past its
 * threshold. Each metric is flagged once per case, recorded on the case for the cabin's alert list
 * and in the audit trail. Meant to run every minute from the scheduler.
 */
class CheckRoadsideSlaCommand extends Command
{
    use InteractsWithAssistanceCase;
    use KanvasJobsTrait;

    protected $signature = 'kanvas:movipass-roadside-sla-check {app_id}';

    protected $description = 'Flag open Asistencia Regional roadside cases that breached a stage SLA';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $terminal = array_map(
            static fn (RoadsideCaseStateEnum $state) => $state->value,
            array_filter(RoadsideCaseStateEnum::cases(), static fn (RoadsideCaseStateEnum $state) => $state->isTerminal()),
        );
        $flagged = 0;

        Order::fromApp($app)
            ->notDeleted()
            ->whereNotNull('metadata->assistance_case->state')
            ->whereNotIn('metadata->assistance_case->state', array_values($terminal))
            ->chunkById(200, function ($orders) use (&$flagged) {
                foreach ($orders as $order) {
                    if (RegionalAssistance::isEnabledFor($order)) {
                        $flagged += $this->flagBreaches($order);
                    }
                }
            });

        $this->info('Flagged ' . $flagged . ' SLA breaches for app ' . $app->getId() . '.');

        return self::SUCCESS;
    }

    private function flagBreaches(Order $order): int
    {
        $assistanceCase = $this->assistanceCaseFrom($order);
        $alerts = (array) ($assistanceCase['sla_alerts'] ?? []);
        $new = 0;

        foreach (RoadsideSlaCalculator::forCase($assistanceCase, RegionalAssistance::slaThresholds($order)) as $metric) {
            if (! $metric['running'] || ! $metric['breached'] || isset($alerts[$metric['metric']])) {
                continue;
            }

            $alerts[$metric['metric']] = Carbon::now()->toISOString();
            $new++;

            RoadsideAuditTrail::forOrder(
                order: $order,
                module: 'order',
                action: 'sla_breached',
                after: $metric,
                succeeded: false,
            );
        }

        if ($new > 0) {
            $assistanceCase['sla_alerts'] = $alerts;
            $this->saveAssistanceCase($order, $assistanceCase);
        }

        return $new;
    }
}
