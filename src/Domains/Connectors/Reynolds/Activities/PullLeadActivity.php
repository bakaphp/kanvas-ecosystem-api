<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Reynolds\Actions\PullLeadAction;
use Kanvas\Connectors\Reynolds\Enums\ConfigurationEnum;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Connectors\Reynolds\Services\XmlParser;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Throwable;

/**
 * Workflow activity that refreshes a Kanvas Lead from a Reynolds Publish Lead
 * Update (LDU) payload.
 *
 * Reynolds is push-only — there is no on-demand GET-prospect endpoint in the
 * SalesAssist specs, so this activity is not a fetch. The actual payload has
 * to be supplied by the caller via $params:
 *
 *   - $params['record']  → already-parsed Record array (preferred — what an
 *                          inbound webhook job would pass after running it
 *                          through XmlParser).
 *   - $params['xml']     → raw SOAP envelope string (we'll parse it ourselves).
 *
 * If neither is provided, the activity is a no-op for that Lead — we report
 * back the current REYNOLDS_PROSPECT_ID (if any) and exit cleanly so the
 * workflow can still be wired to "frontend opened lead" type triggers
 * without breaking on first run before R&R has pushed anything.
 */
#[WorkflowAction]
class PullLeadActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Lead $lead, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        $company = $lead->company;

        if (empty($company->get(ConfigurationEnum::REYNOLDS_DEALER_NUMBER->value))) {
            return ['error' => 'Reynolds dealer not configured for this company'];
        }

        $record = $this->resolveRecord($params);

        if ($record === null) {
            return [
                'message' => 'No Reynolds LDU payload supplied to refresh this lead; nothing to pull',
                'lead_id' => $lead->getId(),
                'prospect_id' => $lead->get(CustomFieldEnum::PROSPECT_ID->value),
            ];
        }

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::REYNOLDS,
            additionalParams: $params,
            integrationOperation: function (Lead $lead, Apps $app, mixed $integrationCompany, array $additionalParams) use ($record): array {
                try {
                    $refreshed = new PullLeadAction(
                        $app,
                        $lead->company,
                        $lead->user
                    )->execute($record);
                } catch (Throwable $e) {
                    return $this->failWorkflow([
                        'error' => 'Reynolds PullLeadAction failed: ' . $e->getMessage(),
                    ]);
                }

                return [
                    'message' => 'Lead refreshed from Reynolds payload',
                    'lead_id' => $refreshed->getId(),
                    'prospect_id' => $refreshed->get(CustomFieldEnum::PROSPECT_ID->value),
                ];
            },
            company: $company,
        );
    }

    /**
     * Accepts either a pre-parsed Record array or a raw SOAP envelope string
     * and returns the Record subnode ready for PullLeadAction.
     */
    private function resolveRecord(array $params): ?array
    {
        if (isset($params['record']) && is_array($params['record'])) {
            return $params['record'];
        }

        if (isset($params['xml']) && is_string($params['xml']) && $params['xml'] !== '') {
            $payload = XmlParser::extractPayloadFromEnvelope($params['xml']);

            $record = $payload['Record'] ?? null;

            return is_array($record) ? $record : null;
        }

        return null;
    }
}
