<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Jobs;

use Baka\Support\Str;
use Kanvas\Guild\Leads\Actions\ConvertJsonTemplateToLeadStructureAction;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Override;

/**
 * One endpoint, several lead receivers. The first `configuration.routes` entry matching the payload as
 * sent (before any receiver template) picks the receiver; no match uses `receiver_id`.
 *
 * "routes": [{ "field": "Other.Loan_Type", "equals" | "contains": "real estate", "receiver_id": 200 }]
 */
#[WorkflowAction]
class CreateLeadsFromRoutedReceiverJob extends CreateLeadsFromReceiverJob
{
    #[Override]
    protected function loadReceiver(): LeadReceiver
    {
        return LeadReceiver::getByIdFromCompanyApp(
            $this->routedReceiverId() ?? $this->receiver->configuration['receiver_id'],
            $this->receiver->company,
            $this->receiver->app,
        );
    }

    private function routedReceiverId(): ?int
    {
        $routes = $this->receiver->configuration['routes'] ?? [];
        if (! is_array($routes) || $routes === []) {
            return null;
        }

        $payload = $this->rawPayload();
        $reader = new ConvertJsonTemplateToLeadStructureAction([], $payload);

        foreach ($routes as $route) {
            if (! is_array($route) || empty($route['field']) || empty($route['receiver_id'])) {
                continue;
            }

            $value = $reader->getValueFromPath($payload, (string) $route['field']);

            if ($this->routeMatches($route, $value)) {
                return (int) $route['receiver_id'];
            }
        }

        return null;
    }

    private function routeMatches(array $route, string $value): bool
    {
        $value = Str::lowerTrim($value);
        $equals = Str::lowerTrim((string) ($route['equals'] ?? ''));
        $contains = Str::lowerTrim((string) ($route['contains'] ?? ''));

        if ($equals !== '') {
            return $value === $equals;
        }

        return $contains !== '' && str_contains($value, $contains);
    }
}
