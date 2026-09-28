<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Actions;

use Kanvas\Connectors\SalesAssist\Exceptions\FirstMessageDisabledException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Services\LeadConfigurationService;

final class EnsureFirstMessageEnabledAction
{
    public function __construct(
        private readonly Lead $lead,
    ) {
    }

    /**
     * @return array{status: string, lead_id: int, config_key: string, configured: bool}
     */
    public function execute(): array
    {
        $leadTypeConfig = $this->lead->type()->first()?->config ?? [];
        $configKey = new LeadConfigurationService()->getFirstMessageDefaultKey($this->lead);

        if (array_key_exists($configKey, $leadTypeConfig) && ! (bool) $leadTypeConfig[$configKey]) {
            throw new FirstMessageDisabledException(
                "First message disabled by lead type configuration [{$configKey}]"
            );
        }

        return [
            'status' => 'eligible',
            'lead_id' => $this->lead->getId(),
            'config_key' => $configKey,
            'configured' => array_key_exists($configKey, $leadTypeConfig),
        ];
    }
}
