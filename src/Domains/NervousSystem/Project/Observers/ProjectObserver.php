<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Observers;

use Kanvas\NervousSystem\Project\Actions\BindProjectChannelAction;
use Kanvas\NervousSystem\Project\Actions\ProvisionProjectWebhookAction;
use Kanvas\NervousSystem\Project\Models\Project;

class ProjectObserver
{
    public function updating(Project $project): void
    {
        $project->clearLightHouseCache(withKanvasConfiguration: false);
    }

    public function created(Project $project): void
    {
        new BindProjectChannelAction($project)->execute();
        new ProvisionProjectWebhookAction($project)->execute();
    }
}
