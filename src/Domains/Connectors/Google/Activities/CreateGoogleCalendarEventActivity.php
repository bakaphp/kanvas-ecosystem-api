<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Google\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Google\Actions\SyncEventToGoogleCalendarAction;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction]
class CreateGoogleCalendarEventActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! $entity instanceof EventVersion && ! $entity instanceof Event) {
            return $this->failWorkflow(['status' => 'error', 'message' => 'This activity only supports EventVersion or Event entities.']);
        }

        $eventVersion = $entity instanceof EventVersion
            ? $entity
            : $entity->versions()->latest('version')->first();

        if ($eventVersion === null) {
            return $this->failWorkflow(['status' => 'error', 'message' => 'Event has no version to synchronize.']);
        }

        if ((int) $eventVersion->apps_id !== $app->getId()) {
            return $this->failWorkflow(['status' => 'error', 'message' => 'Event version does not belong to the workflow app.']);
        }

        return $this->executeIntegration(
            entity: $eventVersion,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: static fn (EventVersion $version): array => (new SyncEventToGoogleCalendarAction($version))->execute(),
            additionalParams: $params,
            company: $eventVersion->company,
            throwException: true,
        );
    }
}
