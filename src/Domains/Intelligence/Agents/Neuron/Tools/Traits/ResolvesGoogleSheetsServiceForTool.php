<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Google\Service\Sheets as GoogleSheetsService;
use Kanvas\Connectors\GoogleSheets\Client as GoogleSheetsClient;

/**
 * The Google identity a sheets tool acts with: the agent's own connected account when it has one,
 * and null otherwise so the action falls back to the app's service account.
 *
 * Requires HasKanvasContext, which carries the agent running the turn.
 */
trait ResolvesGoogleSheetsServiceForTool
{
    protected function sheetsServiceForAgent(): ?GoogleSheetsService
    {
        $agent = $this->contextAgent();

        return $agent === null ? null : GoogleSheetsClient::forAgent($agent);
    }
}
