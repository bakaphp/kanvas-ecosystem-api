<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Contracts;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Regions\Models\Regions as KanvasRegions;
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
     * Every key setup() writes with $this->company->set(). Declare all of them, including the
     * optional ones — del() on a missing key is a no-op.
     *
     * @return list<string>
     */
    protected function companySettingKeys(): array
    {
        return [];
    }
}
