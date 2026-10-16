<?php

declare(strict_types=1);

namespace Kanvas\Analytics\Reporting\Contracts;

use Baka\Contracts\AppInterface;

/**
 * A connector's answer to "which report models does this app have?"
 *
 * The registry does not know about connectors; it asks each provider. That is what keeps the
 * Analytics domain free of Intras-specific names and lets a second connector add reporting
 * without editing shared code.
 *
 * A provider decides enablement from whatever it already uses to know it is configured — for
 * Intras that is the connector's own DB settings, the same ones `Intras\Client` reads. An app
 * without them gets no definitions, so a model it never enabled is not discoverable, let alone
 * queryable.
 */
interface ReportDefinitionProviderInterface
{
    /**
     * @return array<int, ReportDefinitionInterface> empty when this connector is not enabled
     */
    public function definitionsFor(AppInterface $app): array;
}
