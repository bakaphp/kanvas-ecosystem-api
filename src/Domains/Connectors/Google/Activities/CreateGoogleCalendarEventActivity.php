<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Google\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Google\Actions\SyncEventToGoogleCalendarAction;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction]
class CreateGoogleCalendarEventActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! $entity instanceof Event) {
            return $this->failWorkflow(['status' => 'error', 'message' => 'This activity only supports Event entities.']);
        }

        $event = $entity;
        if ($event->apps_id !== $app->getId()) {
            return $this->failWorkflow(['status' => 'error', 'message' => 'Event does not belong to the workflow app.']);
        }

        return new SyncEventToGoogleCalendarAction($event)->execute();
    }
}
