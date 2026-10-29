<?php

declare(strict_types=1);

namespace Kanvas\Companies\Onboarding\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Setup\Enums\DefaultAgentEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Onboarding\Enums\ProvisionStatusEnum;
use Kanvas\Companies\Onboarding\Services\OnboardingAgentTemplateService;
use Kanvas\Connectors\Mailgun\Jobs\ProvisionAgentMailboxJob;
use Kanvas\Connectors\Mailgun\Services\AgentMailboxService;
use Kanvas\Intelligence\Agents\Actions\CloneAgentForCompanyAction;
use Kanvas\Intelligence\Agents\Actions\CreateAgentAction;
use Kanvas\Intelligence\Agents\Actions\ProvisionAgentUserAction;
use Kanvas\Intelligence\Agents\DataTransferObject\Agent as AgentData;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * One catalog item for one company. A module is ready by being listed; an agent is the company's
 * own copy of the template or, without one, a fresh agent of the catalog's type, reused when the
 * company already has an agent of that name so a second run creates nothing. A failure is the
 * item's, never the caller's: it comes back as a result.
 */
final class ProvisionOnboardingItemAction
{
    /**
     * @param array<string, mixed> $item one SectionConfig agent or module entry
     */
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly Users $owner,
        private readonly array $item,
    ) {
    }

    /**
     * @return array{status: string, agent_id: ?int, error: ?string}
     */
    public function execute(): array
    {
        if (($this->item['kind'] ?? 'agent') === 'module') {
            return $this->result(ProvisionStatusEnum::READY);
        }

        try {
            return $this->provisionAgent();
        } catch (Throwable $e) {
            report($e);

            return $this->result(ProvisionStatusEnum::FAILED, error: $e->getMessage());
        }
    }

    /**
     * @return array{status: string, agent_id: ?int, error: ?string}
     */
    private function provisionAgent(): array
    {
        // The copy carries the template's name, so that is the name a previous run left behind.
        $sourceName = trim((string) ($this->item['source_agent'] ?? ''));
        $existing = DefaultAgentEnum::named($this->app, $this->company, $sourceName);
        if ($existing !== null) {
            return $this->result(ProvisionStatusEnum::READY, $existing, $this->provisionMailbox($existing));
        }

        $templates = new OnboardingAgentTemplateService();
        $template = $templates->resolve($this->app, $sourceName);
        if ($template !== null) {
            $agent = new CloneAgentForCompanyAction(
                source: $template,
                app: $this->app,
                company: $this->company,
                owner: $this->owner,
                user: $this->agentUser($template->name),
            )->execute();

            return $this->result(ProvisionStatusEnum::READY, $agent, $this->provisionMailbox($agent));
        }

        $handler = (string) ($this->item['agent_type'] ?? '');
        $type = $templates->resolveType($this->app, $handler);
        if ($type === null) {
            return $this->result(
                ProvisionStatusEnum::FAILED,
                error: sprintf(
                    'No template agent "%s" for app %d or app 0, and no agent type with handler "%s"',
                    $sourceName,
                    $this->app->getId(),
                    $handler,
                ),
            );
        }

        $agent = $this->createFromType($type, $sourceName);

        return $this->result(ProvisionStatusEnum::READY, $agent, $this->provisionMailbox($agent));
    }

    private function createFromType(AgentType $type, string $name): Agent
    {
        return new CreateAgentAction(
            new AgentData(
                app: $this->app,
                company: $this->company,
                user: $this->agentUser($name),
                agentType: $type,
                name: $name,
                role: $type->role ?? [],
                is_active: true,
                description: $type->description,
                soul: $type->soul,
                instructions: $type->instructions,
                outputFormat: $type->output_format,
                createdBy: $this->owner,
            ),
        )->execute();
    }

    private function agentUser(string $name): Users
    {
        return new ProvisionAgentUserAction($this->app, $this->company, $name)->execute();
    }

    /**
     * Only for items flagged in the catalog, and only where Mailgun is set up for the tenant; the
     * job re-checks on its own and provisions once. The agent exists either way, so a mailbox
     * failure is noted on the item instead of failing it.
     */
    private function provisionMailbox(Agent $agent): ?string
    {
        if (empty($this->item['provision_mailbox'])) {
            return null;
        }

        try {
            $mailboxes = app(AgentMailboxService::class);
            if (! $mailboxes->hasMailbox($agent) && $mailboxes->isConfiguredFor($agent)) {
                ProvisionAgentMailboxJob::dispatch($agent);
            }
        } catch (Throwable $e) {
            report($e);

            return 'Mailbox not provisioned: ' . $e->getMessage();
        }

        return null;
    }

    /**
     * @return array{status: string, agent_id: ?int, error: ?string}
     */
    private function result(ProvisionStatusEnum $status, ?Agent $agent = null, ?string $error = null): array
    {
        return [
            'status' => $status->value,
            'agent_id' => $agent?->getId(),
            'error' => $error,
        ];
    }
}
