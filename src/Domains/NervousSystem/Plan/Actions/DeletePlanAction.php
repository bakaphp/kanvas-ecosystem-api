<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\NervousSystem\Plan\Enums\PlanChangeTypeEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;

class DeletePlanAction
{
    public function __construct(
        protected readonly Plan $plan,
    ) {
    }

    public function execute(): bool
    {
        if ((bool) $this->plan->is_deleted) {
            return true;
        }

        DB::connection('intelligence')->transaction(function (): void {
            $this->plan->tasks()->update(['is_deleted' => 1]);
            $this->plan->softDelete();
        });

        foreach ($this->plan->socialChannels as $channel) {
            $channel->delete();
        }

        $this->plan->emitLedgerEvent('plan.deleted', payload: [
            'title' => $this->plan->title,
            'status' => $this->plan->status,
        ]);

        $this->plan->broadcastChange(PlanChangeTypeEnum::DELETED);

        return true;
    }
}
