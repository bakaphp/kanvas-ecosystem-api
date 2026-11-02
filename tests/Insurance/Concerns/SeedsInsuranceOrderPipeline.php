<?php

declare(strict_types=1);

namespace Tests\Insurance\Concerns;

use Kanvas\Apps\Models\Apps;
use Kanvas\Insurance\Actions\SetupInsuranceOrderPipelineAction;
use Kanvas\Souk\Orders\Models\OrderTypes;

trait SeedsInsuranceOrderPipeline
{
    protected function seedInsuranceOrderPipeline(): OrderTypes
    {
        return new SetupInsuranceOrderPipelineAction(app(Apps::class))->execute();
    }
}
