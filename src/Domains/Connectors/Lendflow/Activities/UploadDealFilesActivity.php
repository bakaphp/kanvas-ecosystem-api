<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Lendflow\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Lendflow\Actions\UploadDealFilesAction;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction]
class UploadDealFilesActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Deal $deal, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $deal,
            app: $app,
            integration: IntegrationsEnum::LENDFLOW,
            additionalParams: $params,
            integrationOperation: function (Deal $deal) {
                return new UploadDealFilesAction($deal)->execute();
            },
            company: $deal->company,
        );
    }
}
