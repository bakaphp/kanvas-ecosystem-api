<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Rules\Actions\RemoveIntegrationDefaultRulesAction;

final class RemoveIntegrationCompanyAction
{
    /**
     * A company can live in several apps, so the app has to come from the caller — and it has to be
     * the row's own, or one app could disconnect another app's integration.
     */
    public function __construct(
        private readonly IntegrationsCompany $integrationCompany,
        private readonly Apps $app
    ) {
    }

    public function execute(): bool
    {
        if (! $this->integrationCompany->isManageableFrom($this->app)) {
            throw new ModelNotFoundException('Integration not found for this app.');
        }

        new RemoveIntegrationDefaultRulesAction($this->integrationCompany, $this->app)->execute();

        $handler = BaseIntegration::forIntegrationCompany($this->integrationCompany, $this->app);
        $handler?->removing($this->integrationCompany);
        $handler?->teardown();

        return (bool) $this->integrationCompany->delete();
    }
}
