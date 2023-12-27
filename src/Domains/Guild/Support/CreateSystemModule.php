<?php

declare(strict_types=1);

namespace Kanvas\Guild\Support;

use Baka\Contracts\AppInterface;

use Kanvas\Guild\Agents\Models\Agent;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\SystemModules\Actions\CreateInCurrentAppAction;

class CreateSystemModule
{
    public function __construct(
        protected AppInterface $app
    ) {
    }

    public function run(): void
    {
        $createSystemModule = new CreateInCurrentAppAction($this->app);

        $createSystemModule->execute(Organization::class);
        $createSystemModule->execute(Pipeline::class);
        $createSystemModule->execute(PipelineStage::class);
        $createSystemModule->execute(Lead::class);
        $createSystemModule->execute(People::class);
        $createSystemModule->execute(Agent::class);
        $createSystemModule->execute(Contact::class);
    }
}
