<?php

declare(strict_types=1);

namespace Kanvas\Companies\Onboarding\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Onboarding\Enums\RequirementModeEnum;
use Kanvas\Companies\Onboarding\Enums\RequirementStatusEnum;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Connectors\Mailgun\Services\AgentMailboxService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;

/**
 * The slots the selected items need filled, resolved at company level: a shared slot appears once
 * and is satisfied by whichever item declared it first. Status comes from what the person recorded
 * on the onboarding row; whether an option is connected right now is resolved per option.
 */
class OnboardingRequirementsService
{
    private ?int $activeStatusId = null;

    public function __construct(
        private readonly OnboardingSectionsService $sections = new OnboardingSectionsService(),
    ) {
    }

    /**
     * @param list<array<string, mixed>> $items the state's items (template + area)
     * @return array<string, array<string, mixed>> keyed by requirement slug, in first-declared order
     */
    public function forItems(CompanyOnboarding $row, array $items): array
    {
        $app = $row->app;
        $definitions = $this->sections->requirementDefinitions($app);
        $recorded = (array) $row->dataGet('requirements', []);
        $requirements = [];

        foreach ($items as $item) {
            foreach ((array) ($item['template']['requirements'] ?? []) as $slug) {
                $slug = (string) $slug;
                $definition = $definitions[$slug] ?? null;
                if ($definition === null) {
                    continue;
                }

                $shared = (bool) ($definition['shared'] ?? false);
                if ($shared && isset($requirements[$slug])) {
                    continue;
                }

                $answer = (array) ($recorded[$slug] ?? []);
                $status = RequirementStatusEnum::tryFrom((string) ($answer['status'] ?? '')) ?? RequirementStatusEnum::MISSING;
                // The connector flow runs outside onboarding, so the answer stays pending_connect on
                // the row; the live integration state is what says it finished.
                if ($status === RequirementStatusEnum::PENDING_CONNECT && $this->connected($app, $row, $answer)) {
                    $status = RequirementStatusEnum::CONNECTED;
                }

                $requirements[$slug] = [
                    'slug' => $slug,
                    'label' => (string) ($definition['label'] ?? $slug),
                    'description' => $definition['description'] ?? null,
                    'required' => (bool) ($definition['required'] ?? false),
                    'shared' => $shared,
                    'satisfied_by' => $shared ? (string) ($item['template']['slug'] ?? '') : null,
                    'status' => strtoupper($status->value),
                    'mode' => isset($answer['mode']) ? strtoupper((string) $answer['mode']) : null,
                    'inbound_address' => $this->inboundAddress(
                        $app,
                        $row,
                        $definition,
                        $item,
                    ),
                    'options' => $this->options(
                        $app,
                        $row,
                        $definition,
                        $answer,
                    ),
                    'allow_not_used' => (bool) ($definition['allow_not_used'] ?? false),
                    'allow_not_listed' => (bool) ($definition['allow_not_listed'] ?? false),
                ];
            }
        }

        return $requirements;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $answer
     * @return list<array<string, mixed>>
     */
    protected function options(
        Apps $app,
        CompanyOnboarding $row,
        array $definition,
        array $answer,
    ): array {
        $options = [];
        foreach ((array) ($definition['options'] ?? []) as $option) {
            $integration = ! empty($option['integration_id'])
                ? Integrations::query()->whereKey((int) $option['integration_id'])->first()
                : null;

            $options[] = [
                'label' => (string) ($option['label'] ?? ''),
                'description' => $option['description'] ?? null,
                'mode' => strtoupper((string) ($option['mode'] ?? '')),
                'integration' => $integration,
                'connector' => $option['connector'] ?? null,
                'connected' => $this->connected($app, $row, $option),
            ];
        }

        return $options;
    }

    /**
     * Whether the option is wired for this company right now: an integration by its active
     * `integrations_companies` row, a connector by its own status map. Also fed the recorded answer,
     * which carries the same `integration_id` / `connector` keys as the option it chose.
     *
     * @param array<string, mixed> $option
     */
    protected function connected(Apps $app, CompanyOnboarding $row, array $option): bool
    {
        if (! empty($option['integration_id'])) {
            return IntegrationsCompany::query()
                ->fromApp($app)
                ->fromCompany((int) $row->companies_id)
                ->where('integrations_id', (int) $option['integration_id'])
                ->where('status_id', $this->activeStatusId())
                ->where('is_active', true)
                ->notDeleted()
                ->exists();
        }

        if (! empty($option['connector'])) {
            return ConnectorStatusMap::connected($app, $row->company, (string) $option['connector']);
        }

        return false;
    }

    /**
     * The address a `kanvas_provided` slot hands out is the item's own agent mailbox; null until that
     * agent is provisioned and has one. Onboarding never computes an address itself.
     *
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $item
     */
    protected function inboundAddress(
        Apps $app,
        CompanyOnboarding $row,
        array $definition,
        array $item,
    ): ?string {
        $fromMailbox = array_any(
            (array) ($definition['options'] ?? []),
            fn (array $option): bool => ($option['mode'] ?? null) === RequirementModeEnum::KANVAS_PROVIDED->value
                && ($option['address_from'] ?? null) === 'agent_mailbox',
        );
        if (! $fromMailbox) {
            return null;
        }

        $agentId = (int) $row->dataGet('provisioning.' . ($item['template']['slug'] ?? '') . '.agent_id', 0);
        if ($agentId <= 0) {
            return null;
        }

        $agent = Agent::query()->whereKey($agentId)->fromApp($app)->notDeleted()->first();

        return $agent !== null ? new AgentMailboxService()->addressFor($agent) : null;
    }

    private function activeStatusId(): int
    {
        return $this->activeStatusId ??= Status::getDefaultStatusByName(StatusEnum::ACTIVE->value)->getId();
    }
}
