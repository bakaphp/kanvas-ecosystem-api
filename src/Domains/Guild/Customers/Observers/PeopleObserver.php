<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Observers;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Events\PeopleCompanyUpdateEvent;
use Kanvas\Guild\Customers\Events\PeopleUpdateEvent;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Duplicates\Jobs\CheckPeopleDuplicateJob;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Throwable;

class PeopleObserver
{
    public function created(People $people): void
    {
        /*   $people->fireWorkflow(
              WorkflowEnum::CREATED->value,
              true,
              [
                  'app' => $people->app,
              ]
          ); */

        //$people->clearLightHouseCacheJob();

        try {
            CheckPeopleDuplicateJob::dispatch(
                Apps::getById((int) $people->apps_id),
                $people->getId()
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function updated(People $people): void
    {
        /*    $people->fireWorkflow(
               WorkflowEnum::UPDATED->value,
               true,
               [
                   'app' => $people->app,
               ]
           ); */

        //$people->clearLightHouseCacheJob();
        PeopleUpdateEvent::dispatch($people);
        PeopleCompanyUpdateEvent::dispatch($people);
    }
}
