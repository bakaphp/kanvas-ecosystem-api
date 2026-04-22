<?php

declare(strict_types=1);

namespace Kanvas\Connectors\LicensePlateExtractor\Activities;

use Baka\Contracts\AppInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\LicensePlateExtractor\Actions\ExtractLicensePlateAction;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class ExtractLicensePlateActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Filesystem $filesystem, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $filesystem,
            app: $app,
            integration: IntegrationsEnum::LICENSE_PLATE_EXTRACTOR,
            integrationOperation: fn (Filesystem $filesystem, AppInterface $app) => new ExtractLicensePlateAction(
                filesystem: $filesystem,
                app: $app,
                company: $filesystem->company,
            )->execute(),
            additionalParams: $params,
            company: $filesystem->company,
        );
    }
}
