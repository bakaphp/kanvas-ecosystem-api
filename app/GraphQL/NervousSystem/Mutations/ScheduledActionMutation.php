<?php

declare(strict_types=1);

namespace App\GraphQL\NervousSystem\Mutations;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\NervousSystem\Scheduling\Actions\CancelScheduledActionAction;
use Kanvas\NervousSystem\Scheduling\Actions\PauseScheduledActionAction;
use Kanvas\NervousSystem\Scheduling\Actions\ResumeScheduledActionAction;
use Kanvas\NervousSystem\Scheduling\Models\ScheduledAction;

class ScheduledActionMutation
{
    use ResolvesActingContext;

    public function pause(mixed $rootValue, array $request): ScheduledAction
    {
        return new PauseScheduledActionAction($this->scheduledAction($request))->execute();
    }

    public function resume(mixed $rootValue, array $request): ScheduledAction
    {
        return new ResumeScheduledActionAction($this->scheduledAction($request))->execute();
    }

    public function cancel(mixed $rootValue, array $request): ScheduledAction
    {
        return new CancelScheduledActionAction($this->scheduledAction($request))->execute();
    }

    private function scheduledAction(array $request): ScheduledAction
    {
        $ctx = $this->actingContext();

        /** @var ScheduledAction $action */
        $action = ScheduledAction::getByIdFromCompanyApp(
            (int) $request['id'],
            $ctx->company,
            $ctx->app
        );

        return $action;
    }
}
