<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Insurance\DataTransferObject\InsurancePlan as InsurancePlanData;
use Kanvas\Insurance\Models\InsurancePlan;

class ReplaceInsurancePlanDetailsAction
{
    public function __construct(
        protected readonly InsurancePlan $plan,
        protected readonly InsurancePlanData $data,
    ) {
    }

    public function execute(): InsurancePlan
    {
        return DB::connection('insurance')->transaction(function (): InsurancePlan {
            $this->replace('coverages', $this->data->coverages);
            $this->replace('exclusions', $this->data->exclusions);
            $this->replace('requirements', $this->data->requirements);

            return $this->plan->refresh();
        });
    }

    protected function replace(string $relation, ?array $rows): void
    {
        if ($rows === null) {
            return;
        }

        $this->plan->{$relation}()->get()->each(fn ($existing) => $existing->delete());
        $this->plan->{$relation}()->createMany($rows);
    }
}
