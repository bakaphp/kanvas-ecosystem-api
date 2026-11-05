<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Contracts;

use Baka\Contracts\AppInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Regions\Models\Regions as KanvasRegions;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Models\Integrations;

abstract class BaseIntegration
{
    /**
     * $integration is optional so the ~40 existing handlers keep working untouched. It exists for
     * handlers shared by several catalog rows — the MCP handler serves one row per server, and
     * without the row it cannot tell which server it is setting up.
     */
    public function __construct(
        public Apps $app,
        public Companies $company,
        public KanvasRegions $region,
        public array $data,
        public ?Integrations $integration = null
    ) {
    }

    /**
     * setup the connection
     * test the integration connection
     */
    abstract public function setup(): bool;

    /**
     * What stays on the integration_companies row after setup(): the accounts the tenant selected
     * (numbers, a mailbox, a paired session) — whatever later work needs to know which of the
     * integrated account's resources this tenant uses. Never credentials: setup() already puts
     * those in settings, and the row is read far more widely than they are.
     */
    public function persistedConfig(): array
    {
        return [];
    }

    /**
     * Undo what setup() persisted on the company, so a disconnected integration stops resolving
     * credentials. Connector code reads straight from company settings and never checks the
     * integration_companies row, so leaving the keys behind keeps the integration live.
     *
     * App-level settings are shared by every company on the app and are deliberately left alone.
     */
    public function teardown(): void
    {
        foreach ($this->companySettingKeys() as $key) {
            $this->company->del($key);
        }
    }

    /**
     * The row was created or saved again, inside the transaction that wrote it: whatever must exist
     * the moment the integration does is created here, so a failure leaves neither behind.
     */
    public function saved(IntegrationsCompany $integrationCompany): void
    {
    }

    /**
     * The row's switch actually moved, inside the transaction that moved it.
     */
    public function activeChanged(IntegrationsCompany $integrationCompany, bool $active): void
    {
    }

    /**
     * The row is about to be deleted, before teardown() removes the credentials: work that still
     * has to talk to the vendor is scheduled here.
     */
    public function removing(IntegrationsCompany $integrationCompany): void
    {
    }

    /**
     * Every key setup() writes with $this->company->set(). Declare all of them, including the
     * optional ones — del() on a missing key is a no-op.
     *
     * @return list<string>
     */
    protected function companySettingKeys(): array
    {
        return [];
    }

    /**
     * The handler for a connected integration, with no setup payload — enough for the lifecycle
     * hooks, teardown() and defaultRules(). Null when the catalog row names no handler class, or
     * when the row's company or region no longer exists, which old rows do.
     */
    public static function forIntegrationCompany(IntegrationsCompany $integrationCompany, AppInterface $app): ?static
    {
        $handler = $integrationCompany->integration?->handler;

        if (! is_string($handler) || ! is_subclass_of($handler, self::class)) {
            return null;
        }

        if ($integrationCompany->company === null || $integrationCompany->region === null) {
            return null;
        }

        return new $handler(
            app: $app instanceof Apps ? $app : Apps::getById($app->getId()),
            company: $integrationCompany->company,
            region: $integrationCompany->region,
            data: [],
            integration: $integrationCompany->integration,
        );
    }

    /**
     * Rules this integration wants on the company the moment it is connected — which activity
     * answers a `pull` or `push` for which entity. Created by CreateIntegrationCompanyAction,
     * removed on disconnect, so nothing in the resolver has to name a connector.
     *
     * @return list<DefaultRule>
     */
    public function defaultRules(): array
    {
        return [];
    }
}
