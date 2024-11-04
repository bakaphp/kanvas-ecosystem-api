<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Importer\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Subscription\Importer\DataTransferObjects\PlanImporter;
use Kanvas\Subscription\Plans\Actions\CreatePlanAction;
use Kanvas\Subscription\Plans\Actions\UpdatePlanAction;
use Kanvas\Subscription\Plans\DataTransferObject\Plan as PlanDto;
use Kanvas\Subscription\Plans\Models\Plan;
use Kanvas\Subscription\Plans\Repositories\PlanRepository;
use Throwable;

class PlanImporterAction
{
    public function __construct(
        public PlanImporter $importedPlan,
        public UserInterface $user,
        public AppInterface $app,
        public bool $runWorkflow = true
    ) {
    }

    public function execute(): Plan
    {
        try {
            DB::connection('mysql')->beginTransaction();

            $planDto = PlanDto::from([
                'app' => $this->app,
                'user' => $this->user,
                'apps_id' => $this->importedPlan->apps_id,
                'name' => $this->importedPlan->name,
                'description' => $this->importedPlan->description,
                'stripe_id' => $this->importedPlan->stripe_id,
                'is_active' => $this->importedPlan->is_active,
            ]);

            try {
                $existingPlan = PlanRepository::getByStripeId($planDto->stripe_id, $this->app);
                $updateAction = new UpdatePlanAction($existingPlan, $planDto);
                $plan = $updateAction->execute();
            } catch (ModelNotFoundException $e) {
                $createAction = new CreatePlanAction($planDto);
                $plan = $createAction->execute();
            }

            DB::connection('mysql')->commit();
        } catch (Throwable $e) {
            DB::connection('mysql')->rollback();

            throw $e;
        }

        return $plan;
    }
}
