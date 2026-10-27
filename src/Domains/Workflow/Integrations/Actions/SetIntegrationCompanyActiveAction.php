<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;

/**
 * The handler hears about the switch only when it actually moves: switching on what is already on
 * must not release work that was held while it was off.
 */
final class SetIntegrationCompanyActiveAction
{
    public function __construct(
        private readonly IntegrationsCompany $integrationCompany,
        private readonly Apps $app,
        private readonly bool $active
    ) {
    }

    public function execute(): bool
    {
        DB::connection($this->integrationCompany->getConnectionName())->transaction(function (): void {
            $changed = IntegrationsCompany::query()
                ->whereKey($this->integrationCompany->getKey())
                ->where('is_active', ! $this->active)
                ->update(['is_active' => $this->active]) === 1;

            if ($changed) {
                BaseIntegration::forIntegrationCompany($this->integrationCompany, $this->app)
                    ?->activeChanged($this->integrationCompany, $this->active);
            }
        });

        $this->integrationCompany->refresh();

        return (bool) $this->integrationCompany->is_active;
    }
}
